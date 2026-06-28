<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\FormFieldSetting;
use App\Models\User;
use App\Services\AiSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngineerControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------

    private function seedFormFieldSettings(array $overrides = []): void
    {
        $fields = [
            'birth_date', 'nearest_station', 'nearest_line', 'available_from',
            'skills', 'proc_experience', 'has_negotiation_exp', 'appeal_note',
            'desired_rate', 'work_styles', 'remarks',
        ];
        foreach ($fields as $key) {
            FormFieldSetting::create([
                'form_type'          => 'engineer',
                'field_key'          => $key,
                'is_required'        => $overrides[$key] ?? false,
                'is_system_required' => false,
            ]);
        }
    }

    private function validPayload(int $mainUserId): array
    {
        return [
            'name'         => '山田太郎',
            'name_kana'    => 'ヤマダタロウ',
            'status'       => 'proposable',
            'main_user_id' => $mainUserId,
            'sub_user_id'  => null,
        ];
    }

    // -------------------------------------------------------
    // create: GET /engineers/create
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_create_page(): void
    {
        $response = $this->get('/engineers/create');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_create_page(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Engineers/Create'));
    }

    public function test_create_page_props_contain_required_keys(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers/create');

        $response->assertInertia(fn ($page) => $page
            ->component('Engineers/Create')
            ->has('fieldSettings')
            ->has('phases')
            ->has('work_styles')
            ->has('statuses')
            ->has('users')
        );
    }

    public function test_field_settings_reflect_is_required_true(): void
    {
        $this->seedFormFieldSettings(['birth_date' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers/create');

        $response->assertInertia(fn ($page) => $page
            ->where('fieldSettings.birth_date.is_required', true)
            ->where('fieldSettings.remarks.is_required', false)
        );
    }

    public function test_field_settings_default_to_not_required_when_no_db_record(): void
    {
        // DB にレコードが存在しない状態でも is_required=false でレンダリングされる
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers/create');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('fieldSettings.birth_date.is_required', false)
        );
    }

    // -------------------------------------------------------
    // store: POST /engineers — 正常系
    // -------------------------------------------------------

    public function test_guest_cannot_post_to_store(): void
    {
        $response = $this->post('/engineers', []);

        $response->assertRedirect('/login');
    }

    public function test_engineer_is_stored_with_minimum_required_fields(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $engineer = Engineer::where('name', '山田太郎')->first();
        $response->assertRedirect("/engineers/{$engineer->id}");
        $this->assertDatabaseHas('engineers', [
            'name'         => '山田太郎',
            'name_kana'    => 'ヤマダタロウ',
            'status'       => 'proposable',
            'main_user_id' => $user->id,
        ]);
    }

    public function test_store_sets_success_flash_message(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $response->assertSessionHas('success', '人材情報を登録しました。');
    }

    public function test_skills_are_stored_in_engineer_skills_table(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [
                ['label' => 'PHP', 'detail' => 'Laravel 10年'],
                ['label' => 'Vue', 'detail' => null],
            ],
        ]);

        $this->actingAs($user)->post('/engineers', $payload);

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertNotNull($engineer);
        $this->assertCount(2, $engineer->skills);
        $this->assertDatabaseHas('engineer_skills', ['label' => 'PHP', 'detail' => 'Laravel 10年']);
        $this->assertDatabaseHas('engineer_skills', ['label' => 'Vue', 'detail' => null]);
    }

    public function test_work_styles_are_converted_to_boolean_columns(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $payload = array_merge($this->validPayload($user->id), [
            'work_styles' => ['onsite', 'remote'],
        ]);

        $this->actingAs($user)->post('/engineers', $payload);

        $this->assertDatabaseHas('engineers', [
            'work_style_onsite' => true,
            'work_style_hybrid' => false,
            'work_style_remote' => true,
        ]);
    }

    public function test_sub_user_id_accepts_null(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $this->assertDatabaseHas('engineers', ['sub_user_id' => null]);
    }

    public function test_sub_user_id_accepts_existing_user(): void
    {
        $this->seedFormFieldSettings();
        $mainUser = User::factory()->create();
        $subUser  = User::factory()->create();

        $payload = array_merge($this->validPayload($mainUser->id), ['sub_user_id' => $subUser->id]);
        $this->actingAs($mainUser)->post('/engineers', $payload);

        $this->assertDatabaseHas('engineers', ['sub_user_id' => $subUser->id]);
    }

    // -------------------------------------------------------
    // store: POST /engineers — バリデーション（固定必須フィールド）
    // -------------------------------------------------------

    public function test_name_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name' => str_repeat('あ', 101)]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_kana_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['name_kana']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_name_kana_must_be_katakana(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => 'yamada taro']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_name_kana_rejects_hiragana(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => 'やまだたろう']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_name_kana_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => str_repeat('ア', 101)]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_status_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['status']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_status_must_be_valid_enum(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['status' => 'unknown']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_main_user_id_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['main_user_id']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_main_user_id_must_exist_in_users(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['main_user_id' => 99999]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_exist_when_provided(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => 99999]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    // -------------------------------------------------------
    // store: POST /engineers — 動的バリデーション（form_field_settings 依存）
    // -------------------------------------------------------

    public function test_dynamic_field_is_required_when_is_required_true(): void
    {
        $this->seedFormFieldSettings(['birth_date' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $response->assertSessionHasErrors('birth_date');
    }

    public function test_dynamic_field_is_nullable_when_is_required_false(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $response->assertSessionHasNoErrors();
    }

    public function test_proc_experience_fields_are_required_when_is_required_true(): void
    {
        $this->seedFormFieldSettings(['proc_experience' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        // proc_* 全6フィールドが必須エラーになる
        $response->assertSessionHasErrors([
            'proc_requirements',
            'proc_basic_design',
            'proc_detail_design',
            'proc_development',
            'proc_testing',
            'proc_maintenance',
        ]);
    }

    public function test_proc_experience_fields_are_nullable_by_default(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'proc_requirements' => false,
            'proc_development'  => true,
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_work_styles_is_required_when_is_required_true(): void
    {
        $this->seedFormFieldSettings(['work_styles' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $response->assertSessionHasErrors('work_styles');
    }

    public function test_skills_is_required_when_is_required_true(): void
    {
        $this->seedFormFieldSettings(['skills' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $response->assertSessionHasErrors('skills');
    }

    public function test_skill_label_is_required_when_skills_required_and_row_is_empty(): void
    {
        // skills 必須時に空ラベル行を送ると、配列件数は満たすが label が null/'' で
        // 旧実装ではすり抜けて DB に空スキル行が登録され、再編集時にクラッシュしていた回帰防止
        $this->seedFormFieldSettings(['skills' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => '', 'detail' => '']],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.label');
    }

    public function test_skill_with_label_passes_when_skills_required(): void
    {
        $this->seedFormFieldSettings(['skills' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => 'PHP', 'detail' => null]],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_empty_skill_row_is_filtered_on_insert_when_not_required(): void
    {
        // skills 必須でないとき、空行（label/detail とも null）は DB に挿入されない
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [
                ['label' => 'PHP', 'detail' => null],
                ['label' => '',    'detail' => ''],
            ],
        ]);

        $this->actingAs($user)->post('/engineers', $payload);

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertCount(1, $engineer->skills);
        $this->assertSame('PHP', $engineer->skills->first()->label);
    }

    public function test_skill_label_max_length_validation(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => str_repeat('A', 16), 'detail' => null]],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.label');
    }

    public function test_skill_detail_max_length_validation(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => 'PHP', 'detail' => str_repeat('あ', 501)]],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.detail');
    }

    public function test_birth_date_in_future_fails(): void
    {
        $this->seedFormFieldSettings(['birth_date' => true]);
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['birth_date' => now()->addDay()->toDateString()]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('birth_date');
    }

    public function test_birth_date_today_passes(): void
    {
        $this->seedFormFieldSettings(['birth_date' => true]);
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['birth_date' => now()->toDateString()]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_desired_rate_exceeding_max_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['desired_rate' => 65536]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('desired_rate');
    }

    public function test_desired_rate_at_max_passes(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['desired_rate' => 65535]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_work_styles_invalid_value_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['work_styles' => ['invalid_value']]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('work_styles.0');
    }

    public function test_proc_field_invalid_boolean_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['proc_requirements' => 'invalid']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('proc_requirements');
    }

    public function test_has_negotiation_exp_invalid_value_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['has_negotiation_exp' => 'invalid']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('has_negotiation_exp');
    }

    public function test_sub_user_id_must_differ_from_main_user_id(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_skill_label_is_required_when_detail_is_present(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => null, 'detail' => 'Laravel 10年']],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.label');
    }

    public function test_skill_label_is_required_when_detail_is_present_with_empty_string_label(): void
    {
        // フロントは空入力を null ではなく空文字 "" として送るため、null だけでなく "" のケースも回帰防止する
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => '', 'detail' => 'Laravel 10年']],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.label');
    }

    public function test_name_kana_half_width_space_is_normalized_to_full_width(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => 'ヤマダ タロウ']);

        $this->actingAs($user)->post('/engineers', $payload);

        $this->assertDatabaseHas('engineers', ['name_kana' => 'ヤマダ　タロウ']);
    }

    // -------------------------------------------------------
    // store: POST /engineers — AI サマリ
    // -------------------------------------------------------

    public function test_ai_summary_and_generated_at_are_saved_when_service_returns_text(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $this->mock(AiSummaryService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('AI生成要約テキスト');
        });

        $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertSame('AI生成要約テキスト', $engineer->ai_summary);
        $this->assertNotNull($engineer->ai_summary_generated_at);
    }

    public function test_ai_summary_is_null_and_generated_at_is_not_set_when_service_returns_null(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $this->mock(AiSummaryService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn(null);
        });

        $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertNull($engineer->ai_summary);
        $this->assertNull($engineer->ai_summary_generated_at);
    }

    // -------------------------------------------------------
    // show: GET /engineers/{id}
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_show_page(): void
    {
        $engineer = Engineer::factory()->create();

        $response = $this->get("/engineers/{$engineer->id}");

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_show_page(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Engineers/Show'));
    }

    public function test_show_page_returns_404_for_non_existent_engineer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers/99999');

        $response->assertNotFound();
    }

    public function test_show_props_contain_engineer_key(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page->has('engineer'));
    }

    public function test_show_props_contain_correct_engineer_data(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'name'         => '田中花子',
            'name_kana'    => 'タナカハナコ',
            'status'       => 'interviewing',
            'main_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.name', '田中花子')
            ->where('engineer.name_kana', 'タナカハナコ')
            ->where('engineer.status', 'interviewing')
            ->where('engineer.users.main.id', $user->id)
        );
    }

    public function test_show_props_available_label_is_未定_when_available_from_is_null(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id'  => $user->id,
            'available_from' => null,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.available_label', '未定')
        );
    }

    public function test_show_props_available_label_is_formatted_date_when_available_from_is_set(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id'   => $user->id,
            'available_from' => '2026-08-01',
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.available_label', '2026/08/01〜')
        );
    }

    public function test_show_props_age_is_null_when_birth_date_is_null(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'birth_date'   => null,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.age', null)
        );
    }

    public function test_show_props_age_is_calculated_from_birth_date(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'birth_date'   => now()->subYears(30)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.age', 30)
        );
    }

    public function test_show_props_skills_include_detail(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $engineer->skills()->create(['label' => 'PHP', 'detail' => 'Laravel 5年']);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.skills.0.label', 'PHP')
            ->where('engineer.skills.0.detail', 'Laravel 5年')
        );
    }

    public function test_show_props_phases_contain_all_six_entries(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id'       => $user->id,
            'proc_requirements'  => true,
            'proc_development'   => true,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->count('engineer.phases', 6)
            ->where('engineer.phases.0.key', 'proc_requirements')
            ->where('engineer.phases.0.has_experience', true)
            ->where('engineer.phases.1.has_experience', false)
        );
    }

    public function test_show_props_work_styles_returns_only_selected(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'work_style_onsite' => true,
            'work_style_hybrid' => false,
            'work_style_remote' => true,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->count('engineer.work_styles', 2)
            ->where('engineer.work_styles.0.key', 'onsite')
            ->where('engineer.work_styles.1.key', 'remote')
        );
    }

    public function test_show_props_sub_user_is_null_when_not_set(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'sub_user_id'  => null,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.users.sub', null)
        );
    }

    public function test_show_props_sub_user_is_returned_when_set(): void
    {
        $mainUser = User::factory()->create();
        $subUser  = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $mainUser->id,
            'sub_user_id'  => $subUser->id,
        ]);

        $response = $this->actingAs($mainUser)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.users.sub.id', $subUser->id)
            ->where('engineer.users.sub.name', $subUser->name)
        );
    }

    // -------------------------------------------------------
    // destroy: DELETE /engineers/{id}
    // -------------------------------------------------------

    public function test_guest_cannot_delete_engineer(): void
    {
        $engineer = Engineer::factory()->create();

        $response = $this->delete("/engineers/{$engineer->id}");

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('engineers', ['id' => $engineer->id]);
    }

    public function test_admin_can_delete_engineer(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $engineer = Engineer::factory()->create(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/engineers/{$engineer->id}");

        $response->assertRedirect('/engineers');
        $this->assertDatabaseMissing('engineers', ['id' => $engineer->id]);
    }

    public function test_destroy_sets_success_flash_message(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $engineer = Engineer::factory()->create(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/engineers/{$engineer->id}");

        $response->assertSessionHas('success', '人材情報を削除しました。');
    }

    public function test_general_user_cannot_delete_engineer(): void
    {
        $user     = User::factory()->create(['role' => 'general']);
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->delete("/engineers/{$engineer->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('engineers', ['id' => $engineer->id]);
    }

    public function test_destroy_returns_404_for_non_existent_engineer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->delete('/engineers/99999');

        $response->assertNotFound();
    }

    // -------------------------------------------------------
    // edit: GET /engineers/{id}/edit
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_edit_page(): void
    {
        $engineer = Engineer::factory()->create();

        $response = $this->get("/engineers/{$engineer->id}/edit");

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_edit_page(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Engineers/Edit'));
    }

    public function test_edit_page_props_contain_required_keys(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/edit");

        $response->assertInertia(fn ($page) => $page
            ->component('Engineers/Edit')
            ->has('engineer')
            ->has('fieldSettings')
            ->has('phases')
            ->has('work_styles')
            ->has('statuses')
            ->has('users')
        );
    }

    public function test_edit_page_props_engineer_contains_existing_values(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'name'         => '佐藤花子',
            'name_kana'    => 'サトウハナコ',
            'main_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/edit");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.name', '佐藤花子')
            ->where('engineer.name_kana', 'サトウハナコ')
            ->where('engineer.users.main.id', $user->id)
        );
    }

    public function test_edit_page_returns_404_for_non_existent_engineer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers/99999/edit');

        $response->assertNotFound();
    }

    // -------------------------------------------------------
    // update: PUT /engineers/{id} — 正常系
    // -------------------------------------------------------

    public function test_guest_cannot_put_to_update(): void
    {
        $engineer = Engineer::factory()->create();

        $response = $this->put("/engineers/{$engineer->id}", []);

        $response->assertRedirect('/login');
    }

    public function test_engineer_is_updated_with_valid_payload(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'name'         => '旧氏名',
            'main_user_id' => $user->id,
        ]);

        $payload = array_merge($this->validPayload($user->id), ['name' => '新氏名']);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertRedirect("/engineers/{$engineer->id}");
        $this->assertDatabaseHas('engineers', ['id' => $engineer->id, 'name' => '新氏名']);
    }

    public function test_update_sets_success_flash_message(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $this->validPayload($user->id));

        $response->assertSessionHas('success', '人材情報を更新しました。');
    }

    public function test_update_replaces_skills_with_submitted_list(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $engineer->skills()->createMany([
            ['label' => 'PHP',  'detail' => null],
            ['label' => 'Vue',  'detail' => null],
            ['label' => 'Java', 'detail' => null],
        ]);
        $this->assertCount(3, $engineer->fresh()->skills);

        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => 'Go', 'detail' => 'gRPC経験あり']],
        ]);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $engineer = $engineer->fresh();
        $this->assertCount(1, $engineer->skills);
        $this->assertSame('Go', $engineer->skills->first()->label);
        $this->assertDatabaseMissing('engineer_skills', ['engineer_id' => $engineer->id, 'label' => 'PHP']);
    }

    public function test_update_deletes_all_skills_when_empty_array_submitted(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $engineer->skills()->createMany([
            ['label' => 'PHP', 'detail' => null],
            ['label' => 'Vue', 'detail' => null],
        ]);

        $payload = array_merge($this->validPayload($user->id), ['skills' => []]);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $this->assertCount(0, $engineer->fresh()->skills);
    }

    public function test_update_converts_work_styles_to_boolean_columns(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'work_style_onsite' => true,
            'work_style_hybrid' => true,
            'work_style_remote' => true,
        ]);

        $payload = array_merge($this->validPayload($user->id), ['work_styles' => ['hybrid']]);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $this->assertDatabaseHas('engineers', [
            'id'                => $engineer->id,
            'work_style_onsite' => false,
            'work_style_hybrid' => true,
            'work_style_remote' => false,
        ]);
    }

    public function test_update_regenerates_ai_summary_when_appeal_note_changes(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note'  => '元のアピール',
        ]);

        $this->mock(AiSummaryService::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('再生成された要約');
        });

        $payload = array_merge($this->validPayload($user->id), ['appeal_note' => '更新後のアピール']);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $engineer = $engineer->fresh();
        $this->assertSame('再生成された要約', $engineer->ai_summary);
        $this->assertNotNull($engineer->ai_summary_generated_at);
    }

    public function test_update_does_not_regenerate_ai_summary_when_appeal_note_unchanged(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note'  => 'そのままのアピール',
        ]);

        $this->mock(AiSummaryService::class, function ($mock) {
            $mock->shouldNotReceive('generate');
        });

        $payload = array_merge($this->validPayload($user->id), ['appeal_note' => 'そのままのアピール']);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        // モック自体が generate を呼ばれないことを保証している
        $this->assertSame('そのままのアピール', $engineer->fresh()->appeal_note);
    }

    public function test_update_validates_required_fields(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_update_returns_404_for_non_existent_engineer(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/engineers/99999', $this->validPayload($user->id));

        $response->assertNotFound();
    }

    // -------------------------------------------------------
    // index: GET /engineers
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_index_page(): void
    {
        $response = $this->get('/engineers');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_index_page_with_default_props(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Engineers/Index')
            ->has('engineers.data')
            ->has('engineers.meta')
            ->has('filters')
            ->has('statusOptions')
            ->has('workStyleOptions')
            ->has('phaseOptions')
        );
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create(['main_user_id' => $user->id, 'status' => 'proposable']);
        Engineer::factory()->create(['main_user_id' => $user->id, 'status' => 'interviewing']);
        Engineer::factory()->create(['main_user_id' => $user->id, 'status' => 'not_proposable']);

        $response = $this->actingAs($user)->get('/engineers?status[]=proposable');

        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 1)
            ->where('engineers.data.0.status', 'proposable')
        );
    }

    public function test_index_filters_work_styles_with_or_logic(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'work_style_onsite' => true,
            'work_style_hybrid' => false,
            'work_style_remote' => false,
        ]);
        Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'work_style_onsite' => false,
            'work_style_hybrid' => false,
            'work_style_remote' => true,
        ]);
        Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'work_style_onsite' => false,
            'work_style_hybrid' => true,
            'work_style_remote' => false,
        ]);

        $response = $this->actingAs($user)->get('/engineers?work_styles[]=onsite&work_styles[]=remote');

        // onsite OR remote の 2 件のみヒット（hybrid のみは除外）
        $response->assertInertia(fn ($page) => $page->count('engineers.data', 2));
    }

    public function test_index_filters_phases_with_and_logic(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'proc_development'  => true,
            'proc_testing'      => true,
        ]);
        Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'proc_development'  => true,
            'proc_testing'      => false,
        ]);
        Engineer::factory()->create([
            'main_user_id'      => $user->id,
            'proc_development'  => false,
            'proc_testing'      => true,
        ]);

        $response = $this->actingAs($user)
            ->get('/engineers?phases[]=proc_development&phases[]=proc_testing');

        // 両方 true の 1 件のみ
        $response->assertInertia(fn ($page) => $page->count('engineers.data', 1));
    }

    public function test_index_searches_keyword_by_name(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '山田太郎']);
        Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '佐藤花子']);

        $response = $this->actingAs($user)->get('/engineers?keyword=山田');

        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 1)
            ->where('engineers.data.0.name', '山田太郎')
        );
    }

    public function test_index_searches_keyword_by_skill_prefix(): void
    {
        $user = User::factory()->create();
        $e1 = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'Aさん']);
        $e1->skills()->create(['label' => 'JavaScript', 'detail' => null]);

        $e2 = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'Bさん']);
        $e2->skills()->create(['label' => 'Python', 'detail' => null]);

        $response = $this->actingAs($user)->get('/engineers?keyword=Java');

        // スキル前方一致：JavaScript はヒット、Python は非ヒット
        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 1)
            ->where('engineers.data.0.name', 'Aさん')
        );
    }

    public function test_index_does_not_search_keyword_by_appeal_note(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'name'         => 'Aさん',
            'appeal_note'  => 'バックエンド開発が得意です',
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'name'         => 'Bさん',
            'appeal_note'  => 'デザインが得意です',
        ]);

        $response = $this->actingAs($user)->get('/engineers?keyword=バックエンド');

        // アピールポイントは検索対象外のため0件
        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 0)
        );
    }

    public function test_index_default_sort_is_created_at_desc(): void
    {
        $user = User::factory()->create();
        $old  = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '古い']);
        $old->created_at = now()->subDays(5); $old->save();
        $new  = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '新しい']);
        $new->created_at = now(); $new->save();

        $response = $this->actingAs($user)->get('/engineers');

        $response->assertInertia(fn ($page) => $page
            ->where('engineers.data.0.name', '新しい')
            ->where('engineers.data.1.name', '古い')
        );
    }

    public function test_index_sort_by_available_from_places_nulls_last(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create([
            'main_user_id'   => $user->id,
            'name'           => 'A',
            'available_from' => '2026-08-01',
        ]);
        Engineer::factory()->create([
            'main_user_id'   => $user->id,
            'name'           => 'B',
            'available_from' => null,
        ]);
        Engineer::factory()->create([
            'main_user_id'   => $user->id,
            'name'           => 'C',
            'available_from' => '2026-07-01',
        ]);

        $response = $this->actingAs($user)
            ->get('/engineers?sort=available_from&order=asc');

        $response->assertInertia(fn ($page) => $page
            ->where('engineers.data.0.name', 'C')   // 2026-07-01
            ->where('engineers.data.1.name', 'A')   // 2026-08-01
            ->where('engineers.data.2.name', 'B')   // NULL は末尾
        );
    }

    public function test_index_paginates_with_per_page(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->count(5)->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/engineers?per_page=2');

        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 2)
            ->where('engineers.meta.total', 5)
            ->where('engineers.meta.per_page', 2)
            ->where('engineers.meta.last_page', 3)
        );
    }

    public function test_index_clamps_per_page_to_max_100(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers?per_page=500');

        $response->assertInertia(fn ($page) => $page
            ->where('engineers.meta.per_page', 100)
            ->where('filters.per_page', 100)
        );
    }

    public function test_index_returns_empty_when_no_match(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '山田']);

        $response = $this->actingAs($user)->get('/engineers?keyword=該当なしのキーワード');

        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 0)
            ->where('engineers.meta.total', 0)
        );
    }

    public function test_index_echoes_query_params_into_filters(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(
            '/engineers?status[]=proposable&work_styles[]=remote&phases[]=proc_development'
            . '&keyword=Java&sort=available_from&order=asc&per_page=10&page=1'
        );

        $response->assertInertia(fn ($page) => $page
            ->where('filters.status', ['proposable'])
            ->where('filters.work_styles', ['remote'])
            ->where('filters.phases', ['proc_development'])
            ->where('filters.keyword', 'Java')
            ->where('filters.sort', 'available_from')
            ->where('filters.order', 'asc')
            ->where('filters.per_page', 10)
        );
    }

    public function test_index_does_not_return_text_columns(): void
    {
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note'  => 'これは表示されないはず',
            'remarks'      => '特記事項も表示されないはず',
            'ai_summary'   => 'AI要約も表示されないはず',
        ]);
        $engineer->skills()->create(['label' => 'PHP', 'detail' => 'Laravel 5年']);

        $response = $this->actingAs($user)->get('/engineers');

        $response->assertInertia(fn ($page) => $page
            ->missing('engineers.data.0.appeal_note')
            ->missing('engineers.data.0.remarks')
            ->missing('engineers.data.0.ai_summary')
            ->where('engineers.data.0.skills.0.label', 'PHP')
            ->missing('engineers.data.0.skills.0.detail')
        );
    }

    public function test_index_eager_loads_relations_to_avoid_n_plus_one(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->count(5)->create(['main_user_id' => $user->id])
            ->each(function (Engineer $e) {
                $e->skills()->create(['label' => 'PHP', 'detail' => null]);
                $e->skills()->create(['label' => 'Vue', 'detail' => null]);
            });

        \DB::enableQueryLog();

        $this->actingAs($user)->get('/engineers');

        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        // セッション・ユーザー・count・engineers 本体・skills・mainUser・subUser を含む。
        // N+1 が起きた場合は 5件 × 2リレーション = 10+ 件追加されるので 20 件以内で十分検出できる
        $this->assertLessThanOrEqual(20, count($queries));
    }
}
