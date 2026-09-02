<?php

namespace App\Support\Csv;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * CSV カラム定義の単一出所（SSOT）— 抽象基底。
 *
 * サブクラス（EngineerCsvSchema / ProjectCsvSchema）は `columns()` に「ヘッダー名↔DB列・列順・
 * 型・エクスポート専用フラグ」を1箇所だけ定義する。ヘッダー照合・行バリデーションルール・
 * エクスポート整形・DB 保存属性の組み立ては、すべてこの定義から機械的に導出する。
 * これにより「カラムを1列足す＝columns() に1行足すだけ」で入出力の両方に反映される（DRY）。
 *
 * 型（type）の意味：
 *   - id            … 主キー（新規=空 / 更新=既存ID）。行バリデーション対象外（存在判定は Service が preload 照合）
 *   - string / text … 文字列（書式は共有ルール ProjectRules/EngineerRules から取得）
 *   - integer       … 整数（min/max も共有ルール由来）
 *   - date          … 日付。CSV では date_format:Y-m-d に厳格化（O-10）
 *   - flag          … 0/1 フラグ。CSV では in:0,1 に限定（O-2 補足）
 *   - enum          … status / commercial_flow / work_style（共有ルールの in:... を使用）
 *   - user          … main_user_id / sub_user_id（integer のみ。exists は Service が preload 照合＝N+1回避）
 *   - relation_name … 担当者名（主担当名/サブ担当名）。エクスポート専用の派生値
 *   - version       … 楽観ロック制御列（issue #45）。エクスポートは参照用の現在値、インポートは
 *                     更新行の照合にのみ使う。writableColumns() には含めない（DB書き込み値は
 *                     CsvImportService が「新規=0／更新=照合済みの現在値+1」を算出して設定するため、
 *                     CSVセルの値をそのまま保存してはいけない）
 *
 * 設計原則：DRY（列定義の単一出所）／SRP（列メタデータと導出に責務を限定）／OCP（列追加に対して開いている）。
 */
abstract class CsvSchema
{
    /**
     * 列定義（エクスポートの列順そのもの）。各要素：
     *   ['header' => 'ヘッダー名', 'field' => 'db_column'|null, 'type' => '...', 'relation' => 'mainUser'?, 'export_only' => bool?]
     *
     * @return array<int, array{header: string, field: ?string, type: string, relation?: string, export_only?: bool}>
     */
    abstract public function columns(): array;

    /**
     * 共有の書式・範囲ルール（EngineerRules::formatRules() / ProjectRules::formatRules()）。
     *
     * @return array<string, array<int, mixed>>
     */
    abstract protected function sharedFormatRules(): array;

    /**
     * CSV 固有の必須フィールド集合（form_field_settings は不適用）。
     *
     * @return array<int, string>
     */
    abstract public function requiredFields(): array;

    /** 対象 Eloquent モデルの FQCN。 */
    abstract public function modelClass(): string;

    /** リソース名（ファイル名・リソースキー用。例 'engineers'）。 */
    abstract public function resourceKey(): string;

    /**
     * エクスポート用の絞り込み済みクエリ（SELECT 明示・担当者名 Eager Loading 済み）。
     *
     * @param  array<string, mixed>  $filters  検証済みの絞り込みパラメータ
     */
    abstract public function exportQuery(array $filters): Builder;

    /**
     * 行データ（field => value）に対するインポート前の正規化。既定は無変換。
     * 人材は name / name_kana の半角→全角スペース変換のためにオーバーライドする。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeRow(array $data): array
    {
        return $data;
    }

    /**
     * 行に依存する条件付きルール（rate_min/max の相互 lte/gte・required_if 等）。
     * 既定は無し。案件でオーバーライドする。
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array<int, mixed>>
     */
    protected function conditionalImportRules(array $row): array
    {
        return [];
    }

    // ------------------------------------------------------------------
    // 導出（ヘッダー照合）
    // ------------------------------------------------------------------

