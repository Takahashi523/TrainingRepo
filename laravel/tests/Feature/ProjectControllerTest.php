<?php

namespace Tests\Feature;

use App\Models\FormFieldSetting;
use App\Models\Project;
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
                'form_type'          => 'project',
                'field_key'          => $key,
                'is_required'        => $overrides[$key] ?? false,
                'is_system_required' => false,
            ]);
        }
    }

    private function validPayload(int $mainUserId): array
    {
        return [
            'name'         => 'テスト案件',
            'status'       => 'open',
            'main_user_id' => $mainUserId,
            'sub_user_id'  => null,
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
            'name'         => 'テスト案件',
            'status'       => 'open',
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
            'name'         => 'テスト案件',
            'status'       => 'open',
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
            'label'      => 'PHP',
            'detail'     => 'Laravel 5年以上',
        ]);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'required',
            'label'      => 'Vue',
            'detail'     => null,
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
            'label'      => 'Docker',
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
        $subUser  = User::factory()->create();

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
            'rate_note'          => 'スキル見合い',
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min'           => null,
            'rate_max'           => null,
            'rate_note'          => 'スキル見合い',
        ]);
    }

    public function test_rate_negotiable_sets_default_rate_note_when_empty(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note'          => null,  // 未入力
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min'  => null,
            'rate_max'  => null,
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
            'rate_note'          => '応相談',
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min'  => null,
            'rate_max'  => null,
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
            'rate_min'           => 60,
            'rate_max'           => 80,
            'rate_note'          => null, // チェックON直後は未入力（実際の画面操作を再現）
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min'  => null,
            'rate_max'  => null,
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
            'rate_min'           => 60,
            'rate_max'           => 80,
            'rate_note'          => 'スキル見合い', // 以前スキル見合いだった際の残留値を想定
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'rate_min'  => 60,
            'rate_max'  => 80,
            'rate_note' => null,
        ]);
    }

    public function test_proc_fields_are_stored_as_boolean(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'proc_requirements'  => true,
            'proc_basic_design'  => false,
            'proc_detail_design' => false,
            'proc_development'   => true,
            'proc_testing'       => false,
            'proc_maintenance'   => false,
        ]);

        $this->actingAs($user)->post('/projects', $payload);

        $this->assertDatabaseHas('projects', [
            'proc_requirements'  => 1,
            'proc_development'   => 1,
            'proc_basic_design'  => 0,
            'proc_detail_design' => 0,
            'proc_testing'       => 0,
            'proc_maintenance'   => 0,
        ]);
    }

    // -------------------------------------------------------
    // store: POST /projects — バリデーション（固定必須フィールド）
    // -------------------------------------------------------

    public function test_name_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'name' => str_repeat('あ', 256),
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_status_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['status']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_status_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['status' => 'invalid']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_main_user_id_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['main_user_id']);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_main_user_id_must_exist_in_users_table(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['main_user_id' => 99999]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_differ_from_main_user_id(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_sub_user_id_must_exist_in_users_table(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('description');
    }

    public function test_dynamic_field_is_nullable_when_form_field_setting_is_false(): void
    {
        $this->seedFormFieldSettings(['description' => false]);
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('description');
    }

    public function test_negotiation_required_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'negotiation_required' => 'invalid',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('negotiation_required');
    }

    public function test_proc_fields_are_required_when_proc_experience_setting_is_true(): void
    {
        $this->seedFormFieldSettings(['proc_experience' => true]);
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('proc_requirements');
        $response->assertSessionHasErrors('proc_development');
    }

    public function test_rate_min_is_required_when_rate_setting_is_true_and_not_negotiable(): void
    {
        $this->seedFormFieldSettings(['rate' => true]);
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min'           => 80,
            'rate_max'           => 60, // rate_min > rate_max
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('rate_min');
        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_max_is_required_when_rate_min_is_filled(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'             => 'onsite',
            'work_location_station'  => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_station_is_required_when_work_style_is_hybrid(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'            => 'hybrid',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_station_is_not_required_when_work_style_is_remote(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'            => 'remote',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('work_location_station');
    }

    public function test_work_location_line_is_required_when_work_location_setting_is_true_and_work_style_is_onsite(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'          => 'onsite',
            'work_location_line'  => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_line');
    }

    public function test_work_location_line_is_not_required_when_work_style_is_remote(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'         => 'remote',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors('work_location_line');
    }

    public function test_required_skills_is_required_when_form_field_setting_is_true(): void
    {
        $this->seedFormFieldSettings(['required_skills' => true]);
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [],
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('required_skills');
    }

    public function test_skill_label_is_required_when_detail_is_present(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'commercial_flow' => 'invalid',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('commercial_flow');
    }

    public function test_work_style_rejects_invalid_value(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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

    public function test_authenticated_user_can_view_index_page(): void
    {
        $user = User::factory()->create();
        $this->createProject(['name' => 'A案件', 'main_user_id' => $user->id]);
        $this->createProject(['name' => 'B案件', 'main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Projects/Index')
            ->has('projects', 2)
        );
    }

    public function test_index_projects_are_ordered_by_id_desc(): void
    {
        $user   = User::factory()->create();
        $first  = $this->createProject(['name' => '先に作成', 'main_user_id' => $user->id]);
        $second = $this->createProject(['name' => '後に作成', 'main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/projects');

        $response->assertInertia(fn ($page) => $page
            ->where('projects.0.id', $second->id)
            ->where('projects.1.id', $first->id)
        );
    }

    // -------------------------------------------------------
    // show: GET /projects/{project}
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_show_page(): void
    {
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->get("/projects/{$project->id}");

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_show_page(): void
    {
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject([
            'main_user_id'          => $user->id,
            'client_name'           => 'テスト商事',
            'start_date'            => '2026-08-01',
            'rate_min'              => 60,
            'rate_max'              => 80,
            'commercial_flow'       => 'prime',
            'work_style'            => 'onsite',
            'work_location_line'    => 'JR山手線',
            'work_location_station' => '渋谷',
            'interview_count'       => 2,
            'headcount'             => 3,
            'negotiation_required'  => true,
            'description'           => '業務内容詳細のテキスト',
            'work_env'              => '稼働環境のテキスト',
            'billing_range'         => '精算幅140-180',
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
        $project  = $this->createProject(['main_user_id' => $mainUser->id]);

        $response = $this->actingAs($mainUser)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('project.users.main.id', $mainUser->id)
            ->where('project.users.main.name', '担当太郎')
        );
    }

    public function test_show_page_sub_user_is_null_when_not_set(): void
    {
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'sub_user_id' => null]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('project.users.sub', null)
        );
    }

    public function test_show_page_contains_sub_user_information_when_set(): void
    {
        $mainUser = User::factory()->create();
        $subUser  = User::factory()->create(['name' => '副担当花子']);
        $project  = $this->createProject(['main_user_id' => $mainUser->id, 'sub_user_id' => $subUser->id]);

        $response = $this->actingAs($mainUser)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('project.users.sub.id', $subUser->id)
            ->where('project.users.sub.name', '副担当花子')
        );
    }

    public function test_show_page_separates_required_and_preferred_skills(): void
    {
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/projects/{$project->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('project.required_skills', 0)
            ->has('project.preferred_skills', 0)
        );
    }

    public function test_show_page_phases_reflect_proc_boolean_columns(): void
    {
        $user    = User::factory()->create();
        $project = $this->createProject([
            'main_user_id'       => $user->id,
            'proc_requirements'  => true,
            'proc_basic_design'  => false,
            'proc_detail_design' => false,
            'proc_development'   => true,
            'proc_testing'       => false,
            'proc_maintenance'   => false,
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->delete("/projects/{$project->id}");

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_admin_can_delete_project(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $project = $this->createProject(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/projects/{$project->id}");

        $response->assertRedirect('/projects');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_destroy_sets_success_flash_message(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $project = $this->createProject(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/projects/{$project->id}");

        $response->assertSessionHas('success', '案件情報を削除しました。');
    }

    public function test_general_user_cannot_delete_project(): void
    {
        $user    = User::factory()->create(['role' => 'general']);
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->delete("/projects/{$project->id}");

        $response->assertForbidden();
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
        $admin   = User::factory()->create(['role' => 'admin']);
        $project = $this->createProject(['main_user_id' => $admin->id]);
        $project->projectSkills()->create([
            'skill_type' => 'required',
            'label'      => 'PHP',
            'detail'     => null,
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
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'             => 'onsite',
            'work_location_station'  => str_repeat('あ', 101),
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_project_is_stored_with_work_location_when_work_style_is_onsite(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'            => 'onsite',
            'work_location_line'    => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'work_style'             => 'onsite',
            'work_location_line'    => '東京メトロ丸ノ内線',
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
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'             => 'remote',
            'work_location_line'    => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $response = $this->actingAs($user)->post('/projects', $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'work_style'             => 'remote',
            'work_location_line'    => null,
            'work_location_station' => null,
        ]);
    }

    // -------------------------------------------------------
    // edit: GET /projects/{project}/edit
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_edit_page(): void
    {
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->get("/projects/{$project->id}/edit");

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_edit_page(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject([
            'name'         => '既存案件A',
            'status'       => 'pending',
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['name' => '旧案件名', 'status' => 'open', 'main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'name'   => '新案件名',
            'status' => 'closed',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertRedirect("/projects/{$project->id}");
        $this->assertDatabaseHas('projects', [
            'id'     => $project->id,
            'name'   => '新案件名',
            'status' => 'closed',
        ]);
    }

    public function test_update_sets_success_flash_message(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $this->validPayload($user->id));

        $response->assertSessionHas('success', '案件情報を更新しました。');
    }

    public function test_required_skills_are_replaced_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->create([
            'skill_type' => 'required',
            'label'      => 'PHP',
            'detail'     => null,
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'required_skills' => [
                ['label' => 'Go', 'detail' => null],
            ],
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseMissing('project_skills', [
            'project_id' => $project->id,
            'label'      => 'PHP',
        ]);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'required',
            'label'      => 'Go',
        ]);
    }

    public function test_preferred_skills_are_replaced_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->create([
            'skill_type' => 'preferred',
            'label'      => 'Docker',
            'detail'     => null,
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'preferred_skills' => [
                ['label' => 'AWS', 'detail' => null],
            ],
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseMissing('project_skills', [
            'project_id' => $project->id,
            'label'      => 'Docker',
        ]);
        $this->assertDatabaseHas('project_skills', [
            'project_id' => $project->id,
            'skill_type' => 'preferred',
            'label'      => 'AWS',
        ]);
    }

    public function test_skills_are_all_deleted_when_empty_array_sent_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $project->projectSkills()->createMany([
            ['skill_type' => 'required',  'label' => 'PHP',    'detail' => null],
            ['skill_type' => 'preferred', 'label' => 'Docker', 'detail' => null],
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'required_skills'  => [],
            'preferred_skills' => [],
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseMissing('project_skills', ['project_id' => $project->id]);
    }

    public function test_sub_user_id_can_be_cleared_on_update(): void
    {
        $this->seedFormFieldSettings();
        $mainUser = User::factory()->create();
        $subUser  = User::factory()->create();
        $project  = $this->createProject(['main_user_id' => $mainUser->id, 'sub_user_id' => $subUser->id]);

        $payload = array_merge($this->validPayload($mainUser->id), ['sub_user_id' => null]);

        $this->actingAs($mainUser)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'sub_user_id' => null]);
    }

    public function test_rate_negotiable_stores_null_for_rate_min_and_max_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'rate_min' => 60, 'rate_max' => 80]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note'          => 'スキル見合い',
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id'       => $project->id,
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'rate_min' => 60, 'rate_max' => 80]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_note'          => '応相談',
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id'        => $project->id,
            'rate_min'  => null,
            'rate_max'  => null,
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id, 'rate_min' => 60, 'rate_max' => 80]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => true,
            'rate_min'           => 60,
            'rate_max'           => 80,
            'rate_note'          => null, // チェックON直後は未入力（実際の画面操作を再現）
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id'        => $project->id,
            'rate_min'  => null,
            'rate_max'  => null,
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
        $user    = User::factory()->create();
        $project = $this->createProject([
            'main_user_id' => $user->id,
            'rate_min'     => null,
            'rate_max'     => null,
            'rate_note'    => 'スキル見合い',
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min'           => 60,
            'rate_max'           => 80,
            'rate_note'          => 'スキル見合い', // 以前スキル見合いだった際の残留値を想定
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id'        => $project->id,
            'rate_min'  => 60,
            'rate_max'  => 80,
            'rate_note' => null,
        ]);
    }

    public function test_proc_fields_are_stored_as_boolean_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'proc_requirements'  => true,
            'proc_basic_design'  => false,
            'proc_detail_design' => false,
            'proc_development'   => true,
            'proc_testing'       => false,
            'proc_maintenance'   => false,
        ]);

        $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $this->assertDatabaseHas('projects', [
            'id'                 => $project->id,
            'proc_requirements'  => 1,
            'proc_development'   => 1,
            'proc_basic_design'  => 0,
            'proc_detail_design' => 0,
            'proc_testing'       => 0,
            'proc_maintenance'   => 0,
        ]);
    }

    public function test_name_is_required_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), ['main_user_id' => 99999]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_differ_from_main_user_id_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => $user->id]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_skill_label_is_required_when_detail_is_present_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'work_style'             => 'onsite',
            'work_location_station'  => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_station_is_not_required_when_work_style_is_remote_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);

        $payload = array_merge($this->validPayload($user->id), [
            'work_style'             => 'remote',
            'work_location_station'  => null,
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
        $user    = User::factory()->create();
        $project = $this->createProject([
            'main_user_id'           => $user->id,
            'work_style'             => 'onsite',
            'work_location_line'    => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $payload = array_merge($this->validPayload($user->id), [
            'work_style'             => 'remote',
            'work_location_line'    => '東京メトロ丸ノ内線',
            'work_location_station' => '大手町',
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('projects', [
            'id'                     => $project->id,
            'work_style'             => 'remote',
            'work_location_line'    => null,
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id);
        unset($payload['status']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_status_rejects_invalid_value_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), ['status' => 'invalid']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_main_user_id_is_required_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id);
        unset($payload['main_user_id']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_exist_in_users_table_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => 99999]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_dynamic_field_is_required_when_form_field_setting_is_true_on_update(): void
    {
        $this->seedFormFieldSettings(['description' => true]);
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('description');
    }

    public function test_dynamic_field_is_nullable_when_form_field_setting_is_false_on_update(): void
    {
        $this->seedFormFieldSettings(['description' => false]);
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id); // description を含まない

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors('description');
    }

    public function test_negotiation_required_rejects_invalid_value_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = $this->validPayload($user->id);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('proc_requirements');
        $response->assertSessionHasErrors('proc_development');
    }

    public function test_rate_min_is_required_when_rate_setting_is_true_and_not_negotiable_on_update(): void
    {
        $this->seedFormFieldSettings(['rate' => true]);
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'rate_is_negotiable' => false,
            'rate_min'           => 80,
            'rate_max'           => 60, // rate_min > rate_max
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('rate_min');
        $response->assertSessionHasErrors('rate_max');
    }

    public function test_rate_max_is_required_when_rate_min_is_filled_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'            => 'hybrid',
            'work_location_station' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_location_station');
    }

    public function test_work_location_line_is_required_when_work_location_setting_is_true_and_work_style_is_onsite_on_update(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'         => 'onsite',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionHasErrors('work_location_line');
    }

    public function test_work_location_line_is_not_required_when_work_style_is_remote_on_update(): void
    {
        $this->seedFormFieldSettings(['work_location' => true]);
        $user    = User::factory()->create();
        $project = $this->createProject(['main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), [
            'work_style'         => 'remote',
            'work_location_line' => null,
        ]);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertSessionDoesntHaveErrors('work_location_line');
    }

    public function test_required_skills_is_required_when_form_field_setting_is_true_on_update(): void
    {
        $this->seedFormFieldSettings(['required_skills' => true]);
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create();
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
        $user    = User::factory()->create(['role' => 'general']);
        $project = $this->createProject(['name' => '旧案件名', 'main_user_id' => $user->id]);
        $payload = array_merge($this->validPayload($user->id), ['name' => '新案件名']);

        $response = $this->actingAs($user)->put("/projects/{$project->id}", $payload);

        $response->assertRedirect("/projects/{$project->id}");
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => '新案件名']);
    }
}
