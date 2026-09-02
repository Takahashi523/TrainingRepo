<?php

namespace App\Services\Csv;

use App\Models\User;
use App\Support\Csv\CsvFile;
use App\Support\Csv\CsvInjection;
use App\Support\Csv\CsvSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CSV インポートの中核。人材・案件で共通のロジックを持ち、列の違いは CsvSchema に委譲する。
 *
 * 処理順序（api/08・§8 の確定に厳密に従う）：
 *   1. BOM ストリップ → RFC4180 パース（空行は論理レコード単位でスキップ／CsvFile）
 *   2. ヘッダー検証（名前ベース照合・必須列欠落は全体中断・未知列は無視・列順入替は許容／O-7）
 *   3. データ0行チェック（O-9）
 *   4. 参照データ preload（users.id・既存 id を各1回／N+1回避・O-13）
 *   5. 行ごと：レイアウト（列数）先行 → 復元（CsvInjection）→ 正規化 → 項目検証（bail なし・全メッセージ収集）
 *              → 担当者/更新 id の存在照合（メモリ）→ version 照合（楽観ロック・issue #45）→ ファイル内 id 重複（O-8）
 *   6. エラーが1件でもあれば書き込まず 422（errors.importErrors に構造化 JSON）
 *   7. エラー0件なら DB::transaction 内で version 再照合（下記）→ upsert によるバッチ INSERT / UPDATE
 *      （全項目上書き＋version 更新＝O-1。新規行は version=0、更新行は version+1）
 *
 * 設計原則：SRP（1クラス＝インポートの1責務）／DRY（列定義は CsvSchema・書式ルールは *Rules に集約）／
 * フェイルセーフ（全エラー収集後に一括ロールバック）。
 *
 * **楽観ロック（version・issue #45）**：更新行（id 指定）は version 列必須。preload 済みの現在値と
 * 照合し、不一致なら当該行を「バージョンが一致しません」エラーとする（他の行エラーと同じ422・
 * 全行ロールバック）。一致した行のみ write() で version+1 して書き込む。新規行（id 空）は
 * version 列を無視し、常に 0（DBのdefaultと同値）で作成する。画面編集（PUT/PATCH）側の
 * `version` チェックと同じ「読んだ版を照合してから+1」方式に揃えることで、CSVインポートと
 * 画面編集のどちらが先に更新しても、後から保存しようとした側が確実に競合として検知される。
 *
 * **version 照合のタイミングと再照合（issue #45・2026-09-02 修正／レビュー指摘）**：上記の照合は
 * preload 時点の値を見ているだけで、画面編集4経路（lockForUpdate() ロック内で照合〜更新まで
 * 1トランザクションに閉じる）と異なり、preload〜実際の書き込み（write()）までの間に競合が
 * 割り込む余地が元々あった（最大5,000行を許容する仕様のため、この間隔はゼロではない）。
 * これを塞ぐため、write() は実際に書き込む直前・同一トランザクション内でも対象行を
 * lockForUpdate() で再照合する（{@see write()}・{@see guardVersionsUnchangedSincePreload()}）。
 * これにより「ロック→照合→更新」が1トランザクションに閉じる形になり、画面編集4経路と
 * 同じ強度の保証になる。
 */