    /**
     * インポート対象の [ヘッダー名 => field]（エクスポート専用列は除外・id を含む）。
     *
     * @return array<string, string>
     */
    public function importableHeaderMap(): array
    {
        $map = [];
        foreach ($this->columns() as $col) {
            if (($col['export_only'] ?? false) || $col['field'] === null) {
                continue;
            }
            $map[$col['header']] = $col['field'];
        }

        return $map;
    }

    /**
     * インポート時に存在必須なヘッダー（＝インポート対象列のヘッダー）。
     * 欠落するとヘッダーエラーで全体中断する（O-7）。
     *
     * @return array<int, string>
     */
    public function requiredHeaders(): array
    {
        return array_keys($this->importableHeaderMap());
    }

    /**
     * エクスポートのヘッダー行（全列・列順どおり）。
     *
     * @return array<int, string>
     */
    public function exportHeaders(): array
    {
        return array_map(fn ($col) => $col['header'], $this->columns());
    }

    // ------------------------------------------------------------------
    // 導出（バリデーション）
    // ------------------------------------------------------------------

    /**
     * バリデーションメッセージ用の日本語属性名（field => ヘッダー名）。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attrs = [];
        foreach ($this->columns() as $col) {
            if ($col['field'] === null || $col['type'] === 'relation_name') {
                continue;
            }
            $attrs[$col['field']] = $col['header'];
        }

        return $attrs;
    }

    /**
     * 1行分のインポートバリデーションルール（field => rules）。
     * 共有ルール ＋ CSV 固有（date_format / in:0,1 / exists 除外）＋ 必須/任意 ＋ 条件付き。
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array<int, mixed>>
     */
    public function importRules(array $row): array
    {
        $shared = $this->sharedFormatRules();
        $required = $this->requiredFields();
        $rules = [];

        foreach ($this->writableColumns() as $col) {
            $field = $col['field'];
            $base = $this->csvRulesForColumn($col, $shared);
            $prefix = in_array($field, $required, true) ? 'required' : 'nullable';
            $rules[$field] = array_merge([$prefix], $base);
        }

        // version（楽観ロック・issue #45）：writableColumns() に含めないため専用に組み立てる。
        // 更新行（id 指定）は他ユーザーとの競合を検知するために必須（形式もチェックする）。
        // 新規行（id 空）は書き込み時に必ず無視され値を読み取らないため、形式チェック自体を行わない
        // （nullable + integer/min:0 のままだと「空欄以外の非数値」が弾かれてしまい、CSVヒント文や
        // チェックリストが説明する「新規行では何を入れても無視される」という前提と食い違うため。
        // 2026-09-02 修正：レビュー指摘）。
        $rules['version'] = ($row['id'] ?? null) !== null
            ? ['required', 'integer', 'min:0']
            : [];

        foreach ($this->conditionalImportRules($row) as $field => $extra) {
            $rules[$field] = array_merge($rules[$field] ?? ['nullable'], $extra);
        }

        return $rules;
    }

    /**
     * インポート行バリデーションのカスタムメッセージ（§8 の文面に寄せる）。
     * - date_format は CSV でしか使わないため一括で「YYYY-MM-DD 形式」に上書きしてよい。
     * - in はフラグ列（0/1）と enum 列（status 等）の両方で発火するため、
     *   フラグ列だけ field 固有に「0または1」を割り当て、enum の in 文面は汎用のまま残す。
     *
     * @return array<string, string>
     */
    public function importMessages(): array
    {
        $messages = [
            'date_format' => ':attributeはYYYY-MM-DD形式で入力してください。',
        ];

        foreach ($this->writableColumns() as $col) {
            if ($col['type'] === 'flag') {
                $messages[$col['field'].'.in'] = ':attributeは0または1で入力してください。';
            }
        }

        return $messages;
    }

