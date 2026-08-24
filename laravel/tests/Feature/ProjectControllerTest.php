<?php

namespace Tests\Feature;

use App\Models\FormFieldSetting;
use App\Models\Project;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------

    private function seedFormFieldSettings(array $overrides = []): void
    {
        $fields = [
            'client_name', 'required_skills', 'preferred_skills', 'rate',
            'start_date', 'work_style', 'work_location', 'commercial_flow',
            'interview_count', 'headcount', 'work_env', 'billing_range',
            'proc_experience', 'negotiation_required', 'description', 'remarks',
        ];
        foreach ($fields as $key) {
            FormFieldSetting::create([
                'form_type' => 'project',
                'field_key' => $key,
                'is_required' => $overrides[$key] ?? false,
                'is_system_required' => false,
            ]);
        }
    }

    private function validPayload(int $mainUserId): array
    {
        return [
            'name' => 'テスト案件',
            'status' => 'open',
            'main_user_id' => $mainUserId,
            'sub_user_id' => null,
        ];
    }

    /**
     * show/index/destroy 系のテスト用に Project を直接作成する
     * （ProjectFactory が存在しないため最小限の属性で作成）
     */
    private function createProject(array $overrides = []): Project
    {
        $mainUser = $overrides['main_user_id'] ?? User::factory()->create()->id;

        return Project::create(array_merge([
            'name' => 'テスト案件',
            'status' => 'open',
            'main_user_id' => $mainUser,
        ], $overrides));
    }

    // -------------------------------------------------------
    // create: GET /projects/create
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_create_page(): void
    {
        $response = $this->get('/projects/create');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_create_page(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Projects/Create'));
    }

    public function test_create_page_props_contain_required_keys(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/create');

        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Create')
            ->has('fieldSettings')
            ->has('phases')
            ->has('work_styles')
            ->has('commercial_flows')
            ->has('statuses')
            ->has('users')
        );
    }

    public function test_field_settings_reflect_is_required_true(): void
    {
        $this->seedFormFieldSettings(['description' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/create');

        $response->assertInertia(fn ($page) => $page
            ->where('fieldSettings.description.is_required', true)
            ->where('fieldSettings.remarks.is_required', false)
        );
    }

    public function test_field_settings_default_to_not_required_when_no_db_record(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('fieldSettings.description.is_required', false)
        );
    }

    // -------------------------------------------------------
    // store: POST /projects — 正常系
    // -------------------------------------------------------

    public function test_guest_cannot_post_to_store(): void
    {
        $response = $this->post('/projects', []);

        $response->assertRedirect('/login');
    }

    public function test_project_is_stored_with_minimum_required_fields(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload($user->id));

        $project = Project::where('name', 'テスト案件')->first();
        $response->assertRedirect("/projects/{$project->id}");
        $this->assertDatabaseHas('projects', [
            'name' => 'テスト案件',
            'status' => 'open',
            'main_user_id' => $user->id,
        ]);
    }

    public function test_store_sets_success_flash_message(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/projects', $this->validPayload($user->id));

        $response->assertSessionHas('success', '案件情報を登録しました。');
    }

    public function test_required_skills_are_stored_in_project_skills_table(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => 'PHP',  'detail' => 'Laravel 5年以上'],
                ['label' => 'Vue',  'detail' => null],
            ],
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $project = Project::where('name', 'テスト案件')->first();
        $this->assertNotNull($project);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'required',
            'label' => 'PHP',
            'detail' => 'Laravel 5年以上',
        ]);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'required',
            'label' => 'Vue',
            'detail' => null,
        ]);
    }

    public function test_preferred_skills_are_stored_in_project_skills_table(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'preferred_skills' => [
                ['label' => 'Docker', 'detail' => null],
            ],
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $project = Project::where('name', 'テスト案件')->first();
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'preferred',
            'label' => 'Docker',
        ]);
    }

    public function test_sub_user_id_accepts_null(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/projects', $this->validPayload($user->id));

        $this->assertDatabaseHas('projects', ['sub_user_id' => null]);
    }

    public function test_sub_user_id_accepts_existing_user(): void
    {
        $this->seedFormFieldSettings();
        $mainUser = User::factory()->create();
        $subUser = User::factory()->create();

        $payload = array_merge($this->validPayload($mainUser->id), ['sub_user_id' => $subUser->id]);
        $this->actingAs($mainUser)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', ['sub_user_id' => $subUser->id]);
    }

    public function test_rate_negotiable_stores_null_for_rate_min_and_max(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note' => 'スキル見合い',
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => 'スキル見合い',
        ]);
    }

    public function test_rate_negotiable_sets_default_rate_note_when_empty(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note' => null,  // 未入力
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => 'スキル見合い',  // デフォルト値が入る
        ]);
    }

    /**
     * rate_noteにデフォルト値（スキル見合い）とは異なる文字列を入力した場合、
     * 「未入力なら補完」のロジックで上書きされず、入力した文言がそのまま保存されることを確認する。
     */
    public function test_rate_negotiable_stores_custom_rate_note_as_is(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note' => '応相談',
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => '応相談',
        ]);
    }

    /**
     * フォーム側は「スキル見合い」チェックON/OFF時にrate_min/rate_maxの値をクリアしなくなったため、
     * 画面上は値が残ったまま送信されうる。その場合でもサーバー側で必ずnullにされることを確認する。
     */
    public function test_rate_negotiable_stores_null_even_when_rate_min_and_max_are_present_in_payload(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_min' => 60,
            'rate_max' => 80,
            'rate_note' => null, // チェックON直後は未入力（実際の画面操作を再現）
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => 'スキル見合い', // 未入力時のデフォルト値が入る
        ]);
    }

    /**
     * rate_is_negotiableがfalse（通常の数値入力モード）のとき、rate_noteは
     * 画面上も入力欄自体が表示されないフィールドのため、送信内容に古い値が
     * 残っていたとしても必ずnullで保存されることを確認する。
     */
    public function test_rate_note_is_nulled_when_not_negotiable_even_if_value_is_present(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min' => 60,
            'rate_max' => 80,
            'rate_note' => 'スキル見合い', // 以前スキル見合いだった際の残留値を想定
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min' => 60,
            'rate_max' => 80,
            'rate_note' => null,
        ]);
    }

    public function test_proc_fields_are_stored_as_boolean(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'proc_requirements' => true,
            'proc_basic_design' => false,
            'proc_detail_design' => false,
            'proc_development' => true,
            'proc_testing' => false,
            'proc_maintenance' => false,
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'proc_requirements' => 1,
            'proc_development' => 1,
            'proc_basic_design' => 0,
            'proc_detail_design' => 0,
            'proc_testing' => 0,
            'proc_maintenance' => 0,
        ]);
    }

    // -------------------------------------------------------
    // store: POST /projects — バリデーション（固定必須フィールド）
    // -------------------------------------------------------

    public function test_name_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'name' => str_repeat('あ', 256),
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_status_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['status']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_status_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['status' => 'invalid']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('status');
    }

    /**
     * #67：数値欄をテキスト化したため非数値文字列がそのままサーバへ届く。
     * サーバ FormRequest（integer）が弾き、サイレント保存されないことをロックする。
     */
    public function test_headcount_rejects_non_numeric_value(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['headcount' => 'あ']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('headcount');
        $this->assertDatabaseCount('projects', 0);
    }

    /**
     * #67：日付欄をテキスト化したため実在しない日付文字列がそのままサーバへ届く。
     * サーバ FormRequest（date）が弾き、サイレント保存されないことをロックする。
     */
    public function test_start_date_rejects_invalid_date(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['start_date' => '2026-02-30']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('start_date');
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_main_user_id_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['main_user_id']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_main_user_id_must_exist_in_users_table(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['main_user_id' => 99999]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_differ_from_main_user_id(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_sub_user_id_must_exist_in_users_table(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => 99999]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    // -------------------------------------------------------
    // store: POST /projects — バリデーション（動的フィールド）
    // -------------------------------------------------------

    public function test_dynamic_field_is_required_when_form_field_setting_is_true(): void
    {
        $this->seedFormFieldSettings(['description' => true]);
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('description');
    }

    public function test_dynamic_field_is_nullable_when_form_field_setting_is_false(): void
    {
        $this->seedFormFieldSettings(['description' => false]);
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('description');
    }

    public function test_negotiation_required_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'negotiation_required' => 'invalid',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('negotiation_required');
    }

    public function test_proc_fields_are_required_when_proc_experience_setting_is_true(): void
    {
        $this->seedFormFieldSettings(['proc_experience' => true]);
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('proc_requirements');
        $response->assertSessionHasErrors('proc_development');
    }

    public function test_rate_min_is_required_when_rate_setting_is_true_and_not_negotiable(): void
    {
        $this->seedFormFieldSettings(['rate' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('rate_min');
        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_min_and_max_are_not_required_when_negotiable(): void
    {
        $this->seedFormFieldSettings(['rate' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('rate_min');
        $response->assertSessionDoesntHaveErrors('rate_max');
    }

    public function test_rate_min_must_be_less_than_or_equal_to_rate_max(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min' => 80,
            'rate_max' => 60, // rate_min > rate_max
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('rate_min');
        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_max_is_required_when_rate_min_is_filled(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'rate_min' => 50,
            'rate_max' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('rate_max');
    }

    public function test_work_location_station_is_required_when_work_style_is_onsite(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'onsite',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_station_is_required_when_work_style_is_hybrid(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'hybrid',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_station_is_not_required_when_work_style_is_remote(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'remote',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('work_location_station');
    }

    public function test_work_location_line_is_required_when_work_location_setting_is_true_and_work_style_is_onsite(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'onsite',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_line');
    }

    public function test_work_location_line_is_not_required_when_work_style_is_remote(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'remote',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('work_location_line');
    }

    public function test_required_skills_is_required_when_form_field_setting_is_true(): void
    {
        $this->seedFormFieldSettings(['required_skills' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [],
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('required_skills');
    }

    public function test_skill_label_is_required_when_detail_is_present(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => null, 'detail' => '詳細テキスト'],
            ],
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('required_skills.0.label');
    }

    public function test_skill_label_max_length_is_15(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => str_repeat('あ', 16), 'detail' => null],
            ],
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('required_skills.0.label');
    }

    public function test_skill_label_is_required_when_required_skills_setting_is_true(): void
    {
        $this->seedFormFieldSettings(['required_skills' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => null, 'detail' => null],
            ],
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('required_skills.0.label');
    }

    public function test_rate_min_is_required_when_rate_max_is_filled(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'rate_min' => null,
            'rate_max' => 80,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('rate_min');
    }

    public function test_commercial_flow_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'commercial_flow' => 'invalid',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('commercial_flow');
    }

    public function test_work_style_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'invalid',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_style');
    }

    // -------------------------------------------------------
    // index: GET /projects
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_index_page(): void
    {
        $response = $this->get('/projects');

        $response->assertRedirect('/login');
    }

    public function test_index_page_renders_with_expected_props(): void
    {
        $user = User::factory()->create();
        $this->createProject(['name' => 'A案件', 'main_user_id' => $user->id]);
        $this->createProject(['name' => 'B案件', 'main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Index')
            ->has('projects.data', 2)
            ->has('projects.meta')
            ->where('projects.meta.per_page', 20)
            ->has('filters')
            ->has('statusOptions')
            ->has('workStyleOptions')
            ->has('commercialFlowOptions')
            ->has('interviewCountOptions')
            ->has('sortOptions')
        );
    }

    public function test_index_saved_searches_only_include_project_type_for_current_user(): void
    {
        // EngineerController@index とコピペで search_type を取り違えやすい箇所の回帰防止
        // （実装時に一度 'engineer' が誤って指定されていたのを本テストで固定する）。
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $projectSearch = SavedSearch::create([
            'user_id' => $user->id, 'name' => '自分のproject検索', 'search_type' => 'project',
            'conditions' => ['status' => [], 'work_style' => [], 'commercial_flow' => [], 'interview_count' => [], 'keyword' => '', 'sort' => '', 'order' => ''],
        ]);
        SavedSearch::create([
            'user_id' => $user->id, 'name' => '自分のengineer検索', 'search_type' => 'engineer',
            'conditions' => ['status' => [], 'work_styles' => [], 'phases' => [], 'keyword' => '', 'sort' => '', 'order' => ''],
        ]);
        SavedSearch::create([
            'user_id' => $otherUser->id, 'name' => '他人のproject検索', 'search_type' => 'project',
            'conditions' => ['status' => [], 'work_style' => [], 'commercial_flow' => [], 'interview_count' => [], 'keyword' => '', 'sort' => '', 'order' => ''],
        ]);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertInertia(fn ($page) => $page
            ->count('savedSearches', 1)
            ->where('savedSearches.0.id', $projectSearch->id)
            ->where('savedSearches.0.name', '自分のproject検索')
        );
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'pending']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'closed']);

        $response = $this->actingAs($user)->get('/projects?status[]=open');

        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.status', 'open')
        );
    }

    public function test_index_filters_status_with_or_logic(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'pending']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'closed']);

        $response = $this->actingAs($user)
            ->get('/projects?status[]=open&status[]=pending');

        // open OR pending の2件のみヒット（closed は除外）
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 2)
            ->where('projects.data', fn ($data) => collect($data)
                ->pluck('status')->sort()->values()->all() === ['open', 'pending'])
        );
    }

    public function test_index_filters_by_work_style_with_or_logic(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'work_style' => 'onsite']);
        $this->createProject(['main_user_id' => $user->id, 'work_style' => 'remote']);
        $this->createProject(['main_user_id' => $user->id, 'work_style' => 'hybrid']);

        $response = $this->actingAs($user)
            ->get('/projects?work_style[]=onsite&work_style[]=remote');

        // onsite OR remote の2件のみヒット（hybrid は除外）
        $response->assertInertia(fn ($page) => $page->count('projects.data', 2));
    }

    public function test_index_filters_by_commercial_flow(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'commercial_flow' => 'prime']);
        $this->createProject(['main_user_id' => $user->id, 'commercial_flow' => 'secondary']);

        $response = $this->actingAs($user)->get('/projects?commercial_flow[]=prime');

        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.commercial_flow', 'prime')
        );
    }

    public function test_index_filters_by_interview_count(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 1]);
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 2]);

        $response = $this->actingAs($user)->get('/projects?interview_count[]=1');

        // value=1・2は完全一致
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.interview_count', 1)
        );
    }

    public function test_index_filters_by_interview_count_3_or_more_includes_higher_counts(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 2]);
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 3]);
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 5]);

        $response = $this->actingAs($user)->get('/projects?interview_count[]=3');

        // value=3（「3回以上」）は3以上すべてにヒットする。interview_count=2は除外。
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 2)
            ->where('projects.data', fn ($data) => collect($data)
                ->pluck('interview_count')->sort()->values()->all() === [3, 5])
        );
    }

    public function test_index_filters_by_interview_count_combines_exact_and_or_more_with_or_logic(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 1]);
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 2]);
        $this->createProject(['main_user_id' => $user->id, 'interview_count' => 4]);

        $response = $this->actingAs($user)
            ->get('/projects?interview_count[]=1&interview_count[]=3');

        // 「1回」OR「3回以上」：interview_count=1と4がヒット、2は除外
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 2)
            ->where('projects.data', fn ($data) => collect($data)
                ->pluck('interview_count')->sort()->values()->all() === [1, 4])
        );
    }

    public function test_index_interview_count_or_more_does_not_leak_past_other_filters(): void
    {
        $user = User::factory()->create();
        // status=open かつ interview_count>=3 の1件のみヒットさせたい
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open', 'interview_count' => 3]);
        // status違いなので、interview_count>=3の条件を満たしてもヒットしてはいけない
        $this->createProject(['main_user_id' => $user->id, 'status' => 'closed', 'interview_count' => 5]);

        $response = $this->actingAs($user)
            ->get('/projects?status[]=open&interview_count[]=3');

        // closureで条件をグループ化しているため、OR条件がstatusのAND条件の外に漏れ出さないことを確認
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.status', 'open')
        );
    }

    public function test_index_combines_different_filter_types_with_and_logic(): void
    {
        $user = User::factory()->create();
        // AND条件確認用：status一致・work_style不一致のパターンを混在させる
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open', 'work_style' => 'remote']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open', 'work_style' => 'onsite']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'closed', 'work_style' => 'remote']);

        $response = $this->actingAs($user)
            ->get('/projects?status[]=open&work_style[]=remote');

        // status=open かつ work_style=remote の1件のみ（異なる項目間はAND条件）
        $response->assertInertia(fn ($page) => $page->count('projects.data', 1));
    }

    public function test_index_ignores_invalid_filter_values(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open']);
        $this->createProject(['main_user_id' => $user->id, 'status' => 'closed']);

        // 未定義の値はホワイトリスト(array_intersect)で除外され、絞り込み条件なし
        // （フィルタ未適用＝全件対象）として扱われるべき。エラーにもならない。
        $response = $this->actingAs($user)->get('/projects?status[]=invalid_value');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 2)
            ->where('filters.status', [])
        );
    }

    public function test_index_searches_keyword_by_name(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => '金融システム開発']);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'ECサイト構築']);

        $response = $this->actingAs($user)->get('/projects?keyword=金融');

        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.name', '金融システム開発')
        );
    }

    public function test_index_searches_keyword_by_skill_prefix(): void
    {
        $user = User::factory()->create();
        $p1 = $this->createProject(['main_user_id' => $user->id, 'name' => 'A案件']);
        $p1->projectSkills()->create(['skill_type' => 'required', 'label' => 'JavaScript', 'detail' => null]);

        $p2 = $this->createProject(['main_user_id' => $user->id, 'name' => 'B案件']);
        $p2->projectSkills()->create(['skill_type' => 'required', 'label' => 'Python', 'detail' => null]);

        $response = $this->actingAs($user)->get('/projects?keyword=Java');

        // スキル前方一致：JavaScript はヒット、Python は非ヒット
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.name', 'A案件')
        );
    }

    public function test_index_does_not_search_skill_by_partial_match(): void
    {
        $user = User::factory()->create();
        // 案件名は 'Native' を含まない。スキルは前方一致のみのため 'ReactNative' は 'Native' で非ヒットになるべき。
        $p = $this->createProject(['main_user_id' => $user->id, 'name' => 'C案件']);
        $p->projectSkills()->create(['skill_type' => 'required', 'label' => 'ReactNative', 'detail' => null]);

        $response = $this->actingAs($user)->get('/projects?keyword=Native');

        $response->assertInertia(fn ($page) => $page->count('projects.data', 0));
    }

    public function test_index_searches_keyword_by_name_or_skill(): void
    {
        $user = User::factory()->create();

        // A：案件名で部分一致（%Ruby%）／スキルは無関係
        $a = $this->createProject(['main_user_id' => $user->id, 'name' => 'RubyDev案件']);
        $a->projectSkills()->create(['skill_type' => 'required', 'label' => 'COBOL', 'detail' => null]);

        // B：案件名は非ヒット／スキルで前方一致（Ruby%）
        $b = $this->createProject(['main_user_id' => $user->id, 'name' => 'Sato案件']);
        $b->projectSkills()->create(['skill_type' => 'preferred', 'label' => 'Ruby', 'detail' => null]);

        // C（対照）：案件名・スキルとも非ヒット
        $c = $this->createProject(['main_user_id' => $user->id, 'name' => 'Tanaka案件']);
        $c->projectSkills()->create(['skill_type' => 'required', 'label' => 'Java', 'detail' => null]);

        $response = $this->actingAs($user)->get('/projects?keyword=Ruby');

        // 案件名一致(A) と スキル一致(B) の OR が同一クエリで両立し、両方ヒット・C は除外
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 2)
            ->where('projects.data', fn ($data) => collect($data)
                ->pluck('name')->sort()->values()->all() === ['RubyDev案件', 'Sato案件'])
        );
    }

    public function test_index_escapes_backslash_in_keyword(): void
    {
        // LIKE のバックスラッシュエスケープは DB の「LIKE 既定エスケープ文字」に依存する。
        // 本番の MySQL は既定エスケープが '\' のため、パターン中の '\\' は \ リテラルにマッチする。
        // 一方テスト用の SQLite は ESCAPE 句なしでは '\' をエスケープ扱いしない検証は本番と同じ MySQL 接続でのみ意味を持つ。
        if (\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('LIKE のバックスラッシュエスケープ検証は MySQL でのみ有効（SQLite は LIKE の既定エスケープを持たない）。');
        }

        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => 'a\\b案件']);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'ab案件']);

        $response = $this->actingAs($user)->get('/projects?keyword='.urlencode('a\\b'));

        // バックスラッシュがリテラルとして扱われ、'a\b案件' のみヒット（'ab案件' は非ヒット）
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.name', 'a\\b案件')
        );
    }

    public function test_index_accepts_keyword_at_max_length_255(): void
    {
        $user = User::factory()->create();

        // 255文字（案件名 max:255 と同上限）は許容される
        $keyword = str_repeat('a', 255);
        $response = $this->actingAs($user)->get('/projects?keyword='.$keyword);

        $response->assertOk();
        $response->assertSessionHasNoErrors();
        $response->assertInertia(fn ($page) => $page->where('filters.keyword', $keyword));
    }

    public function test_index_rejects_keyword_over_255(): void
    {
        $user = User::factory()->create();

        // 256文字はサーバ側 FormRequest で弾く（フロント maxLength の安全網）
        $response = $this->actingAs($user)->get('/projects?keyword='.str_repeat('a', 256));

        $response->assertSessionHasErrors('keyword');
    }

    public function test_index_does_not_search_keyword_by_description(): void
    {
        $user = User::factory()->create();
        $this->createProject([
            'main_user_id' => $user->id,
            'name' => 'A案件',
            'description' => 'バックエンド開発が中心の業務内容です',
        ]);
        $this->createProject([
            'main_user_id' => $user->id,
            'name' => 'B案件',
            'description' => 'デザイン業務が中心です',
        ]);

        $response = $this->actingAs($user)->get('/projects?keyword=バックエンド');

        // 業務内容詳細（description）は検索対象外のため0件
        $response->assertInertia(fn ($page) => $page->count('projects.data', 0));
    }

    public function test_index_keyword_filter_does_not_leak_past_other_filters(): void
    {
        $user = User::factory()->create();
        // status=open かつ 案件名に「金融」を含む1件のみヒットさせたい
        $this->createProject(['main_user_id' => $user->id, 'status' => 'open', 'name' => '金融システム開発']);
        // status違いなので、名前が一致してもヒットしてはいけない
        $this->createProject(['main_user_id' => $user->id, 'status' => 'closed', 'name' => '金融システム保守']);

        $response = $this->actingAs($user)
            ->get('/projects?status[]=open&keyword=金融');

        // closureで条件をグループ化しているため、keywordのOR条件がstatusのAND条件の外に漏れ出さないことを確認
        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 1)
            ->where('projects.data.0.status', 'open')
        );
    }

    public function test_index_default_sort_is_created_at_desc(): void
    {
        $user = User::factory()->create();
        $old = $this->createProject(['main_user_id' => $user->id, 'name' => '古い']);
        $old->created_at = now()->subDays(5);
        $old->save();
        $new = $this->createProject(['main_user_id' => $user->id, 'name' => '新しい']);
        $new->created_at = now();
        $new->save();

        $response = $this->actingAs($user)->get('/projects');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', '新しい')
            ->where('projects.data.1.name', '古い')
        );
    }

    public function test_index_sort_by_start_date_places_nulls_last(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => 'A', 'start_date' => '2026-08-01']);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'B', 'start_date' => null]);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'C', 'start_date' => '2026-07-01']);

        $response = $this->actingAs($user)->get('/projects?sort=start_date&order=asc');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', 'C')   // 2026-07-01
            ->where('projects.data.1.name', 'A')   // 2026-08-01
            ->where('projects.data.2.name', 'B')   // NULL（未定）は末尾
        );
    }

    public function test_index_sort_by_rate_max_places_nulls_last(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => 'A', 'rate_min' => 50, 'rate_max' => 60]);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'B', 'rate_min' => null, 'rate_max' => null]);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'C', 'rate_min' => 70, 'rate_max' => 90]);

        $response = $this->actingAs($user)->get('/projects?sort=rate_max&order=desc');

        // 高い順（desc）でも、単価未設定（NULL）は order の向きにかかわらず常に末尾に来るべき
        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', 'C')   // rate_max=90
            ->where('projects.data.1.name', 'A')   // rate_max=60
            ->where('projects.data.2.name', 'B')   // NULL は末尾
        );
    }

    public function test_index_sort_by_rate_max_desc_tiebreaks_by_rate_min_desc(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => 'A', 'rate_min' => 50, 'rate_max' => 100]);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'B', 'rate_min' => 80, 'rate_max' => 100]);

        $response = $this->actingAs($user)->get('/projects?sort=rate_max&order=desc');

        // rate_maxが同率(100)の場合、rate_maxと同じ向き（高い順）でrate_minも高い順に並ぶべき
        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', 'B')   // rate_min=80
            ->where('projects.data.1.name', 'A')   // rate_min=50
        );
    }

    public function test_index_sort_by_rate_max_asc_tiebreaks_by_rate_min_asc(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => 'A', 'rate_min' => 50, 'rate_max' => 100]);
        $this->createProject(['main_user_id' => $user->id, 'name' => 'B', 'rate_min' => 80, 'rate_max' => 100]);

        $response = $this->actingAs($user)->get('/projects?sort=rate_max&order=asc');

        // rate_maxが同率(100)の場合、rate_maxと同じ向き（低い順）でrate_minも低い順に並ぶべき
        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', 'A')   // rate_min=50
            ->where('projects.data.1.name', 'B')   // rate_min=80
        );
    }

    public function test_index_sort_by_rate_max_separates_negotiable_from_unset_in_null_bucket(): void
    {
        // rate_max/rate_minが揃ってNULLになる末尾バケット内で、
        // 「スキル見合い（rate_noteあり）→単価未設定（rate_noteなし）」の順に固定されることを確認する
        // （PRレビュー指摘：数値案件・スキル見合い・単価未設定の3種混在ケース）
        $user = User::factory()->create();
        $this->createProject([
            'main_user_id' => $user->id, 'name' => '数値案件',
            'rate_min' => 50, 'rate_max' => 100, 'rate_note' => null,
        ]);
        $this->createProject([
            'main_user_id' => $user->id, 'name' => 'スキル見合い案件',
            'rate_min' => null, 'rate_max' => null, 'rate_note' => 'スキル見合い',
        ]);
        $this->createProject([
            'main_user_id' => $user->id, 'name' => '単価未設定案件',
            'rate_min' => null, 'rate_max' => null, 'rate_note' => null,
        ]);

        // 高い順・低い順のどちらでも「見合い→未設定」の順は変わらない（$order非依存）
        $descResponse = $this->actingAs($user)->get('/projects?sort=rate_max&order=desc');
        $descResponse->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', '数値案件')
            ->where('projects.data.1.name', 'スキル見合い案件')
            ->where('projects.data.2.name', '単価未設定案件')
        );

        $ascResponse = $this->actingAs($user)->get('/projects?sort=rate_max&order=asc');
        $ascResponse->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', '数値案件')
            ->where('projects.data.1.name', 'スキル見合い案件')
            ->where('projects.data.2.name', '単価未設定案件')
        );
    }

    public function test_index_sort_by_updated_at_desc(): void
    {
        $user = User::factory()->create();
        $p1 = $this->createProject(['main_user_id' => $user->id, 'name' => '古い更新']);
        $p1->updated_at = now()->subDays(3);
        $p1->saveQuietly();
        $p2 = $this->createProject(['main_user_id' => $user->id, 'name' => '新しい更新']);
        $p2->updated_at = now();
        $p2->saveQuietly();

        $response = $this->actingAs($user)->get('/projects?sort=updated_at&order=desc');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', '新しい更新')
            ->where('projects.data.1.name', '古い更新')
        );
    }

    public function test_index_tiebreak_by_id_asc_when_sort_key_is_equal(): void
    {
        $user = User::factory()->create();
        $now = now()->toDateTimeString();

        // created_at を同値に揃えて id の昇順が効くことを確認する
        $p1 = $this->createProject(['main_user_id' => $user->id, 'name' => '先に登録']);
        $p2 = $this->createProject(['main_user_id' => $user->id, 'name' => '後に登録']);
        \DB::table('projects')->whereIn('id', [$p1->id, $p2->id])->update(['created_at' => $now]);

        $response = $this->actingAs($user)->get('/projects?sort=created_at&order=desc');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', '先に登録')   // id が小さい方が先
            ->where('projects.data.1.name', '後に登録')
        );
    }

    public function test_index_invalid_sort_key_falls_back_to_created_at_desc(): void
    {
        $user = User::factory()->create();
        $old = $this->createProject(['main_user_id' => $user->id, 'name' => '古い']);
        $old->created_at = now()->subDays(5);
        $old->saveQuietly();
        $this->createProject(['main_user_id' => $user->id, 'name' => '新しい']);

        // sort が無効なので created_at DESC にフォールバック → 新しい方が先
        $response = $this->actingAs($user)->get('/projects?sort=invalid_key');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.name', '新しい')
            ->where('filters.sort', 'created_at')
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_invalid_order_falls_back_to_desc(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects?sort=created_at&order=INVALID');

        $response->assertInertia(fn ($page) => $page->where('filters.order', 'desc'));
    }

    public function test_index_disallowed_sort_order_pair_falls_back_to_default(): void
    {
        $user = User::factory()->create();

        // start_date:desc は SORT_OPTIONS に存在しない仕様外の組み合わせ（許可は start_date:asc のみ）。
        $response = $this->actingAs($user)->get('/projects?sort=start_date&order=desc');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'created_at')
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_provides_sort_options_from_backend_as_ssot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects');

        // 許可組はバックエンド一本化（SSOT）。DB設計書§8 の6組＋labelが props で渡り、先頭がデフォルト。
        $response->assertInertia(fn ($page) => $page
            ->has('sortOptions', 6)
            ->where('sortOptions.0.sort', 'created_at')
            ->where('sortOptions.0.order', 'desc')
            ->where('sortOptions.0.label', '登録日順（新しい順）')
            ->where('sortOptions.5.sort', 'rate_max')
            ->where('sortOptions.5.order', 'asc')
        );
    }

    public function test_index_paginates_with_per_page(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->createProject(['main_user_id' => $user->id]);
        }

        $response = $this->actingAs($user)->get('/projects?per_page=2');

        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 2)
            ->where('projects.meta.total', 5)
            ->where('projects.meta.per_page', 2)
            ->where('projects.meta.last_page', 3)
        );
    }

    public function test_index_clamps_per_page_to_max_100(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects?per_page=500');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.meta.per_page', 100)
            ->where('filters.per_page', 100)
        );
    }

    public function test_index_clamps_per_page_to_min_1(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects?per_page=0');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.meta.per_page', 1)
            ->where('filters.per_page', 1)
        );
    }

    public function test_index_returns_empty_when_no_match(): void
    {
        $user = User::factory()->create();
        $this->createProject(['main_user_id' => $user->id, 'name' => '案件A']);

        $response = $this->actingAs($user)->get('/projects?keyword=該当なしのキーワード');

        $response->assertInertia(fn ($page) => $page
            ->count('projects.data', 0)
            ->where('projects.meta.total', 0)
        );
    }

    public function test_index_echoes_query_params_into_filters(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(
            '/projects?status[]=open&work_style[]=remote&commercial_flow[]=prime&interview_count[]=1'
            .'&keyword=Java&sort=start_date&order=asc&per_page=10&page=1'
        );

        $response->assertInertia(fn ($page) => $page
            ->where('filters.status', ['open'])
            ->where('filters.work_style', ['remote'])
            ->where('filters.commercial_flow', ['prime'])
            ->where('filters.interview_count', [1])
            ->where('filters.keyword', 'Java')
            ->where('filters.sort', 'start_date')
            ->where('filters.order', 'asc')
            ->where('filters.per_page', 10)
        );
    }

    public function test_index_does_not_return_excluded_columns(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject([
            'main_user_id' => $user->id,
            'description' => 'これは表示されないはず',
            'work_env' => '稼働環境も表示されないはず',
            'remarks' => '特記事項も表示されないはず',
            'billing_range' => '精算幅も表示されないはず',
            'work_location_line' => '勤務地の路線も一覧では非表示',
            'work_location_station' => '最寄駅も一覧では非表示',
        ]);
        $project->projectSkills()->create(['skill_type' => 'required', 'label' => 'PHP', 'detail' => 'Laravel 5年']);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertInertia(fn ($page) => $page
            ->missing('projects.data.0.description')
            ->missing('projects.data.0.work_env')
            ->missing('projects.data.0.remarks')
            ->missing('projects.data.0.billing_range')
            ->missing('projects.data.0.work_location_line')
            ->missing('projects.data.0.work_location_station')
            ->where('projects.data.0.required_skills.0.label', 'PHP')
            ->missing('projects.data.0.required_skills.0.detail')
        );
    }

    public function test_index_returns_main_and_sub_user(): void
    {
        // main_user_id/sub_user_id をselectし忘れるとmainUserがnullになりクラッシュしていた不具合の回帰テスト
        $mainUser = User::factory()->create(['name' => '田中太郎']);
        $subUser = User::factory()->create(['name' => '鈴木花子']);
        $this->createProject([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $subUser->id,
        ]);

        $response = $this->actingAs($mainUser)->get('/projects');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.users.main.name', '田中太郎')
            ->where('projects.data.0.users.sub.name', '鈴木花子')
        );
    }

    public function test_index_returns_null_sub_user_when_not_assigned(): void
    {
        // sub_user_id未設定の案件で、users.subがnullとして返ることを確認
        $mainUser = User::factory()->create();
        $this->createProject([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => null,
        ]);

        $response = $this->actingAs($mainUser)->get('/projects');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('projects.data.0.users.sub', null)
        );
    }

    public function test_index_splits_skills_by_required_and_preferred(): void
    {
        // eager load時にskill_typeをselectし忘れると必須/尚可が両方とも空配列になっていた不具合の回帰テスト
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->create(['skill_type' => 'required', 'label' => 'Java', 'detail' => null]);
        $project->projectSkills()->create(['skill_type' => 'preferred', 'label' => 'AWS', 'detail' => null]);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertInertia(fn ($page) => $page
            ->count('projects.data.0.required_skills', 1)
            ->where('projects.data.0.required_skills.0.label', 'Java')
            ->count('projects.data.0.preferred_skills', 1)
            ->where('projects.data.0.preferred_skills.0.label', 'AWS')
        );
    }

    public function test_index_eager_loads_relations_to_avoid_n_plus_one(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $project = $this->createProject(['main_user_id' => $user->id]);
            $project->projectSkills()->create(['skill_type' => 'required', 'label' => 'PHP', 'detail' => null]);
            $project->projectSkills()->create(['skill_type' => 'preferred', 'label' => 'Vue', 'detail' => null]);
        }

        \DB::enableQueryLog();

        $this->actingAs($user)->get('/projects');

        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        // セッション・ユーザー・count・projects本体・projectSkills・mainUser・subUser を含む。
        // N+1 が起きた場合は 5件 × 3リレーション分で大きく増えるので 20 件以内で十分検出できる
        $this->assertLessThanOrEqual(20, count($queries));
    }

    // -------------------------------------------------------
    // show: GET /projects/{project}
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_show_page(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->get("/projects/{$project->id}");

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_show_page(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['name' => 'テスト案件', 'main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->where('project.id', $project->id)
            ->where('project.name', 'テスト案件')
        );
    }

    public function test_show_page_contains_all_resource_scalar_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject([
            'main_user_id' => $user->id,
            'client_name' => 'テスト商事',
            'start_date' => '2026-08-01',
            'rate_min' => 60,
            'rate_max' => 80,
            'commercial_flow' => 'prime',
            'work_style' => 'onsite',
            'work_location_line' => 'JR山手線',
            'work_location_station' => '渋谷',
            'interview_count' => 2,
            'headcount' => 3,
            'negotiation_required' => true,
            'description' => '業務内容詳細のテキスト',
            'work_env' => '稼働環境のテキスト',
            'billing_range' => '精算幅140-180',
        ]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->where('project.client_name', 'テスト商事')
            ->where('project.start_date', '2026-08-01')
            ->where('project.start_label', '2026/08/01〜')
            ->where('project.rate_min', 60)
            ->where('project.rate_max', 80)
            ->where('project.commercial_flow', 'prime')
            ->where('project.work_style', 'onsite')
            ->where('project.work_location_line', 'JR山手線')
            ->where('project.work_location_station', '渋谷')
            ->where('project.interview_count', 2)
            ->where('project.headcount', 3)
            ->where('project.negotiation_required', true)
            ->where('project.description', '業務内容詳細のテキスト')
            ->where('project.work_env', '稼働環境のテキスト')
            ->where('project.billing_range', '精算幅140-180')
        );
    }

    public function test_show_returns_404_for_non_existent_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/99999');

        $response->assertNotFound();
    }

    public function test_show_page_contains_main_user_information(): void
    {
        $mainUser = User::factory()->create(['name' => '担当太郎']);
        $project = $this->createProject(['main_user_id' => $mainUser->id]);

        $response = $this->actingAs($mainUser)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('project.users.main.id', $mainUser->id)
            ->where('project.users.main.name', '担当太郎')
        );
    }

    public function test_show_page_sub_user_is_null_when_not_set(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'sub_user_id' => null]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('project.users.sub', null)
        );
    }

    public function test_show_page_contains_sub_user_information_when_set(): void
    {
        $mainUser = User::factory()->create();
        $subUser = User::factory()->create(['name' => '副担当花子']);
        $project = $this->createProject(['main_user_id' => $mainUser->id, 'sub_user_id' => $subUser->id]);

        $response = $this->actingAs($mainUser)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('project.users.sub.id', $subUser->id)
            ->where('project.users.sub.name', '副担当花子')
        );
    }

    public function test_show_page_separates_required_and_preferred_skills(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->createMany([
            ['skill_type' => 'required',  'label' => 'PHP',    'detail' => 'Laravel 5年以上'],
            ['skill_type' => 'preferred', 'label' => 'Docker', 'detail' => null],
        ]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('project.required_skills', 1)
            ->where('project.required_skills.0.label', 'PHP')
            ->where('project.required_skills.0.detail', 'Laravel 5年以上')
            ->has('project.preferred_skills', 1)
            ->where('project.preferred_skills.0.label', 'Docker')
        );
    }

    public function test_show_page_skills_are_empty_arrays_when_none_registered(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('project.required_skills', 0)
            ->has('project.preferred_skills', 0)
        );
    }

    public function test_show_page_phases_reflect_proc_boolean_columns(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject([
            'main_user_id' => $user->id,
            'proc_requirements' => true,
            'proc_basic_design' => false,
            'proc_detail_design' => false,
            'proc_development' => true,
            'proc_testing' => false,
            'proc_maintenance' => false,
        ]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('project.phases', 6)
            ->where('project.phases.0.key', 'proc_requirements')
            ->where('project.phases.0.is_target', true)
            ->where('project.phases.3.key', 'proc_development')
            ->where('project.phases.3.is_target', true)
            ->where('project.phases.1.is_target', false)
        );
    }

    // -------------------------------------------------------
    // destroy: DELETE /projects/{project}
    // -------------------------------------------------------

    public function test_guest_cannot_delete_project(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->delete("/projects/{$project->id}");

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_admin_can_delete_project(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $project = $this->createProject(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/projects/{$project->id}");

        $response->assertRedirect('/projects');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_destroy_sets_success_flash_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $project = $this->createProject(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/projects/{$project->id}");

        $response->assertSessionHas('success', '案件情報を削除しました。');
    }

    public function test_general_user_cannot_delete_project(): void
    {
        $user = User::factory()->create(['role' => 'general']);
        $project = $this->createProject(['main_user_id' => $user->id]);

        // 削除は案件詳細画面から実行されるため、referer は詳細URL自身になる。
        $response = $this->actingAs($user)->delete(
            "/projects/{$project->id}",
            [],
            ['X-Inertia' => 'true', 'referer' => "/projects/{$project->id}"]
        );

        // 設計書 04_案件管理 DELETE #7：権限不足は 403 を素で投げず、前画面（＝同じ詳細画面）へ
        // 戻し flash.error を返す。redirect先を固定しないと一覧へ飛ばす誤実装でも通ってしまうため、
        // referer を明示してリダイレクト先まで検証する（StaleResourceHandlingTestと同粒度）。
        $response->assertStatus(303);
        $response->assertRedirect("/projects/{$project->id}");
        $response->assertSessionHas('error', '削除権限がありません。');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_general_user_delete_project_without_referer_falls_back_to_dashboard(): void
    {
        // referer が無い場合（直接リクエスト等）でも flash.error を失わずダッシュボードへ戻る。
        $user = User::factory()->create(['role' => 'general']);
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->delete("/projects/{$project->id}");

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error', '削除権限がありません。');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_destroy_returns_404_for_non_existent_project(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->delete('/projects/99999');

        $response->assertNotFound();
    }

    public function test_deleting_project_cascades_to_project_skills(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $project = $this->createProject(['main_user_id' => $admin->id]);
        $project->projectSkills()->create([
            'skill_type' => 'required',
            'label' => 'PHP',
            'detail' => null,
        ]);

        $this->actingAs($admin)->delete("/projects/{$project->id}");

        $this->assertDatabaseMissing('project_skills', ['project_id' => $project->id]);
    }

    // -------------------------------------------------------
    // store: POST /projects — 勤務地（追加分。work_location_station の
    // required_if 化・work_location_line の判定と併せてカバーする）
    // -------------------------------------------------------

    public function test_work_location_station_max_length_is_100(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'onsite',
            'work_location_station' => str_repeat('あ', 101),
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_project_is_stored_with_work_location_when_work_style_is_onsite(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'onsite',
            'work_location_line' => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'work_style' => 'onsite',
            'work_location_line' => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);
    }

    /**
     * フォーム側は稼働形態切替時にwork_location_line/stationの値をクリアしなくなったため、
     * work_style=remoteのまま画面上に値が残った状態で送信されうる。その場合でも
     * サーバー側で必ずnullにされることを確認する（ProjectService::projectAttributes）。
     */
    public function test_work_location_is_nulled_when_work_style_is_remote_even_when_values_are_present(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'remote',
            'work_location_line' => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'work_style' => 'remote',
            'work_location_line' => null,
            'work_location_station' => null,
        ]);
    }

    // -------------------------------------------------------
    // edit: GET /projects/{project}/edit
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_edit_page(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->get("/projects/{$project->id}/edit");

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_edit_page(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['name' => 'テスト案件', 'main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Edit')
            ->where('project.id', $project->id)
        );
    }

    public function test_edit_page_props_contain_required_keys(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}/edit");

        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Edit')
            ->has('project')
            ->has('fieldSettings')
            ->has('phases')
            ->has('work_styles')
            ->has('commercial_flows')
            ->has('statuses')
            ->has('users')
        );
    }

    public function test_edit_returns_404_for_non_existent_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/99999/edit');

        $response->assertNotFound();
    }

    public function test_edit_page_project_prop_reflects_existing_data(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject([
            'name' => '既存案件A',
            'status' => 'pending',
            'main_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}/edit");

        $response->assertInertia(fn ($page) => $page
            ->where('project.name', '既存案件A')
            ->where('project.status', 'pending')
        );
    }

    // -------------------------------------------------------
    // update: PUT /projects/{project}
    // -------------------------------------------------------

    public function test_guest_cannot_put_to_update(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->put("/projects/{$project->id}", []);

        $response->assertRedirect('/login');
    }

    public function test_update_returns_404_for_non_existent_project(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/projects/99999', $this->validPayload($user->id));

        $response->assertNotFound();
    }

    public function test_project_is_updated_with_valid_payload(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['name' => '旧案件名', 'status' => 'open', 'main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'name' => '新案件名',
            'status' => 'closed',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertRedirect("/projects/{$project->id}");
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => '新案件名',
            'status' => 'closed',
        ]);
    }

    public function test_update_sets_success_flash_message(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $this->validPayload($user->id));

        $response->assertSessionHas('success', '案件情報を更新しました。');
    }

    public function test_required_skills_are_replaced_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->create([
            'skill_type' => 'required',
            'label' => 'PHP',
            'detail' => null,
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => 'Go', 'detail' => null],
            ],
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseMissing('project_skills', [
            'project_id' => $project->id,
            'label' => 'PHP',
        ]);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'required',
            'label' => 'Go',
        ]);
    }

    public function test_preferred_skills_are_replaced_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->create([
            'skill_type' => 'preferred',
            'label' => 'Docker',
            'detail' => null,
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'preferred_skills' => [
                ['label' => 'AWS', 'detail' => null],
            ],
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseMissing('project_skills', [
            'project_id' => $project->id,
            'label' => 'Docker',
        ]);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'preferred',
            'label' => 'AWS',
        ]);
    }

    public function test_skills_are_all_deleted_when_empty_array_sent_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->createMany([
            ['skill_type' => 'required',  'label' => 'PHP',    'detail' => null],
            ['skill_type' => 'preferred', 'label' => 'Docker', 'detail' => null],
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [],
            'preferred_skills' => [],
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseMissing('project_skills', ['project_id' => $project->id]);
    }

    public function test_sub_user_id_can_be_cleared_on_update(): void
    {
        $this->seedFormFieldSettings();
        $mainUser = User::factory()->create();
        $subUser = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $mainUser->id, 'sub_user_id' => $subUser->id]);

        $payload = array_merge($this->validPayload($mainUser->id), ['sub_user_id' => null]);

        $this->actingAs($mainUser)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'sub_user_id' => null]);
    }

    public function test_rate_negotiable_stores_null_for_rate_min_and_max_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'rate_min' => 60, 'rate_max' => 80]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note' => 'スキル見合い',
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => 'スキル見合い',
        ]);
    }

    /**
     * rate_noteにデフォルト値（スキル見合い）とは異なる文字列を入力した場合、
     * 「未入力なら補完」のロジックで上書きされず、入力した文言がそのまま保存されることを確認する。
     */
    public function test_rate_negotiable_stores_custom_rate_note_as_is_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'rate_min' => 60, 'rate_max' => 80]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note' => '応相談',
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => '応相談',
        ]);
    }

    /**
     * フォーム側は「スキル見合い」チェックON/OFF時にrate_min/rate_maxの値をクリアしなくなったため、
     * 画面上は元の単価が残ったまま送信されうる。その場合でもサーバー側で必ずnullにされることを確認する。
     */
    public function test_rate_negotiable_stores_null_even_when_rate_min_and_max_are_present_in_payload_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'rate_min' => 60, 'rate_max' => 80]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_min' => 60,
            'rate_max' => 80,
            'rate_note' => null, // チェックON直後は未入力（実際の画面操作を再現）
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => 'スキル見合い', // 未入力時のデフォルト値が入る
        ]);
    }

    /**
     * 過去にスキル見合いとして保存された案件（rate_noteに値が残っている）を、
     * 通常の数値入力モードに戻して保存すると、rate_noteは必ずnullになることを確認する。
     * これはフロントが稼働形態・スキル見合いの切替時に値をクリアしなくなったことで、
     * 過去のrate_noteが残留したまま送信されるケースを想定した回帰テスト。
     */
    public function test_rate_note_is_nulled_when_not_negotiable_even_if_value_is_present_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject([
            'main_user_id' => $user->id,
            'rate_min' => null,
            'rate_max' => null,
            'rate_note' => 'スキル見合い',
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min' => 60,
            'rate_max' => 80,
            'rate_note' => 'スキル見合い', // 以前スキル見合いだった際の残留値を想定
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'rate_min' => 60,
            'rate_max' => 80,
            'rate_note' => null,
        ]);
    }

    public function test_proc_fields_are_stored_as_boolean_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'proc_requirements' => true,
            'proc_basic_design' => false,
            'proc_detail_design' => false,
            'proc_development' => true,
            'proc_testing' => false,
            'proc_maintenance' => false,
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'proc_requirements' => 1,
            'proc_development' => 1,
            'proc_basic_design' => 0,
            'proc_detail_design' => 0,
            'proc_testing' => 0,
            'proc_maintenance' => 0,
        ]);
    }

    public function test_name_is_required_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['name' => '元の案件名', 'main_user_id' => $user->id]);

        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => '元の案件名']);
    }

    public function test_main_user_id_must_exist_in_users_table_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), ['main_user_id' => 99999]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_differ_from_main_user_id_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => $user->id]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_skill_label_is_required_when_detail_is_present_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => null, 'detail' => '詳細テキスト'],
            ],
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('required_skills.0.label');
    }

    public function test_work_location_station_is_required_when_work_style_is_onsite_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'onsite',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_station_is_not_required_when_work_style_is_remote_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'remote',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors('work_location_station');
    }

    /**
     * フォーム側は稼働形態切替時にwork_location_line/stationの値をクリアしなくなったため、
     * 常駐で登録済みの案件をフルリモートに変更したとき、画面上に前の勤務地が残ったまま
     * 送信されうる。その場合でもサーバー側で必ずnullにされることを確認する。
     */
    public function test_work_location_is_nulled_when_work_style_is_remote_even_when_values_are_present_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject([
            'main_user_id' => $user->id,
            'work_style' => 'onsite',
            'work_location_line' => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'remote',
            'work_location_line' => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'work_style' => 'remote',
            'work_location_line' => null,
            'work_location_station' => null,
        ]);
    }

    // -------------------------------------------------------
    // update: PUT /projects/{project} — バリデーション（POSTと同一ルールの再検証）
    // ProjectRequest はstore/updateで共有されているため、POST側で確認済みの
    // ルールがPUT側でも同じ挙動になることをここで再検証する
    // -------------------------------------------------------

    public function test_name_exceeding_max_length_fails_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'name' => str_repeat('あ', 256),
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_status_is_required_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id);
        unset($payload['status']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_status_rejects_invalid_value_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), ['status' => 'invalid']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_main_user_id_is_required_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id);
        unset($payload['main_user_id']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_exist_in_users_table_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => 99999]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_dynamic_field_is_required_when_form_field_setting_is_true_on_update(): void
    {
        $this->seedFormFieldSettings(['description' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('description');
    }

    public function test_dynamic_field_is_nullable_when_form_field_setting_is_false_on_update(): void
    {
        $this->seedFormFieldSettings(['description' => false]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors('description');
    }

    public function test_negotiation_required_rejects_invalid_value_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'negotiation_required' => 'invalid',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('negotiation_required');
    }

    public function test_proc_fields_are_required_when_proc_experience_setting_is_true_on_update(): void
    {
        $this->seedFormFieldSettings(['proc_experience' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('proc_requirements');
        $response->assertSessionHasErrors('proc_development');
    }

    public function test_rate_min_is_required_when_rate_setting_is_true_and_not_negotiable_on_update(): void
    {
        $this->seedFormFieldSettings(['rate' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('rate_min');
        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_min_and_max_are_not_required_when_negotiable_on_update(): void
    {
        $this->seedFormFieldSettings(['rate' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors('rate_min');
        $response->assertSessionDoesntHaveErrors('rate_max');
    }

    public function test_rate_min_must_be_less_than_or_equal_to_rate_max_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min' => 80,
            'rate_max' => 60, // rate_min > rate_max
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('rate_min');
        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_max_is_required_when_rate_min_is_filled_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'rate_min' => 50,
            'rate_max' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_min_is_required_when_rate_max_is_filled_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'rate_min' => null,
            'rate_max' => 80,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('rate_min');
    }

    public function test_work_location_station_is_required_when_work_style_is_hybrid_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'hybrid',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_line_is_required_when_work_location_setting_is_true_and_work_style_is_onsite_on_update(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'onsite',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_location_line');
    }

    public function test_work_location_line_is_not_required_when_work_style_is_remote_on_update(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'remote',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors('work_location_line');
    }

    public function test_required_skills_is_required_when_form_field_setting_is_true_on_update(): void
    {
        $this->seedFormFieldSettings(['required_skills' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [],
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('required_skills');
    }

    public function test_skill_label_max_length_is_15_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => str_repeat('あ', 16), 'detail' => null],
            ],
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('required_skills.0.label');
    }

    public function test_skill_label_is_required_when_required_skills_setting_is_true_on_update(): void
    {
        $this->seedFormFieldSettings(['required_skills' => true]);
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => null, 'detail' => null],
            ],
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('required_skills.0.label');
    }

    public function test_commercial_flow_rejects_invalid_value_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'commercial_flow' => 'invalid',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('commercial_flow');
    }

    public function test_work_style_rejects_invalid_value_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style' => 'invalid',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_style');
    }

    // -------------------------------------------------------
    // update: PUT /projects/{project} — 権限（ProjectPolicyにupdate()が
    // 存在しないため、adminと一般営業のどちらも更新できることの回帰確認）
    // -------------------------------------------------------

    public function test_general_role_user_can_update_project(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create(['role' => 'general']);
        $project = $this->createProject(['name' => '旧案件名', 'main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), ['name' => '新案件名']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertRedirect("/projects/{$project->id}");
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => '新案件名']);
    }
}
