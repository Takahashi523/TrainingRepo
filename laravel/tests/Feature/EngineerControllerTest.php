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

        $response->assertRedirect('/engineers');
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
}