    /**
     * 列型に応じた CSV 用の書式ルール（required/nullable を含まない）。
     *
     * @param  array{header: string, field: ?string, type: string}  $col
     * @param  array<string, array<int, mixed>>  $shared
     * @return array<int, mixed>
     */
    private function csvRulesForColumn(array $col, array $shared): array
    {
        $field = $col['field'];

        return match ($col['type']) {
            // 0/1 フラグは CSV では in:0,1 に限定（boolean の true/false は受理しない）
            'flag' => ['in:0,1'],

            // 日付は date_format:Y-m-d に厳格化（O-10）。before_or_equal:today 等の付随ルールは維持する
            'date' => array_map(
                fn ($rule) => $rule === 'date' ? 'date_format:Y-m-d' : $rule,
                $shared[$field] ?? ['date'],
            ),

            // 担当者 ID は integer のみ（exists は Service が preload 照合＝N+1回避）。sub は different を付与
            'user' => $field === 'sub_user_id'
                ? ['integer', 'different:main_user_id']
                : ['integer'],

            // string / text / integer / enum は共有ルールをそのまま使う
            default => $shared[$field] ?? [],
        };
    }

    // ------------------------------------------------------------------
    // 導出（列の分類）
    // ------------------------------------------------------------------

    /**
     * 書き込み可能な列（エクスポート専用・relation・id を除く）。
     *
     * @return array<int, array{header: string, field: string, type: string}>
     */
    public function writableColumns(): array
    {
        return array_values(array_filter($this->columns(), function ($col) {
            return ! ($col['export_only'] ?? false)
                && $col['field'] !== null
                && $col['type'] !== 'id'
                // version は楽観ロック制御列（issue #45）。読み取り・照合はするが buildAttributes()
                // でCSVセルの値をそのまま書き込ませないため、通常の書き込み可能列からは除外する。
                && $col['type'] !== 'version';
        }));
    }

    // ------------------------------------------------------------------
    // 保存属性の組み立て
    // ------------------------------------------------------------------

    /**
     * 正規化・復元済みの行データ（field => value）から DB 保存属性を組み立てる。
     * 空セルは null（空文字と null は同一扱い）。型に応じてキャストする。
     * id・エクスポート専用列（ai_summary・担当者名）は含めない（＝更新時も上書きしない）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildAttributes(array $data): array
    {
        $attrs = [];
        foreach ($this->writableColumns() as $col) {
            $field = $col['field'];
            $raw = $data[$field] ?? null;

            if ($raw === null || $raw === '') {
                $attrs[$field] = null;

                continue;
            }

            $attrs[$field] = match ($col['type']) {
                'flag', 'integer', 'user' => (int) $raw,
                'date' => $raw, // 検証済みの 'Y-m-d' 文字列をそのまま保存
                default => (string) $raw,
            };
        }

        return $attrs;
    }

    // ------------------------------------------------------------------
    // エクスポート整形
    // ------------------------------------------------------------------

    /**
     * 1レコードをエクスポート用のセル配列（列順・生値。インジェクションエスケープ前）に整形する。
     *
     * @return array<int, string>
     */
    public function exportRow(Model $model): array
    {
        $cells = [];
        foreach ($this->columns() as $col) {
            $cells[] = $this->exportCell($model, $col);
        }

        return $cells;
    }

    /**
     * @param  array{header: string, field: ?string, type: string, relation?: string}  $col
     */
    private function exportCell(Model $model, array $col): string
    {
        if ($col['type'] === 'relation_name') {
            $related = $model->getRelationValue($col['relation']);

            return $related?->name ?? '';
        }

        $field = $col['field'];
        $value = $model->getAttribute($field);

        if ($value === null || $value === '') {
            return '';
        }

        return match ($col['type']) {
            'id', 'integer', 'user', 'version' => (string) $value,
            // boolean キャスト or 0/1 を確実に '0'/'1' へ
            'flag' => ((int) $value) === 0 ? '0' : '1',
            'date' => Carbon::parse($value)->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
