<?php

namespace Tests\Feature\Csv;

use App\Models\Engineer;
use App\Support\Csv\CsvInjection;
use App\Support\Csv\EngineerCsvSchema;
use App\Support\Csv\ProjectCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use League\Csv\Writer;

/**
 * CSV インポート（人材・案件）の正常系・異常系・境界値・ロールバック・空行/BOM/RFC 往復・インジェクション。
 */
class CsvImportTest extends CsvTestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // ヘルパー
    // ------------------------------------------------------------------

    private function engineer(array $overrides = []): Engineer
    {
        $mainUserId = $overrides['main_user_id'] ?? $this->makeUser()->id;

        return Engineer::create(array_merge([
            'name' => '既存太郎',
            'name_kana' => 'キソンタロウ',
            'status' => 'proposable',
            'main_user_id' => $mainUserId,
        ], $overrides));
    }

    /** @param array<int, array{row: ?int, field: ?string, messages: array<int, string>}> $errors */
    private function findError(array $errors, ?int $row, ?string $field): ?array
    {
        foreach ($errors as $e) {
            if ($e['row'] === $row && $e['field'] === $field) {
                return $e;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 正常系
    // ------------------------------------------------------------------

    public function test_engineer_import_creates_new_rows(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [
            ['name' => '新規一郎', 'name_kana' => 'シンキイチロウ', 'status' => 'proposable', 'main_user_id' => $user->id, 'work_style_onsite' => '1'],
            ['name' => '新規二郎', 'name_kana' => 'シンキジロウ', 'status' => 'interviewing', 'main_user_id' => $user->id, 'desired_rate' => '80'],
        ]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false);
        $response->assertRedirect(route('csv.index'));

        $result = session('importResult');
        $this->assertSame('engineers', $result['resource']);
        $this->assertSame(['total_rows' => 2, 'created' => 2, 'updated' => 0], $result['summary']);

        $this->assertDatabaseHas('engineers', ['name' => '新規一郎', 'work_style_onsite' => 1]);
        $this->assertDatabaseHas('engineers', ['name' => '新規二郎', 'desired_rate' => 80]);
    }

    public function test_engineer_import_updates_existing_row_and_ignores_ai_summary(): void
    {
        $user = $this->makeUser('admin');
        $engineer = $this->engineer(['main_user_id' => $user->id, 'ai_summary' => '既存の要約']);

        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'id' => $engineer->id,
            'name' => '更新後の名前',
            'name_kana' => 'コウシンゴ',
            'status' => 'not_proposable',
            'main_user_id' => $user->id,
            'ai_summary' => 'これは無視されるべき', // import 対象外列（buildCsv には出ないが念のため）
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)
            ->assertRedirect(route('csv.index'));

        $this->assertSame(['total_rows' => 1, 'created' => 0, 'updated' => 1], session('importResult')['summary']);

        $engineer->refresh();
        $this->assertSame('更新後の名前', $engineer->name);
        $this->assertSame('not_proposable', $engineer->status);
        $this->assertSame('既存の要約', $engineer->ai_summary, 'ai_summary は上書きされない');
    }

    public function test_engineer_import_mixed_create_and_update(): void
    {
        $user = $this->makeUser('admin');
        $engineer = $this->engineer(['main_user_id' => $user->id]);

        $csv = $this->buildCsv(new EngineerCsvSchema, [
            ['id' => $engineer->id, 'name' => '更新済', 'name_kana' => 'コウシンズミ', 'status' => 'proposable', 'main_user_id' => $user->id],
            ['name' => '新規のみ', 'name_kana' => 'シンキノミ', 'status' => 'proposable', 'main_user_id' => $user->id],
        ]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();

        $this->assertSame(['total_rows' => 2, 'created' => 1, 'updated' => 1], session('importResult')['summary']);
    }

    public function test_engineer_import_empty_cell_overwrites_existing_with_null(): void
    {
        $user = $this->makeUser('admin');
        $engineer = $this->engineer(['main_user_id' => $user->id, 'nearest_station' => '東京', 'desired_rate' => 90]);

        // nearest_station / desired_rate を空セルで送る → null 上書き
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'id' => $engineer->id, 'name' => '既存太郎', 'name_kana' => 'キソンタロウ',
            'status' => 'proposable', 'main_user_id' => $user->id,
            'nearest_station' => '', 'desired_rate' => '',
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();

        $engineer->refresh();
        $this->assertNull($engineer->nearest_station);
        $this->assertNull($engineer->desired_rate);
    }

    public function test_engineer_name_kana_half_width_space_is_normalized(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => '山田 太郎', 'name_kana' => 'ヤマダ タロウ', 'status' => 'proposable', 'main_user_id' => $user->id,
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();

        // 半角スペースが全角に正規化されて保存される（フォームと一致）
        $this->assertDatabaseHas('engineers', ['name' => '山田　太郎', 'name_kana' => 'ヤマダ　タロウ']);
    }

    public function test_project_import_creates_new_row(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new ProjectCsvSchema, [[
            'name' => '新規案件', 'status' => 'open', 'main_user_id' => $user->id,
            'work_style' => 'remote', 'commercial_flow' => 'prime', 'headcount' => '3',
        ]]);

        $this->postImport($user, 'csv.projects.import', $this->makeUpload($csv), false)->assertRedirect();

        $this->assertSame('projects', session('importResult')['resource']);
        $this->assertDatabaseHas('projects', ['name' => '新規案件', 'work_style' => 'remote', 'headcount' => 3]);
    }

    // ------------------------------------------------------------------
    // 異常系（ファイルレベル）
    // ------------------------------------------------------------------

    public function test_non_csv_extension_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        $file = $this->makeUpload('id\n1', 'data.png', 'text/plain');

        $this->postImport($user, 'csv.engineers.import', $file)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('file');
    }

    public function test_oversized_file_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        $file = UploadedFile::fake()->create('big.csv', 6000); // 6000KB > 5MB

        $this->actingAs($user)->post(route('csv.engineers.import'), ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('file');
    }

    public function test_non_utf8_file_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        $sjis = mb_convert_encoding("id,氏名\n1,日本語", 'SJIS-win', 'UTF-8');

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($sjis));
        $response->assertStatus(422)->assertJsonValidationErrorFor('file');
        $this->assertStringContainsString('UTF-8', $response->json('errors.file.0'));
    }

    public function test_over_5000_data_rows_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        $content = "id\r\n".str_repeat("x\r\n", 5001);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422)->assertJsonValidationErrorFor('file');
        $this->assertStringContainsString('5,000', $response->json('errors.file.0'));
    }

    public function test_missing_required_header_aborts_whole_import(): void
    {
        $user = $this->makeUser('admin');
        // 氏名カナ 列を落としたヘッダー（必須列欠落）
        $content = "id,氏名,ステータス,主担当ID\r\n,山田,proposable,{$user->id}\r\n";

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 1, null));
        $this->assertStringContainsString('必要な列がありません', $errors[0]['messages'][0]);
        $this->assertStringContainsString('氏名カナ', $errors[0]['messages'][0]);
    }

    public function test_unknown_header_column_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        // 全 importable 列＋定義にない「メモ」列（未知列）。ヘッダー段階で全体中断する。
        $headers = array_keys((new EngineerCsvSchema)->importableHeaderMap());
        $content = implode(',', $headers).",メモ\r\n";

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 1, null));
        $this->assertStringContainsString('不明な列があります', $errors[0]['messages'][0]);
        $this->assertStringContainsString('メモ', $errors[0]['messages'][0]);
    }

    public function test_empty_header_column_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        // 末尾の余分なカンマ由来の空ヘッダーは専用メッセージで弾く（列位置つき）。
        $headers = array_keys((new EngineerCsvSchema)->importableHeaderMap());
        $content = implode(',', $headers).",\r\n";

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 1, null));
        $this->assertStringContainsString('ヘッダーに空の列があります', $errors[0]['messages'][0]);
    }

    public function test_duplicate_header_column_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        // 「氏名」を重複させる。値の対応が曖昧になるためヘッダー段階で弾く。
        $headers = array_keys((new EngineerCsvSchema)->importableHeaderMap());
        $content = implode(',', $headers).',氏名'."\r\n";

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 1, null));
        $this->assertStringContainsString('ヘッダーに重複した列があります', $errors[0]['messages'][0]);
        $this->assertStringContainsString('氏名', $errors[0]['messages'][0]);
    }

    public function test_header_only_zero_data_rows_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, []); // ヘッダーのみ

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertStringContainsString('インポートする対象データがありません', $errors[0]['messages'][0]);
    }

    // ------------------------------------------------------------------
    // 異常系（行）
    // ------------------------------------------------------------------

    public function test_column_count_mismatch_is_structural_error(): void
    {
        $user = $this->makeUser('admin');
        $headers = implode(',', array_keys((new EngineerCsvSchema)->importableHeaderMap()));
        // 列数不足の行（1列だけ）→ レイアウトエラー（field:null）・項目検証はスキップ
        $content = $headers."\r\nonlyonecolumn\r\n";

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422);
        $error = $this->findError($this->importErrors($response), 2, null);
        $this->assertNotNull($error);
        $this->assertStringContainsString('列数がヘッダーと一致しません', $error['messages'][0]);
    }

    public function test_spaces_only_line_is_layout_error_not_skipped(): void
    {
        $user = $this->makeUser('admin');
        $headers = implode(',', array_keys((new EngineerCsvSchema)->importableHeaderMap()));
        // 2行目=スペースだけ（1列としてパース→レイアウトエラー）／空行ではない
        $content = $headers."\r\n   \r\n";

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content));
        $response->assertStatus(422);
        $this->assertNotNull($this->findError($this->importErrors($response), 2, null));
    }

    public function test_non_existent_update_id_is_row_error(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'id' => 99999, 'name' => '存在しないID', 'name_kana' => 'ソンザイシナイ', 'status' => 'proposable', 'main_user_id' => $user->id,
        ]]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $error = $this->findError($this->importErrors($response), 2, 'id');
        $this->assertNotNull($error);
        $this->assertDatabaseMissing('engineers', ['name' => '存在しないID']);
    }

    public function test_duplicate_id_in_file_is_structural_error(): void
    {
        $user = $this->makeUser('admin');
        $engineer = $this->engineer(['main_user_id' => $user->id]);

        $csv = $this->buildCsv(new EngineerCsvSchema, [
            ['id' => $engineer->id, 'name' => 'A', 'name_kana' => 'エー', 'status' => 'proposable', 'main_user_id' => $user->id],
            ['id' => $engineer->id, 'name' => 'B', 'name_kana' => 'ビー', 'status' => 'proposable', 'main_user_id' => $user->id],
        ]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 2, null));
        $this->assertNotNull($this->findError($errors, 3, null));
        $this->assertStringContainsString('同一ID', $this->findError($errors, 2, null)['messages'][0]);
    }

    public function test_leading_zero_id_variants_are_detected_as_duplicate(): void
    {
        $user = $this->makeUser('admin');
        $engineer = $this->engineer(['main_user_id' => $user->id]);

        // 同一レコードを指す "1" と "01"（Excel 等が生む表記ゆれ）を2行に置く。
        // 文字列そのままをキーにすると別ID扱いで重複をすり抜け、同一行を二重 UPDATE してしまうため、
        // 正規化キー（(int) 化）で重複として検出されることを担保する。
        $csv = $this->buildCsv(new EngineerCsvSchema, [
            ['id' => (string) $engineer->id, 'name' => 'A', 'name_kana' => 'エー', 'status' => 'proposable', 'main_user_id' => $user->id],
            ['id' => '0'.$engineer->id, 'name' => 'B', 'name_kana' => 'ビー', 'status' => 'proposable', 'main_user_id' => $user->id],
        ]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 2, null));
        $this->assertNotNull($this->findError($errors, 3, null));
        $this->assertStringContainsString('同一ID', $this->findError($errors, 2, null)['messages'][0]);

        // 全行ロールバック：どちらの表記も書き込まれず、既存レコードは元のまま（後勝ちの二重更新が起きない）
        $engineer->refresh();
        $this->assertSame('既存太郎', $engineer->name);
    }

    public function test_non_existent_main_user_id_is_row_error_without_n_plus_one(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => '担当不明', 'name_kana' => 'タントウフメイ', 'status' => 'proposable', 'main_user_id' => 88888,
        ]]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $this->assertNotNull($this->findError($this->importErrors($response), 2, 'main_user_id'));
    }

    public function test_boundary_values_are_rejected(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => '境界値', 'name_kana' => 'キョウカイチ', 'status' => 'proposable', 'main_user_id' => $user->id,
            'desired_rate' => '1000',        // max:999 違反
            'birth_date' => '2020/01/01',    // date_format:Y-m-d 違反
            'work_style_onsite' => '2',      // in:0,1 違反
        ]]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 2, 'desired_rate'));
        $this->assertNotNull($this->findError($errors, 2, 'birth_date'));
        $this->assertNotNull($this->findError($errors, 2, 'work_style_onsite'));

        // フラグ列・日付は §8 に沿った分かりやすい文面にする（汎用の「選択された…は無効です」等にしない）
        $this->assertStringContainsString(
            '0または1',
            $this->findError($errors, 2, 'work_style_onsite')['messages'][0],
        );
        $this->assertStringContainsString(
            'YYYY-MM-DD',
            $this->findError($errors, 2, 'birth_date')['messages'][0],
        );
    }

    public function test_one_cell_collects_multiple_messages(): void
    {
        $user = $this->makeUser('admin');
        // 氏名カナ：漢字（regex 違反）かつ 100文字超（max 違反）→ 2メッセージ
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => 'テスト', 'name_kana' => str_repeat('漢', 101), 'status' => 'proposable', 'main_user_id' => $user->id,
        ]]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $error = $this->findError($this->importErrors($response), 2, 'name_kana');
        $this->assertNotNull($error);
        $this->assertGreaterThanOrEqual(2, count($error['messages']), '1セルに複数メッセージが集約される（bail なし）');
    }

    public function test_sub_user_id_must_differ_from_main(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => '同一担当', 'name_kana' => 'ドウイツ', 'status' => 'proposable',
            'main_user_id' => $user->id, 'sub_user_id' => $user->id,
        ]]);

        $response = $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $this->assertNotNull($this->findError($this->importErrors($response), 2, 'sub_user_id'));
    }

    public function test_any_error_rolls_back_all_rows(): void
    {
        $user = $this->makeUser('admin');
        // 1行目は正常、2行目は必須欠落 → 全行ロールバック（1行目も保存されない）
        $csv = $this->buildCsv(new EngineerCsvSchema, [
            ['name' => '正常行', 'name_kana' => 'セイジョウ', 'status' => 'proposable', 'main_user_id' => $user->id],
            ['name' => '', 'name_kana' => '', 'status' => '', 'main_user_id' => ''],
        ]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv))->assertStatus(422);

        $this->assertDatabaseCount('engineers', 0);
    }

    public function test_project_rate_min_greater_than_max_is_rejected(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new ProjectCsvSchema, [[
            'name' => '単価逆転', 'status' => 'open', 'main_user_id' => $user->id,
            'rate_min' => '100', 'rate_max' => '50',
        ]]);

        $response = $this->postImport($user, 'csv.projects.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 2, 'rate_min'));
        $this->assertNotNull($this->findError($errors, 2, 'rate_max'));
    }

    public function test_project_station_required_when_onsite(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new ProjectCsvSchema, [[
            'name' => '常駐案件', 'status' => 'open', 'main_user_id' => $user->id,
            'work_style' => 'onsite', 'work_location_station' => '',
        ]]);

        $response = $this->postImport($user, 'csv.projects.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $this->assertNotNull($this->findError($this->importErrors($response), 2, 'work_location_station'));
    }

    public function test_project_headcount_and_interview_boundaries(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new ProjectCsvSchema, [[
            'name' => '境界案件', 'status' => 'open', 'main_user_id' => $user->id,
            'headcount' => '100',       // max:99
            'interview_count' => '11',  // max:10
        ]]);

        $response = $this->postImport($user, 'csv.projects.import', $this->makeUpload($csv));
        $response->assertStatus(422);
        $errors = $this->importErrors($response);
        $this->assertNotNull($this->findError($errors, 2, 'headcount'));
        $this->assertNotNull($this->findError($errors, 2, 'interview_count'));
    }

    // ------------------------------------------------------------------
    // 空行 / BOM / RFC4180 / インジェクション
    // ------------------------------------------------------------------

    public function test_blank_lines_are_skipped_and_not_counted(): void
    {
        $user = $this->makeUser('admin');
        $headers = implode(',', array_keys((new EngineerCsvSchema)->importableHeaderMap()));
        $schema = new EngineerCsvSchema;
        // buildCsv は空行を入れられないので、2つの有効行の CSV を作って間に空行を挿入する
        $rowA = $this->rowLine($schema, ['name' => 'Ａ行', 'name_kana' => 'エーギョウ', 'status' => 'proposable', 'main_user_id' => $user->id]);
        $rowB = $this->rowLine($schema, ['name' => 'Ｂ行', 'name_kana' => 'ビーギョウ', 'status' => 'proposable', 'main_user_id' => $user->id]);
        $content = $headers."\r\n".$rowA."\r\n\r\n".$rowB."\r\n\r\n";

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($content), false)->assertRedirect();

        // 空行は total_rows に計上されない
        $this->assertSame(['total_rows' => 2, 'created' => 2, 'updated' => 0], session('importResult')['summary']);
    }

    public function test_bom_prefixed_file_imports_successfully(): void
    {
        $user = $this->makeUser('admin');
        $csv = "\xEF\xBB\xBF".$this->buildCsv(new EngineerCsvSchema, [[
            'name' => 'BOM太郎', 'name_kana' => 'ボムタロウ', 'status' => 'proposable', 'main_user_id' => $user->id,
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();
        $this->assertDatabaseHas('engineers', ['name' => 'BOM太郎']);
    }

    public function test_rfc4180_field_with_comma_quote_newline_round_trips(): void
    {
        $user = $this->makeUser('admin');
        $tricky = "行1,\"引用\"\n2行目"; // カンマ・ダブルクオート・改行を含む
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => 'RFC太郎', 'name_kana' => 'アールエフシー', 'status' => 'proposable',
            'main_user_id' => $user->id, 'appeal_note' => $tricky,
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();

        $engineer = Engineer::where('name', 'RFC太郎')->first();
        $this->assertSame($tricky, $engineer->appeal_note, '引用符内の改行・カンマ・"" が1論理レコードとして復元される');
    }

    public function test_csv_injection_round_trip_on_import(): void
    {
        $user = $this->makeUser('admin');
        // エクスポートを模し、値を escape した状態のセルを import → restore で元値に戻る
        $escaped = CsvInjection::escape('=SUM(1+1)');
        $this->assertSame("'=SUM(1+1)", $escaped);

        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => 'インジェクション', 'name_kana' => 'インジェクション', 'status' => 'proposable',
            'main_user_id' => $user->id, 'appeal_note' => $escaped,
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();

        $engineer = Engineer::where('name', 'インジェクション')->first();
        $this->assertSame('=SUM(1+1)', $engineer->appeal_note, '機械が付けた先頭 \' は復元で除去される');
    }

    public function test_human_entered_leading_quote_is_preserved_on_import(): void
    {
        $user = $this->makeUser('admin');
        $csv = $this->buildCsv(new EngineerCsvSchema, [[
            'name' => 'アポストロフィ', 'name_kana' => 'アポストロフィ', 'status' => 'proposable',
            'main_user_id' => $user->id, 'appeal_note' => "'重要メモ", // 人が意図した先頭 '
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)->assertRedirect();

        $engineer = Engineer::where('name', 'アポストロフィ')->first();
        $this->assertSame("'重要メモ", $engineer->appeal_note, '危険文字が続かない先頭 \' は保持される');
    }

    public function test_exported_csv_round_trips_back_through_import(): void
    {
        // この機能のメイン運用（エクスポート→編集→再インポート）を実 export 出力で通しで検証する。
        // 実ファイルには export専用列（主担当名/サブ担当名/AI要約）と BOM が含まれるが、
        // 未知列扱いにならず（exportHeaders に含む）、値は無視され、id 付きで既存行が更新される。
        $mainUser = $this->makeUser('admin');
        $subUser = $this->makeUser('general');
        $engineer = $this->engineer([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $subUser->id,
            'name' => '往復太郎',
            'name_kana' => 'オウフクタロウ',
            'status' => 'interviewing',
            'birth_date' => '1990-01-02',
            'work_style_onsite' => 1,
            'ai_summary' => 'AI生成の要約',
        ]);

        // 実際のエクスポート出力（BOM 付き・26列・export専用列を含む）をそのまま取り込む。
        $exported = $this->actingAs($mainUser)->get(route('csv.engineers.export'))->streamedContent();

        $this->postImport($mainUser, 'csv.engineers.import', $this->makeUpload($exported), false)
            ->assertRedirect(route('csv.index'));

        $this->assertSame(['total_rows' => 1, 'created' => 0, 'updated' => 1], session('importResult')['summary']);

        $engineer->refresh();
        $this->assertSame('往復太郎', $engineer->name);
        $this->assertSame('interviewing', $engineer->status);
        $this->assertSame('1990-01-02', $engineer->birth_date, '日付が Y-m-d で往復する');
        $this->assertSame(1, (int) $engineer->work_style_onsite);
        // AI要約 は export専用列のため取り込まれず、既存値が保持される（上書きされない）
        $this->assertSame('AI生成の要約', $engineer->ai_summary);
    }

    /**
     * 1行分のセルを importableHeaderMap の順序で CSV 1行文字列にする（空行挿入テスト用）。
     */
    private function rowLine(EngineerCsvSchema $schema, array $row): string
    {
        $fields = array_values($schema->importableHeaderMap());
        $writer = Writer::createFromString();
        $writer->setEscape('');
        $writer->setEndOfLine('');
        $writer->insertOne(array_map(fn ($f) => (string) ($row[$f] ?? ''), $fields));

        return rtrim($writer->toString(), "\r\n");
    }
}
