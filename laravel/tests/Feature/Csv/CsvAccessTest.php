<?php

namespace Tests\Feature\Csv;

use App\Support\Csv\EngineerCsvSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 認可（O-3）と index Props の検証。admin/general 双方可・未ログインは弾く。
 */
class CsvAccessTest extends CsvTestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('csv.index'))->assertRedirect(route('login'));
    }

    public function test_guest_import_is_unauthorized(): void
    {
        $file = $this->makeUpload("id\n", 'data.csv');

        // Accept: json の未ログインは 401
        $this->post(route('csv.engineers.import'), ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_admin_can_open_index(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->get(route('csv.index'))
            ->assertOk();
    }

    public function test_general_can_open_index(): void
    {
        $this->actingAs($this->makeUser('general'))
            ->get(route('csv.index'))
            ->assertOk();
    }

    public function test_index_shares_full_user_list_for_legend_and_filters(): void
    {
        // フロントページ（Csv/Index.tsx）はフェーズ3で実装するため、存在チェックを無効化する
        config(['inertia.testing.ensure_pages_exist' => false]);

        $u1 = $this->makeUser('admin');
        $u2 = $this->makeUser('general');

        $this->actingAs($u1)
            ->get(route('csv.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Csv/Index')
                ->has('engineer_filter_options.users', 2) // admin/general 双方を含む全件
                ->has('engineer_filter_options.statuses')
                ->has('engineer_filter_options.work_styles')
                ->has('project_filter_options.users', 2)
            );
    }

    public function test_general_can_export_and_import(): void
    {
        $user = $this->makeUser('general');

        // export
        $this->actingAs($user)->get(route('csv.engineers.export'))->assertOk();

        // import（新規1行）
        $schema = new EngineerCsvSchema;
        $csv = $this->buildCsv($schema, [[
            'name' => '山田太郎', 'name_kana' => 'ヤマダタロウ', 'status' => 'proposable', 'main_user_id' => $user->id,
        ]]);

        $this->postImport($user, 'csv.engineers.import', $this->makeUpload($csv), false)
            ->assertRedirect(route('csv.index'))
            ->assertSessionHas('importResult');

        $this->assertDatabaseHas('engineers', ['name' => '山田太郎']);
    }
}
