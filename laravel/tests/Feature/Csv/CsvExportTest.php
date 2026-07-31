<?php

namespace Tests\Feature\Csv;

use App\Models\Engineer;
use App\Models\EngineerSkill;
use App\Models\Project;
use App\Support\Csv\CsvFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * CSV エクスポート：Content-Type/Disposition・BOM・ヘッダーのみ・担当者名列・ai_summary・
 * enum 内部値・絞り込み反映・インジェクションエスケープ。
 */
class CsvExportTest extends CsvTestCase
{
    use RefreshDatabase;

    private function engineer(array $overrides = []): Engineer
    {
        $mainUserId = $overrides['main_user_id'] ?? $this->makeUser()->id;

        return Engineer::create(array_merge([
            'name' => '書出太郎',
            'name_kana' => 'カキダシタロウ',
            'status' => 'proposable',
            'main_user_id' => $mainUserId,
        ], $overrides));
    }

    /** 論理レコード配列（ヘッダー含む）を返す。 */
    private function records(TestResponse $response): array
    {
        return CsvFile::readRecords($response->streamedContent());
    }

    public function test_export_sets_csv_headers_and_bom_and_filename(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->get(route('csv.engineers.export'));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/attachment; filename="engineers_\d{8}_\d{6}\.csv"/', $disposition);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent(), 'UTF-8 BOM 付き');
    }

    public function test_export_zero_rows_returns_header_only(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->get(route('csv.engineers.export'));
        $records = $this->records($response);

        $this->assertCount(1, $records, 'ヘッダー行のみ');
        $this->assertSame('id', $records[0][0]);
        $this->assertContains('氏名カナ', $records[0]);
        $this->assertContains('AI要約', $records[0], '人材はエクスポート専用の AI要約 列を持つ');
        $this->assertContains('主担当名', $records[0]);
    }

    public function test_export_includes_relation_names_and_ai_summary_and_internal_enum(): void
    {
        $mainUser = $this->makeUser('admin');
        $subUser = $this->makeUser('general');
        $this->engineer([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $subUser->id,
            'status' => 'interviewing',
            'ai_summary' => 'AI生成の要約',
        ]);

        $response = $this->actingAs($mainUser)->get(route('csv.engineers.export'));
        $records = $this->records($response);
        $header = $records[0];
        $row = $records[1];

        $col = fn (string $name) => $row[array_search($name, $header, true)];

        $this->assertSame('interviewing', $col('ステータス'), 'enum は内部値で出力');
        $this->assertSame($mainUser->name, $col('主担当名'));
        $this->assertSame($subUser->name, $col('サブ担当名'));
        $this->assertSame('AI生成の要約', $col('AI要約'));
    }

    public function test_export_blank_relation_name_when_sub_user_absent(): void
    {
        $mainUser = $this->makeUser('admin');
        $this->engineer(['main_user_id' => $mainUser->id, 'sub_user_id' => null]);

        $response = $this->actingAs($mainUser)->get(route('csv.engineers.export'));
        $records = $this->records($response);
        $header = $records[0];

        $this->assertSame('', $records[1][array_search('サブ担当名', $header, true)], '担当者未設定は空セル');
    }

    public function test_export_status_filter_is_applied(): void
    {
        $user = $this->makeUser('admin');
        $this->engineer(['main_user_id' => $user->id, 'name' => '提案可', 'status' => 'proposable']);
        $this->engineer(['main_user_id' => $user->id, 'name' => '面談中', 'status' => 'interviewing']);

        $response = $this->actingAs($user)->get(route('csv.engineers.export', ['status' => ['interviewing']]));
        $records = $this->records($response);

        $this->assertCount(2, $records, 'ヘッダー＋該当1行のみ');
        $header = $records[0];
        $this->assertSame('面談中', $records[1][array_search('氏名', $header, true)]);
    }

    public function test_export_keyword_filters_by_skill(): void
    {
        $user = $this->makeUser('admin');
        $withSkill = $this->engineer(['main_user_id' => $user->id, 'name' => 'スキル有り']);
        EngineerSkill::create(['engineer_id' => $withSkill->id, 'label' => 'PHP']);
        $this->engineer(['main_user_id' => $user->id, 'name' => 'スキル無し']);

        $response = $this->actingAs($user)->get(route('csv.engineers.export', ['keyword' => 'PHP']));
        $records = $this->records($response);

        $this->assertCount(2, $records);
        $this->assertSame('スキル有り', $records[1][array_search('氏名', $records[0], true)]);
    }

    public function test_export_escapes_formula_injection(): void
    {
        $user = $this->makeUser('admin');
        $this->engineer(['main_user_id' => $user->id, 'appeal_note' => '=IMPORTXML(1)']);

        $response = $this->actingAs($user)->get(route('csv.engineers.export'));
        $records = $this->records($response);
        $header = $records[0];

        $this->assertSame("'=IMPORTXML(1)", $records[1][array_search('アピールポイント', $header, true)]);
    }

    public function test_project_export_has_expected_headers_and_no_ai_summary(): void
    {
        $user = $this->makeUser('admin');
        Project::create(['name' => '案件A', 'status' => 'open', 'main_user_id' => $user->id, 'commercial_flow' => 'prime']);

        $response = $this->actingAs($user)->get(route('csv.projects.export'));
        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/projects_\d{8}_\d{6}\.csv/', $disposition);

        $records = $this->records($response);
        $header = $records[0];
        $this->assertContains('商流', $header);
        $this->assertContains('主担当名', $header);
        $this->assertNotContains('AI要約', $header, '案件に ai_summary 列は無い');
        $this->assertSame('prime', $records[1][array_search('商流', $header, true)], 'enum は内部値');
    }

    public function test_export_invalid_filter_returns_422(): void
    {
        $user = $this->makeUser('admin');

        $this->actingAs($user)
            ->getJson(route('csv.engineers.export', ['status' => ['not_a_status']]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('status.0');
    }
}