class CsvImportService
{
    /**
     * インポートを実行する。成功時は ['resource' => ..., 'summary' => [...]] を返す。
     * ヘッダー/行/構造エラーがあれば ValidationException（errors.importErrors）を投げる（部分反映なし）。
     *
     * @return array{resource: string, summary: array{total_rows: int, created: int, updated: int}}
     *
     * @throws ValidationException
     */
    public function import(UploadedFile $file, CsvSchema $schema): array
    {
        $content = CsvFile::stripBom((string) file_get_contents($file->getRealPath()));
        $records = CsvFile::readRecords($content); // [オフセット => セル配列]（0=ヘッダー・空行はスキップ済み）

        // ---- ヘッダー検証（O-7）----
        // 欠落・空・重複・未知の4種を検出し、あれば1エントリ（row=1）でまとめて中断する。
        // 未知列を無視せずエラーにするのは、末尾カンマ由来の空列や誤って足した列が「全データ行の列数不一致」に化けて
        // 原因が分かりにくくなるのを防ぐため。エクスポート専用列（AI要約・担当者名）は exportHeaders に含むので許容される。
        $header = array_map(fn ($h) => trim((string) $h), $records[0] ?? []);
        $headerErrors = [];

        // ① 必須列（全 importable 列）の欠落
        $missing = array_values(array_diff($schema->requiredHeaders(), $header));
        if ($missing !== []) {
            // 列名は重複・不明の他メッセージと表記を揃えて「」で囲む。
            $labels = array_map(fn ($h) => "「{$h}」", $missing);
            $headerErrors[] = '必要な列がありません：'.implode('、', $labels).'。不足している列を追加してください。';
        }

        // ② 空ヘッダー（末尾カンマ等）。原因特定のため1始まりの列位置で示す。
        $emptyPositions = [];
        foreach ($header as $i => $name) {
            if ($name === '') {
                $emptyPositions[] = ($i + 1).'列目';
            }
        }
        if ($emptyPositions !== []) {
            $headerErrors[] = 'ヘッダーに空の列があります（'.implode('、', $emptyPositions).'）。末尾の余分なカンマなどを確認してください。';
        }

        // ③ 重複ヘッダー（空は②で扱うため除外）。同名列があると値の対応が曖昧になるため弾く。
        $namedHeaders = array_filter($header, fn ($name) => $name !== '');
        $duplicates = array_keys(array_filter(array_count_values($namedHeaders), fn ($count) => $count > 1));
        if ($duplicates !== []) {
            $labels = array_map(fn ($h) => "「{$h}」", $duplicates);
            $headerErrors[] = 'ヘッダーに重複した列があります：'.implode('、', $labels).'。重複した列を1つにしてください。';
        }

        // ④ 未知の列（既知の列名以外・空は②で扱うため除外）。既知＝importable＋エクスポート専用列。
        $unknown = array_values(array_diff(array_unique($namedHeaders), $schema->exportHeaders()));
        if ($unknown !== []) {
            $labels = array_map(fn ($h) => "「{$h}」", $unknown);
            $headerErrors[] = '不明な列があります：'.implode('、', $labels).'。エクスポートしたCSVの列構成のままにしてください。';
        }

        if ($headerErrors !== []) {
            $this->fail([[
                'row' => 1,
                'field' => null,
                'messages' => $headerErrors,
            ]]);
        }

        $headerIndex = [];
        foreach ($header as $i => $name) {
            $headerIndex[$name] ??= $i; // 最初の出現を採用
        }
        $headerCount = count($header);
        $importableMap = $schema->importableHeaderMap(); // header => field（id 含む）

        // ---- データ行（O-9）----
        $dataRows = $records;
        unset($dataRows[0]);
        if ($dataRows === []) {
            $this->fail([[
                'row' => null,
                'field' => null,
                'messages' => ['インポートする対象データがありません。データ行を1行以上入力してください。'],
            ]]);
        }
        if (count($dataRows) > CsvFile::MAX_DATA_ROWS) {
            // 通常は CsvImportRequest で弾かれるが、Service 単体利用時の保険
            $this->fail([[
                'row' => null,
                'field' => null,
                'messages' => ['一度にインポートできるのは'.number_format(CsvFile::MAX_DATA_ROWS).'行までです。ファイルを分割してインポートし直してください。'],
            ]]);
        }

        // ---- 参照データ preload（N+1回避・O-13）----
        $userIdSet = array_flip(User::query()->pluck('id')->all());

        // ---- pass1：レイアウト検証 + 行データ構築 + id 収集 ----
        $errors = [];
        $parsed = [];
        $idRowNumbers = [];
        foreach ($dataRows as $offset => $cells) {
            $rowNum = $offset + 1; // ヘッダーを1行目とする1オリジン（論理行・空行スキップでも繰り上げない）

            if (count($cells) !== $headerCount) {
                $errors[] = [
                    'row' => $rowNum,
                    'field' => null,
                    'messages' => ['列数がヘッダーと一致しません（この行'.count($cells).'列 / ヘッダー'.$headerCount.'列）。列数をヘッダーと揃えてください。'],
                ];

                continue; // 列数不正の行は項目検証をスキップ
            }

            $assoc = [];
            foreach ($importableMap as $headerName => $field) {
                $idx = $headerIndex[$headerName] ?? null;
                $raw = ($idx !== null && array_key_exists($idx, $cells)) ? $cells[$idx] : null;
                if ($raw !== null) {
                    $raw = CsvInjection::restore(trim((string) $raw));
                }
                $assoc[$field] = ($raw === null || $raw === '') ? null : $raw;
            }
            $assoc = $schema->normalizeRow($assoc);

            $id = $assoc['id'] ?? null;
            // 重複判定は正規化キーで行う。数値かつ整数値（"1"/"01"/"1.0"）のみ (int) 化した文字列をキーにし、
            // 同一レコードを指す表記ゆれを1つのIDとして束ねる（文字列そのままをキーにすると別IDと誤認し、
            // 同一行への二重 UPDATE をすり抜けてしまう）。"1.5" のような小数は整数値でないため raw を維持し、
            // id=1 の行と誤って同一視しない（この後の存在チェックで形式エラーとして弾く）。
            $isIntegerId = $id !== null && is_numeric($id) && (float) $id == (int) $id;
            $dupKey = $id === null ? null : ($isIntegerId ? (string) (int) $id : $id);
            if ($dupKey !== null) {
                $idRowNumbers[$dupKey][] = $rowNum;
            }

            $parsed[] = ['row' => $rowNum, 'id' => $id, 'dupKey' => $dupKey, 'assoc' => $assoc];
        }

        // 既存 id 集合を1回だけロード（更新対象・非空かつ数値のみ）。
        // version（楽観ロック・issue #45）の照合にも使うため、id だけでなく version も同じ1クエリで
        // 取得する（N+1回避。id => version の連想配列。存在確認は isset() で兼ねる）。
        $idsInFile = array_values(array_unique(array_filter(
            array_map(fn ($p) => $p['id'], $parsed),
            fn ($v) => $v !== null && is_numeric($v),
        )));
        $existingVersions = $idsInFile !== []
            ? $schema->modelClass()::query()->whereIn('id', $idsInFile)->pluck('version', 'id')->all()
            : [];

        $duplicateIds = array_flip(array_keys(array_filter(
            $idRowNumbers,
            fn ($rows) => count($rows) > 1,
        )));

        // ---- pass2：項目・存在・重複 検証 + 書き込み候補の確定 ----
        $writable = [];
        foreach ($parsed as $p) {
            ['row' => $rowNum, 'id' => $id, 'dupKey' => $dupKey, 'assoc' => $assoc] = $p;
            $rowHasError = false;

            // 項目バリデーション（id は対象外・exists はメモリ照合のため付与しない・bail なしで全メッセージ収集）
            $validator = Validator::make($assoc, $schema->importRules($assoc), $schema->importMessages(), $schema->attributes());
            if ($validator->fails()) {
                $rowHasError = true;
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors[] = ['row' => $rowNum, 'field' => $field, 'messages' => array_values($messages)];
                }
            }

            // 担当者 ID の存在（preload 済み集合とメモリ照合＝N+1回避）
            foreach (['main_user_id' => '主担当ID', 'sub_user_id' => 'サブ担当ID'] as $field => $label) {
                $value = $assoc[$field] ?? null;
                if ($value !== null && is_numeric($value) && ! isset($userIdSet[(int) $value])) {
                    $rowHasError = true;
                    $errors[] = ['row' => $rowNum, 'field' => $field, 'messages' => ["指定された{$label}のユーザーが存在しません。「担当者ID一覧」で有効なIDを確認してください。"]];
                }
            }

            // ファイル内 id 重複（構造系エラー・field:null／O-8）。正規化キーで判定する。
            if ($dupKey !== null && isset($duplicateIds[$dupKey])) {
                $rowHasError = true;
                $errors[] = ['row' => $rowNum, 'field' => null, 'messages' => ['同一IDが複数行にあります。同じIDの行は1つにまとめてください。']];
            }

            // 更新対象 id の存在。小数（"1.5" 等）は (int) 化で別レコード（id=1）を指してしまうため、
            // 整数値でない id はまず形式エラーとして弾き、サイレントな取り違え更新を防ぐ。
            if ($id !== null) {
                if (! is_numeric($id) || (float) $id != (int) $id) {
                    $rowHasError = true;
                    $errors[] = ['row' => $rowNum, 'field' => 'id', 'messages' => ["ID「{$id}」が正しくありません。IDは整数で入力してください（新規追加はID列を空欄にしてください）。"]];
                } elseif (! isset($existingVersions[(int) $id])) {
                    $rowHasError = true;
                    $errors[] = ['row' => $rowNum, 'field' => 'id', 'messages' => ["指定されたID「{$id}」のデータが存在しません。新規追加はID列を空欄にし、更新する場合は既存の正しいIDを指定してください。"]];
                } else {
                    // version 照合（楽観ロック・issue #45）。id が実在し形式も正しい場合のみ判定する
                    // （id 自体が不正/不存在の行は上の分岐で既にエラー済みのため二重に判定しない）。
                    // version セル自体が空/不正な場合は importRules() の required/integer ルールが
                    // 別途エラーを出すため、ここでは「数値として正しく読めた場合」のみ照合する。
                    $version = $assoc['version'] ?? null;
                    if ($version !== null && is_numeric($version) && (float) $version == (int) $version
                        && (int) $version !== $existingVersions[(int) $id]) {
                        $rowHasError = true;
                        $errors[] = ['row' => $rowNum, 'field' => 'version', 'messages' => ['バージョンが一致しません。他のユーザーがこのデータを更新済みの可能性があります。最新のデータをエクスポートし直してから、再度インポートしてください。']];
                    }
                }
            }

            if (! $rowHasError) {
                // row は write() 側の最終再照合（issue #45・2026-09-02 修正）でエラー行番号を
                // 正しく報告するために保持する。
                $writable[] = ['row' => $rowNum, 'id' => $id, 'assoc' => $assoc];
            }
        }

