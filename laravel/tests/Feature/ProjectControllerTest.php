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
}
