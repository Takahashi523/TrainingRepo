<?php

namespace Tests\Feature;

use App\Models\FormFieldSetting;
use App\Models\User;
use Database\Seeders\FormFieldSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterFormSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function general(): User
    {
        return User::factory()->create(['role' => 'general']);
    }

    private function setting(array $overrides = []): FormFieldSetting
    {
        return FormFieldSetting::create(array_merge([
            'form_type' => 'engineer',
            'field_key' => 'birth_date',
            'is_required' => false,
            'is_system_required' => false,
        ], $overrides));
    }

    // -------------------------------------------------------
    // 認可
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->put('/master/form-settings', ['settings' => []])->assertRedirect('/login');
    }

    public function test_general_user_is_forbidden(): void
    {
        $this->actingAs($this->general())
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true],
                ],
            ])
            ->assertForbidden();
    }

    // -------------------------------------------------------
    // SSOT 同期（FIELD_LABELS ⇔ Seeder）
    // -------------------------------------------------------

    /**
     * FIELD_LABELS（表示名・表示順の SSOT）と、実際に Seeder が投入する field_key が一致することを保証する。
     * 将来フォーム項目を追加した際に片方だけ更新するズレは fieldLabel() の ?? フォールバックで
     * 画面が壊れず隠蔽され CI で気づけないため、機械的に検知する（PR #51 レビュー指摘）。
     */
    public function test_field_labels_matches_seeded_field_keys(): void
    {
        $this->seed(FormFieldSettingSeeder::class);

        foreach (['engineer', 'project'] as $formType) {
            $seededKeys = FormFieldSetting::where('form_type', $formType)->pluck('field_key')->all();
            $labelKeys = array_keys(FormFieldSetting::FIELD_LABELS[$formType]);

            $this->assertEqualsCanonicalizing(
                $seededKeys,
                $labelKeys,
                "form_type={$formType}: FIELD_LABELS と Seeder の field_key がズレています。",
            );
        }
    }

    /**
     * マスタ管理のフォーム設定一覧（案件）が、案件登録フォームのセクション・項目順で返ることを固定する（issue #43）。
     *
     * 期待値はここに直接書き下す。FIELD_LABELS から導出すると定数をどう並べ替えても通ってしまい、
     * 「フォームと同じ順に並んでいること」というこのテストの目的を満たさなくなるため。
     * Seeder はシステム必須（name / status / main_user_id）を先に投入するので DB の id 順とは一致せず、
     * このテストは MasterController::orderedSettings() が効いていることも同時に担保する。
     */
    public function test_project_form_settings_are_ordered_by_form_section(): void
    {
        $this->seed(FormFieldSettingSeeder::class);

        $expected = [
            // 基本情報
            'name', 'client_name', 'headcount', 'interview_count', 'start_date',
            // 契約条件
            'rate', 'billing_range', 'commercial_flow',
            // 勤務条件
            'work_style', 'work_location', 'remarks',
            // スキル要件
            'required_skills', 'preferred_skills', 'proc_experience',
            'negotiation_required', 'description', 'work_env',
            // 管理情報
            'status', 'main_user_id',
        ];

        $response = $this->actingAs($this->admin())->get('/master');
        $response->assertOk();

        // 順序ずれを diff で読めるようにするため assertInertia のクロージャではなく実配列を取り出して比較する。
        $actual = collect($response->viewData('page')['props']['form_settings']['project'])
            ->pluck('field_key')
            ->all();

        $this->assertSame(
            $expected,
            $actual,
            'マスタ管理（案件）の表示順が登録フォームのセクション順と一致していません。FormFieldSetting::FIELD_LABELS の並びを確認してください。',
        );
    }

    /**
     * マスタ管理のフォーム設定一覧（人材）が、人材登録フォームのセクション・項目順で返ることを固定する。
     *
     * FIELD_LABELS の docblock は engineer / project の両方に「フォームのセクション・項目順と一致させること」を
     * 課しているが、順序テストは project 側にしか無かった（PR #96 レビュー指摘）。
     * engineer 側も現時点で EngineerForm.tsx と一致しているため、一致しているうちに固定する。
     * 期待値を literal で書く理由・id 順と一致しない理由は上のテストと同じ。
     */
    public function test_engineer_form_settings_are_ordered_by_form_section(): void
    {
        $this->seed(FormFieldSettingSeeder::class);

        $expected = [
            // 基本情報
            'name', 'name_kana', 'birth_date', 'nearest_station', 'nearest_line', 'available_from',
            // スキル情報
            'skills', 'proc_experience', 'has_negotiation_exp',
            // 経歴・PR
            'appeal_note',
            // 希望条件
            'desired_rate', 'work_styles', 'remarks',
            // 管理情報
            'status', 'main_user_id',
        ];

        $response = $this->actingAs($this->admin())->get('/master');
        $response->assertOk();

        $actual = collect($response->viewData('page')['props']['form_settings']['engineer'])
            ->pluck('field_key')
            ->all();

        $this->assertSame(
            $expected,
            $actual,
            'マスタ管理（人材）の表示順が登録フォームのセクション順と一致していません。FormFieldSetting::FIELD_LABELS の並びを確認してください。',
        );
    }

    // -------------------------------------------------------
    // 更新
    // -------------------------------------------------------

    public function test_admin_can_toggle_field_and_records_updated_by(): void
    {
        $admin = $this->admin();
        $this->setting(['field_key' => 'birth_date', 'is_required' => false]);

        // 即時反映トグルはフラッシュ（トースト）を出さない（行内フィードバックに一本化）。
        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true],
                ],
            ])
            ->assertRedirect(route('master.index'))
            ->assertSessionMissing('success');

        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'engineer',
            'field_key' => 'birth_date',
            'is_required' => true,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_system_required_field_is_ignored(): void
    {
        $admin = $this->admin();
        $this->setting([
            'field_key' => 'name',
            'is_required' => true,
            'is_system_required' => true,
        ]);

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'name', 'is_required' => false],
                ],
            ])
            ->assertRedirect(route('master.index'));

        // 固定必須は変更されない
        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'engineer',
            'field_key' => 'name',
            'is_required' => true,
        ]);
    }

    public function test_nonexistent_field_key_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'not_a_field', 'is_required' => true],
                ],
            ])
            ->assertSessionHasErrors('settings.0.field_key');
    }

    public function test_invalid_form_type_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'unknown', 'field_key' => 'birth_date', 'is_required' => true],
                ],
            ])
            ->assertSessionHasErrors('settings.0.form_type');
    }

    public function test_can_update_engineer_and_project_settings_together(): void
    {
        $admin = $this->admin();
        $this->setting(['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => false]);
        $this->setting(['form_type' => 'project', 'field_key' => 'client_name', 'is_required' => false]);

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true],
                    ['form_type' => 'project', 'field_key' => 'client_name', 'is_required' => true],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true,
        ]);
        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'project', 'field_key' => 'client_name', 'is_required' => true,
        ]);
    }
}
