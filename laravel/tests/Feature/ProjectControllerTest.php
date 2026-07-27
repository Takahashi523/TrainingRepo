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
}