        if ($errors !== []) {
            // 行番号順に整列（読みやすさ。row=null は末尾へ）
            usort($errors, fn ($a, $b) => ($a['row'] ?? PHP_INT_MAX) <=> ($b['row'] ?? PHP_INT_MAX));
            $this->fail($errors);
        }

        $writeResult = $this->write($schema, $writable, $existingVersions);

        return [
            'resource' => $schema->resourceKey(),
            'summary' => [
                'total_rows' => $writeResult['total_rows'],
                'created' => $writeResult['created'],
                'updated' => $writeResult['updated'],
            ],
            // 生成トリガーの全経路適用（issue #61）等、書き込み対象を後から特定したい呼び出し側向けの補助情報。
            // 汎用（人材・案件で共有）であり AI 固有の概念はここに持ち込まない。
            //   updated_ids  … 更新（id 指定）行の実 id 一覧。新規（id=null）行はここに含まれない。
            //   written_at   … このバッチの created_at / updated_at に使われた基準時刻。新規行を
            //                  `where('created_at', $writtenAt)` で絞り込むのに使う。
            'updated_ids' => $writeResult['updated_ids'],
            'written_at' => $writeResult['written_at'],
        ];
    }

    /**
     * 全行 OK のときのみ呼ばれる。1トランザクション内で upsert によりバッチ INSERT / UPDATE する。
     *
     * 新規（id=null）と更新（id=既存）を1つの配列にまとめ、500件ずつ upsert する。
     * MySQL は AUTO_INCREMENT 列への明示的な NULL で通常どおり採番するため、id=null は INSERT、
     * id=既存は主キー衝突で ON DUPLICATE KEY UPDATE（＝UPDATE）になる。個別 UPDATE を1件ずつ発行していた
     * 従来方式に比べ、更新中心のインポートでも SQL 発行回数が件数に比例して増えない。
     * engineers / projects は主キー（id）以外にユニーク制約が無いため、他キー衝突で別レコードを
     * 取り違えて更新する危険はない（マイグレーション確認済み）。
     * `created_at` は更新列に含めない（既存行の作成日時を保持）。全項目上書き＝O-1（issue #45で確定：
     * version 列による楽観ロックをCSV更新経路にも適用。version 照合済みの行のみここに到達する）。
     *
     * **version 再照合（issue #45・2026-09-02 修正／レビュー指摘）**：pass2 の version 照合は
     * `import()` の preload 時点（=このメソッドの呼び出しより前）の値を見ているだけで、その後
     * upsert() がコミットされるまでの間に他セッション（画面編集や別のCSVインポート）が同じ行を
     * 更新すると、その更新をそのまま上書きしてしまう（画面編集4経路は lockForUpdate() ロック内で
     * 照合〜更新まで1トランザクションに閉じているのに対し、従来のCSVはそうなっていなかった）。
     * これを塞ぐため、実際に書き込む直前・同一トランザクション内で対象行を再度 lockForUpdate() し、
     * pass2 で照合済みの version から変化していないかをもう一度確認する。変化していれば
     * （＝ちょうどこの間に競合が起きた）1行もコミットせず、pass2 と同じ 422 エラー行一覧で返す
     * （全行ロールバック方針を維持）。これにより画面編集4経路と同じ「ロック→照合→更新」が
     * 1トランザクションに閉じる形になり、CSVインポートだけ空いていた競合ウィンドウを閉じる。
     *
     * @param  array<int, array{row: int, id: ?string, assoc: array<string, mixed>}>  $writable
     * @param  array<int, int>  $currentVersions  id => pass2 で照合済みの version（新 version・+1 の
     *                                             算出と、書き込み直前の再照合の両方に使う）
     * @return array{total_rows: int, created: int, updated: int, updated_ids: array<int, int>, written_at: \Illuminate\Support\Carbon}
     *
     * @throws ValidationException 書き込み直前の再照合で version 不一致を検出した場合
     */
    private function write(CsvSchema $schema, array $writable, array $currentVersions): array
    {
        /** @var class-string<Model> $model */
        $model = $schema->modelClass();
        $now = now();

        $rows = [];
        $rowNumbersByUpdateId = []; // id => CSV上の行番号（再照合で不一致だった場合のエラー行番号用）
        $updatedIds = [];
        $created = 0;
        $updated = 0;
        $updateColumns = null;
        foreach ($writable as $row) {
            $attrs = $schema->buildAttributes($row['assoc']);
            $id = $row['id'];
            // version（楽観ロック・issue #45）：CSVセルの値をそのまま書き込まない。新規行は 0
            // （DBのdefaultと同値。upsert は全行で同一のキー構成が必要なため明示する）、更新行は
            // pass2 で照合済みの現在値 + 1 を書き込む（画面編集の PUT/PATCH 側と同じ「保存の都度+1」
            // にすることで、CSVインポート後に開いていた編集画面の version が確実に古くなり、
            // 画面側の楽観ロックにも検知される＝相互に穴を作らない）。
            $attrs['version'] = $id === null ? 0 : $currentVersions[(int) $id] + 1;
            // 更新時に上書きする列＝全属性＋updated_at。id（一致キー）と created_at は除外し既存値を保持する。
            $updateColumns ??= array_merge(array_keys($attrs), ['updated_at']);
            // 全行で列構成を揃える（upsert は先頭行のキーで列を決めるため）。id は新規=null／更新=既存値。
            $rows[] = ['id' => $id === null ? null : (int) $id]
                + $attrs
                + ['created_at' => $now, 'updated_at' => $now];
            if ($id === null) {
                $created++;
            } else {
                $updated++;
                $updatedIds[] = (int) $id;
                $rowNumbersByUpdateId[(int) $id] = $row['row'];
            }
        }

        DB::transaction(function () use ($model, $rows, $updateColumns, $currentVersions, $rowNumbersByUpdateId): void {
            $this->guardVersionsUnchangedSincePreload($model, $currentVersions, $rowNumbersByUpdateId);

            foreach (array_chunk($rows, 500) as $chunk) {
                $model::query()->upsert($chunk, ['id'], $updateColumns);
            }
        });

        return [
            'total_rows' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'updated_ids' => $updatedIds,
            'written_at' => $now,
        ];
    }

    /**
     * write() の書き込み直前・同一トランザクション内で行う version の最終再照合（issue #45・2026-09-02 修正）。
     *
     * 対象の更新行（id 指定）を lockForUpdate() で再取得し、pass2 で照合した $currentVersions と
     * 現在の DB 値を比較する。1件でも変化していれば（＝preload〜ここまでの間に他セッションが更新した）
     * 呼び出し元の DB::transaction を通じて全行ロールバックし、他のバリデーションエラーと同じ
     * 422 のエラー行一覧（`field: "version"`）を投げる。
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int>  $currentVersions  id => pass2 で照合済みの version
     * @param  array<int, int>  $rowNumbersByUpdateId  id => CSV上の行番号（エラー表示用）
     *
     * @throws ValidationException
     */
    private function guardVersionsUnchangedSincePreload(string $model, array $currentVersions, array $rowNumbersByUpdateId): void
    {
        $updateIds = array_keys($rowNumbersByUpdateId);
        if ($updateIds === []) {
            return;
        }

        $latestVersions = $model::query()->whereIn('id', $updateIds)->lockForUpdate()->pluck('version', 'id');

        $errors = [];
        foreach ($updateIds as $id) {
            if (($latestVersions[$id] ?? null) !== $currentVersions[$id]) {
                $errors[] = [
                    'row' => $rowNumbersByUpdateId[$id],
                    'field' => 'version',
                    'messages' => ['バージョンが一致しません。他のユーザーがこのデータを更新済みの可能性があります。最新のデータをエクスポートし直してから、再度インポートしてください。'],
                ];
            }
        }

        if ($errors !== []) {
            usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);
            $this->fail($errors);
        }
    }

    /**
     * 構造化エラーを errors.importErrors（JSON 文字列）として 422 で投げる。
     * flash では返さない（Inertia の onSuccess 誤発火防止）。
     *
     * @param  array<int, array{row: ?int, field: ?string, messages: array<int, string>}>  $errors
     *
     * @throws ValidationException
     */
    private function fail(array $errors): never
    {
        throw ValidationException::withMessages([
            'importErrors' => [json_encode($errors, JSON_UNESCAPED_UNICODE)],
        ]);
    }
}
