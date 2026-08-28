<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\FormFieldSetting;
use App\Models\Pipeline;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
                'form_type' => 'engineer',
                'field_key' => $key,
                'is_required' => $overrides[$key] ?? false,
                'is_system_required' => false,
            ]);
        }
    }

    private function validPayload(int $mainUserId): array
    {
        return [
            'name' => '山田太郎',
            'name_kana' => 'ヤマダタロウ',
            'status' => 'proposable',
            'main_user_id' => $mainUserId,
            'sub_user_id' => null,
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
            'name' => '山田太郎',
            'name_kana' => 'ヤマダタロウ',
            'status' => 'proposable',
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
        $subUser = User::factory()->create();

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
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name' => str_repeat('あ', 101)]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_kana_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['name_kana']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_name_kana_must_be_katakana(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => 'yamada taro']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_name_kana_rejects_hiragana(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => 'やまだたろう']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    /**
     * #67：生年月日欄をテキスト化したため未来日文字列がそのままサーバへ届く。
     * サーバ FormRequest（before_or_equal:today）が弾き、サイレント保存されないことをロックする。
     */
    public function test_birth_date_rejects_future_date(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $future = now()->addDay()->format('Y-m-d');
        $payload = array_merge($this->validPayload($user->id), ['birth_date' => $future]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('birth_date');
        $this->assertDatabaseCount('engineers', 0);
    }

    /**
     * #67：日付欄をテキスト化したため実在しない日付文字列がそのままサーバへ届く。
     * サーバ FormRequest（date）が弾き、サイレント保存されないことをロックする。
     */
    public function test_birth_date_rejects_invalid_date(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['birth_date' => '2000-02-30']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('birth_date');
        $this->assertDatabaseCount('engineers', 0);
    }

    /**
     * #67：希望単価欄をテキスト化したため非数値文字列がそのままサーバへ届く。
     * サーバ FormRequest（integer）が弾き、サイレント保存されないことをロックする。
     */
    public function test_desired_rate_rejects_non_numeric_value(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['desired_rate' => 'あ']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('desired_rate');
        $this->assertDatabaseCount('engineers', 0);
    }

    public function test_name_kana_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => str_repeat('ア', 101)]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('name_kana');
    }

    public function test_status_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['status']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_status_must_be_valid_enum(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['status' => 'unknown']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('status');
    }

    public function test_main_user_id_is_required(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = $this->validPayload($user->id);
        unset($payload['main_user_id']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_main_user_id_must_exist_in_users(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['main_user_id' => 99999]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('main_user_id');
    }

    public function test_sub_user_id_must_exist_when_provided(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'proc_requirements' => false,
            'proc_development' => true,
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
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => str_repeat('A', 16), 'detail' => null]],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.label');
    }

    public function test_skill_detail_max_length_validation(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => 'PHP', 'detail' => str_repeat('あ', 501)]],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.detail');
    }

    public function test_birth_date_in_future_fails(): void
    {
        $this->seedFormFieldSettings(['birth_date' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['birth_date' => now()->addDay()->toDateString()]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('birth_date');
    }

    public function test_birth_date_today_passes(): void
    {
        $this->seedFormFieldSettings(['birth_date' => true]);
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['birth_date' => now()->toDateString()]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_desired_rate_exceeding_max_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['desired_rate' => 1000]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('desired_rate');
    }

    public function test_desired_rate_at_max_passes(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['desired_rate' => 999]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_work_styles_invalid_value_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['work_styles' => ['invalid_value']]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('work_styles.0');
    }

    public function test_proc_field_invalid_boolean_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['proc_requirements' => 'invalid']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('proc_requirements');
    }

    public function test_has_negotiation_exp_invalid_value_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['has_negotiation_exp' => 'invalid']);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('has_negotiation_exp');
    }

    public function test_sub_user_id_must_differ_from_main_user_id(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['sub_user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('sub_user_id');
    }

    public function test_skill_label_is_required_when_detail_is_present(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'skills' => [['label' => '', 'detail' => 'Laravel 10年']],
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('skills.0.label');
    }

    public function test_name_kana_half_width_space_is_normalized_to_full_width(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name_kana' => 'ヤマダ タロウ']);

        $this->actingAs($user)->post('/engineers', $payload);

        $this->assertDatabaseHas('engineers', ['name_kana' => 'ヤマダ　タロウ']);
    }

    // #21-3: 氏名（name）も name_kana と同様に半角スペースを全角スペースへ正規化する
    public function test_name_half_width_space_is_normalized_to_full_width(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), ['name' => '山田 太郎']);

        $this->actingAs($user)->post('/engineers', $payload);

        $this->assertDatabaseHas('engineers', ['name' => '山田　太郎']);
    }

    // -------------------------------------------------------
    // store: POST /engineers — AI サマリ
    // -------------------------------------------------------

    public function test_ai_summary_and_generated_at_are_saved_when_engine_returns_text(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => 1,
                'ai_summary' => 'AI生成要約テキスト',
                'ai_summary_generated_at' => '2026-08-07T10:00:00+09:00',
            ], 200),
        ]);

        $response = $this->actingAs($user)->post(
            '/engineers',
            $this->validPayload($user->id) + ['appeal_note' => 'アピールポイント']
        );

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertSame('AI生成要約テキスト', $engineer->ai_summary);
        // Python 返却の生成時刻をそのまま採用する（now() で上書きしない）。datetime キャストで Carbon へ
        // 正規化して保存するため、文字列一致ではなく「同一時点」で検証する（TZ 表記差を吸収）。
        $this->assertEquals(Carbon::parse('2026-08-07T10:00:00+09:00'), $engineer->ai_summary_generated_at);
        // 成功時は失敗トースト（flash.error）を出さない。
        $response->assertSessionMissing('error');
    }

    public function test_engineer_is_saved_and_error_flash_set_when_ai_engine_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        // 上流 5xx（504 相当）。AI 要約は付加情報のため登録自体は成功させる。
        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response('gateway timeout', 504),
        ]);

        $response = $this->actingAs($user)->post(
            '/engineers',
            $this->validPayload($user->id) + ['appeal_note' => 'アピールポイント']
        );

        $engineer = Engineer::where('name', '山田太郎')->first();
        // 人材は保存され、要約だけ NULL のまま。
        $this->assertNotNull($engineer);
        $this->assertNull($engineer->ai_summary);
        $this->assertNull($engineer->ai_summary_generated_at);
        // 登録成功フラッシュ＋AI 失敗フラッシュの両方が出る。
        $response->assertSessionHas('success', '人材情報を登録しました。');
        $response->assertSessionHas('error');
    }

    public function test_ai_summary_is_null_and_no_error_flash_when_engine_returns_empty(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        // 空出力（要約対象なし）。失敗ではないためトーストは出さず NULL 据え置き（#12 §4.3）。
        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => 1,
                'ai_summary' => '',
                'ai_summary_generated_at' => '2026-08-07T10:00:00+09:00',
            ], 200),
        ]);

        $response = $this->actingAs($user)->post(
            '/engineers',
            $this->validPayload($user->id) + ['appeal_note' => 'アピールポイント']
        );

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertNull($engineer->ai_summary);
        $this->assertNull($engineer->ai_summary_generated_at);
        $response->assertSessionMissing('error');
    }

    public function test_ai_engine_is_not_called_when_appeal_note_is_empty_on_store(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        Http::fake();

        // validPayload は appeal_note を含まない → 生成トリガーが立たず E2 は呼ばれない。
        $this->actingAs($user)->post('/engineers', $this->validPayload($user->id));

        Http::assertNothingSent();
    }

    // -------------------------------------------------------
    // show: GET /engineers/{id}
    // -------------------------------------------------------

    // -------------------------------------------------------
    // AI サマリ状態管理（issue #61）
    // -------------------------------------------------------

    public function test_ai_summary_status_is_generated_and_hash_set_when_engine_returns_text(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => 1,
                'ai_summary' => 'AI生成要約テキスト',
                'ai_summary_generated_at' => '2026-08-07T10:00:00+09:00',
            ], 200),
        ]);

        $this->actingAs($user)->post(
            '/engineers',
            $this->validPayload($user->id) + ['appeal_note' => 'アピールポイント']
        );

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertSame('generated', $engineer->ai_summary_status);
        $this->assertSame(hash('sha256', 'アピールポイント'), $engineer->ai_summary_source_hash);
        $this->assertFalse($engineer->is_ai_summary_stale);
    }

    public function test_ai_summary_status_is_failed_when_engine_fails(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        Http::fake(['*/api/v1/ai/profile-summary' => Http::response('gateway timeout', 504)]);

        $this->actingAs($user)->post(
            '/engineers',
            $this->validPayload($user->id) + ['appeal_note' => 'アピールポイント']
        );

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertSame('failed', $engineer->ai_summary_status);
        $this->assertNull($engineer->ai_summary_source_hash);
    }

    public function test_ai_summary_status_is_empty_when_engine_returns_empty(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => 1,
                'ai_summary' => '',
                'ai_summary_generated_at' => '2026-08-07T10:00:00+09:00',
            ], 200),
        ]);

        $this->actingAs($user)->post(
            '/engineers',
            $this->validPayload($user->id) + ['appeal_note' => 'アピールポイント']
        );

        $engineer = Engineer::where('name', '山田太郎')->first();
        $this->assertSame('empty', $engineer->ai_summary_status);
        $this->assertNull($engineer->ai_summary);
    }

    public function test_update_clears_ai_summary_and_resets_status_to_none_when_appeal_note_cleared(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => '元のアピール',
            'ai_summary' => '既存の要約',
            'ai_summary_generated_at' => now(),
            'ai_summary_status' => 'generated',
            'ai_summary_source_hash' => hash('sha256', '元のアピール'),
        ]);

        Http::fake();

        $payload = array_merge($this->validPayload($user->id), ['appeal_note' => '']);
        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        Http::assertNothingSent();
        $engineer = $engineer->fresh();
        $this->assertNull($engineer->ai_summary);
        $this->assertNull($engineer->ai_summary_generated_at);
        $this->assertSame('none', $engineer->ai_summary_status);
        $this->assertNull($engineer->ai_summary_source_hash);
    }

    public function test_regenerate_ai_summary_updates_status_and_hash_on_success(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => '再生成対象のアピール',
            'ai_summary_status' => 'failed',
        ]);

        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => $engineer->id,
                'ai_summary' => '再生成成功後の要約',
                'ai_summary_generated_at' => '2026-08-10T09:00:00+09:00',
            ], 200),
        ]);

        $response = $this->actingAs($user)->post("/engineers/{$engineer->id}/ai-summary/regenerate");

        $response->assertRedirect(route('engineers.show', $engineer));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $engineer = $engineer->fresh();
        $this->assertSame('再生成成功後の要約', $engineer->ai_summary);
        $this->assertSame('generated', $engineer->ai_summary_status);
        $this->assertSame(hash('sha256', '再生成対象のアピール'), $engineer->ai_summary_source_hash);
    }

    public function test_regenerate_ai_summary_sets_failed_status_and_error_flash_on_failure(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => '再生成対象のアピール',
        ]);

        Http::fake(['*/api/v1/ai/profile-summary' => Http::response('gateway timeout', 504)]);

        $response = $this->actingAs($user)->post("/engineers/{$engineer->id}/ai-summary/regenerate");

        $response->assertRedirect(route('engineers.show', $engineer));
        $response->assertSessionHas('error');
        $this->assertSame('failed', $engineer->fresh()->ai_summary_status);
    }

    public function test_regenerate_ai_summary_does_not_call_engine_when_appeal_note_is_blank(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => null,
        ]);

        Http::fake();

        $this->actingAs($user)->post("/engineers/{$engineer->id}/ai-summary/regenerate");

        Http::assertNothingSent();
        $this->assertSame('none', $engineer->fresh()->ai_summary_status);
    }

    public function test_show_props_report_stale_ai_summary_after_failed_regeneration_following_appeal_note_change(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => '元のアピール',
            'ai_summary' => '元のアピールに基づく要約',
            'ai_summary_generated_at' => now(),
            'ai_summary_status' => 'generated',
            'ai_summary_source_hash' => hash('sha256', '元のアピール'),
        ]);

        // appeal_note を変更して保存 → 再生成がトリガーされるが上流障害で失敗 → 古い要約が残ったまま stale になる。
        Http::fake(['*/api/v1/ai/profile-summary' => Http::response('gateway timeout', 504)]);
        $payload = array_merge($this->validPayload($user->id), ['appeal_note' => '変更後のアピール']);
        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.ai_summary', '元のアピールに基づく要約')
            ->where('engineer.ai_summary_status', 'failed')
            ->where('engineer.is_ai_summary_stale', true)
        );
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page->has('engineer'));
    }

    public function test_show_props_contain_correct_engineer_data(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'name' => '田中花子',
            'name_kana' => 'タナカハナコ',
            'status' => 'interviewing',
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'available_from' => null,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.available_label', '未定')
        );
    }

    public function test_show_props_available_label_is_formatted_date_when_available_from_is_set(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'available_from' => '2026-08-01',
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.available_label', '2026/08/01〜')
        );
    }

    public function test_show_props_pipelines_count_is_zero_when_no_pipelines(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.pipelines_count', 0)
        );
    }

    public function test_show_props_pipelines_count_matches_related_pipelines(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Pipeline::factory()->count(3)->create(['engineer_id' => $engineer->id]);
        // 別人材のパイプラインは件数に含めない。
        Pipeline::factory()->create();

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.pipelines_count', 3)
        );
    }

    public function test_show_props_age_is_null_when_birth_date_is_null(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'birth_date' => null,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.age', null)
        );
    }

    public function test_show_props_age_is_calculated_from_birth_date(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'birth_date' => now()->subYears(30)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.age', 30)
        );
    }

    public function test_show_props_skills_include_detail(): void
    {
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'proc_requirements' => true,
            'proc_development' => true,
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'sub_user_id' => null,
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}");

        $response->assertInertia(fn ($page) => $page
            ->where('engineer.users.sub', null)
        );
    }

    public function test_show_props_sub_user_is_returned_when_set(): void
    {
        $mainUser = User::factory()->create();
        $subUser = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $subUser->id,
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
        $admin = User::factory()->create(['role' => 'admin']);
        $engineer = Engineer::factory()->create(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/engineers/{$engineer->id}");

        $response->assertRedirect('/engineers');
        $this->assertDatabaseMissing('engineers', ['id' => $engineer->id]);
    }

    public function test_destroy_sets_success_flash_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $engineer = Engineer::factory()->create(['main_user_id' => $admin->id]);

        $response = $this->actingAs($admin)->delete("/engineers/{$engineer->id}");

        $response->assertSessionHas('success', '人材情報を削除しました。');
    }

    public function test_general_user_cannot_delete_engineer(): void
    {
        $user = User::factory()->create(['role' => 'general']);
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        // 削除は人材詳細画面から実行されるため、referer は詳細URL自身になる。
        $response = $this->actingAs($user)->delete(
            "/engineers/{$engineer->id}",
            [],
            ['X-Inertia' => 'true', 'referer' => "/engineers/{$engineer->id}"]
        );

        // 設計書 DELETE #7：権限不足は 403 を素で投げず、前画面（＝同じ詳細画面）へ戻し
        // flash.error を返す。redirect先を固定しないと一覧へ飛ばす誤実装でも通ってしまうため、
        // referer を明示してリダイレクト先まで検証する（StaleResourceHandlingTestと同粒度）。
        $response->assertStatus(303);
        $response->assertRedirect("/engineers/{$engineer->id}");
        $response->assertSessionHas('error', '削除権限がありません。');
        $this->assertDatabaseHas('engineers', ['id' => $engineer->id]);
    }

    public function test_general_user_delete_engineer_without_referer_falls_back_to_dashboard(): void
    {
        // referer が無い場合（直接リクエスト等）でも flash.error を失わずダッシュボードへ戻る。
        $user = User::factory()->create(['role' => 'general']);
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->delete("/engineers/{$engineer->id}");

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error', '削除権限がありません。');
        $this->assertDatabaseHas('engineers', ['id' => $engineer->id]);
    }

    public function test_destroy_returns_404_for_non_existent_engineer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->delete('/engineers/99999');

        $response->assertNotFound();
    }

    // -------------------------------------------------------
    // store: POST /engineers — appeal_note / remarks の文字数上限
    // （PostTooLargeException対策として EngineerRules に定義済みの max ルールの境界値。
    //   案件側 ProjectControllerTest の description/work_env/remarks と同方式）
    // -------------------------------------------------------

    public function test_appeal_note_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'appeal_note' => str_repeat('あ', 4001),
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('appeal_note');
    }

    public function test_appeal_note_is_accepted_at_max_length_4000(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();

        // appeal_note が非空で保存されるとAI要約生成が走るため、実HTTP呼び出しを避けるためにfakeする。
        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => 1,
                'ai_summary' => 'AI生成要約テキスト',
                'ai_summary_generated_at' => '2026-08-07T10:00:00+09:00',
            ], 200),
        ]);

        $appealNote = str_repeat('あ', 4000);
        $payload    = array_merge($this->validPayload($user->id), [
            'appeal_note' => $appealNote,
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('engineers', [
            'appeal_note' => $appealNote,
        ]);
    }

    public function test_remarks_exceeding_max_length_fails(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $payload = array_merge($this->validPayload($user->id), [
            'remarks' => str_repeat('あ', 1001),
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionHasErrors('remarks');
    }

    public function test_remarks_is_accepted_at_max_length_1000(): void
    {
        $this->seedFormFieldSettings();
        $user    = User::factory()->create();
        $remarks = str_repeat('あ', 1000);
        $payload = array_merge($this->validPayload($user->id), [
            'remarks' => $remarks,
        ]);

        $response = $this->actingAs($user)->post('/engineers', $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('engineers', [
            'remarks' => $remarks,
        ]);
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Engineers/Edit'));
    }

    public function test_edit_page_props_contain_required_keys(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'name' => '佐藤花子',
            'name_kana' => 'サトウハナコ',
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'name' => '旧氏名',
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $this->validPayload($user->id));

        $response->assertSessionHas('success', '人材情報を更新しました。');
    }

    public function test_update_replaces_skills_with_submitted_list(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'work_style_onsite' => true,
            'work_style_hybrid' => true,
            'work_style_remote' => true,
        ]);

        $payload = array_merge($this->validPayload($user->id), ['work_styles' => ['hybrid']]);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $this->assertDatabaseHas('engineers', [
            'id' => $engineer->id,
            'work_style_onsite' => false,
            'work_style_hybrid' => true,
            'work_style_remote' => false,
        ]);
    }

    public function test_update_regenerates_ai_summary_when_appeal_note_changes(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => '元のアピール',
        ]);

        Http::fake([
            '*/api/v1/ai/profile-summary' => Http::response([
                'engineer_id' => $engineer->id,
                'ai_summary' => '再生成された要約',
                'ai_summary_generated_at' => '2026-08-07T11:00:00+09:00',
            ], 200),
        ]);

        $payload = array_merge($this->validPayload($user->id), ['appeal_note' => '更新後のアピール']);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/ai/profile-summary'));
        $engineer = $engineer->fresh();
        $this->assertSame('再生成された要約', $engineer->ai_summary);
        $this->assertEquals(Carbon::parse('2026-08-07T11:00:00+09:00'), $engineer->ai_summary_generated_at);
    }

    public function test_update_does_not_regenerate_ai_summary_when_appeal_note_unchanged(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => 'そのままのアピール',
        ]);

        Http::fake();

        $payload = array_merge($this->validPayload($user->id), ['appeal_note' => 'そのままのアピール']);

        $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        // appeal_note が変わらないため AI 生成 API は呼ばれない。
        Http::assertNothingSent();
        $this->assertSame('そのままのアピール', $engineer->fresh()->appeal_note);
    }

    public function test_update_validates_required_fields(): void
    {
        $this->seedFormFieldSettings();
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $payload = $this->validPayload($user->id);
        unset($payload['name']);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_appeal_note_exceeding_max_length_fails_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $payload  = array_merge($this->validPayload($user->id), [
            'appeal_note' => str_repeat('あ', 4001),
        ]);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertSessionHasErrors('appeal_note');
    }

    public function test_appeal_note_is_accepted_at_max_length_4000_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user       = User::factory()->create();
        $appealNote = str_repeat('あ', 4000);
        // 更新前後で appeal_note を変更しない（AI要約再生成トリガーを踏まないため、実HTTP呼び出しが不要になる）。
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note'  => $appealNote,
        ]);

        Http::fake();

        $payload = array_merge($this->validPayload($user->id), [
            'appeal_note' => $appealNote,
        ]);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertSessionDoesntHaveErrors();
        Http::assertNothingSent();
        $this->assertDatabaseHas('engineers', [
            'id'          => $engineer->id,
            'appeal_note' => $appealNote,
        ]);
    }

    public function test_remarks_exceeding_max_length_fails_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $payload  = array_merge($this->validPayload($user->id), [
            'remarks' => str_repeat('あ', 1001),
        ]);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertSessionHasErrors('remarks');
    }

    public function test_remarks_is_accepted_at_max_length_1000_on_update(): void
    {
        $this->seedFormFieldSettings();
        $user     = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $remarks  = str_repeat('あ', 1000);
        $payload  = array_merge($this->validPayload($user->id), [
            'remarks' => $remarks,
        ]);

        $response = $this->actingAs($user)->put("/engineers/{$engineer->id}", $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('engineers', [
            'id'      => $engineer->id,
            'remarks' => $remarks,
        ]);
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

    public function test_index_saved_searches_only_include_engineer_type_for_current_user(): void
    {
        // ProjectController@index とコピペで search_type を取り違えやすい箇所の回帰防止。
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $engineerSearch = SavedSearch::create([
            'user_id' => $user->id, 'name' => '自分のengineer検索', 'search_type' => 'engineer',
            'conditions' => ['status' => [], 'work_styles' => [], 'phases' => [], 'keyword' => '', 'sort' => '', 'order' => ''],
        ]);
        SavedSearch::create([
            'user_id' => $user->id, 'name' => '自分のproject検索', 'search_type' => 'project',
            'conditions' => ['status' => [], 'work_style' => [], 'commercial_flow' => [], 'interview_count' => [], 'keyword' => '', 'sort' => '', 'order' => ''],
        ]);
        SavedSearch::create([
            'user_id' => $otherUser->id, 'name' => '他人のengineer検索', 'search_type' => 'engineer',
            'conditions' => ['status' => [], 'work_styles' => [], 'phases' => [], 'keyword' => '', 'sort' => '', 'order' => ''],
        ]);

        $response = $this->actingAs($user)->get('/engineers');

        $response->assertInertia(fn ($page) => $page
            ->count('savedSearches', 1)
            ->where('savedSearches.0.id', $engineerSearch->id)
            ->where('savedSearches.0.name', '自分のengineer検索')
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

    public function test_index_filters_status_with_or_logic(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create(['main_user_id' => $user->id, 'status' => 'proposable']);
        Engineer::factory()->create(['main_user_id' => $user->id, 'status' => 'interviewing']);
        Engineer::factory()->create(['main_user_id' => $user->id, 'status' => 'not_proposable']);

        $response = $this->actingAs($user)
            ->get('/engineers?status[]=proposable&status[]=interviewing');

        // proposable OR interviewing の 2 件のみヒット（not_proposable は除外）
        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 2)
            ->where('engineers.data', fn ($data) => collect($data)
                ->pluck('status')->sort()->values()->all() === ['interviewing', 'proposable'])
        );
    }

    public function test_index_filters_work_styles_with_or_logic(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'work_style_onsite' => true,
            'work_style_hybrid' => false,
            'work_style_remote' => false,
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'work_style_onsite' => false,
            'work_style_hybrid' => false,
            'work_style_remote' => true,
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
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
            'main_user_id' => $user->id,
            'proc_development' => true,
            'proc_testing' => true,
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'proc_development' => true,
            'proc_testing' => false,
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'proc_development' => false,
            'proc_testing' => true,
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

    public function test_index_does_not_search_skill_by_partial_match(): void
    {
        $user = User::factory()->create();
        // 氏名は 'Native' を含まない。スキルは前方一致のみのため 'ReactNative' は 'Native' で非ヒットになるべき。
        $e = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'Cさん']);
        $e->skills()->create(['label' => 'ReactNative', 'detail' => null]);

        $response = $this->actingAs($user)->get('/engineers?keyword=Native');

        // スキルは LIKE 'Native%'（前方一致）。実装が部分一致 '%Native%' に退行すると ReactNative がヒットして落ちる。
        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 0)
        );
    }

    public function test_index_searches_keyword_by_name_or_skill(): void
    {
        $user = User::factory()->create();

        // A：氏名で部分一致（%Ruby%）／スキルは無関係
        $a = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'RubyDev']);
        $a->skills()->create(['label' => 'COBOL', 'detail' => null]);

        // B：氏名は非ヒット／スキルで前方一致（Ruby%）
        $b = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'Sato']);
        $b->skills()->create(['label' => 'Ruby', 'detail' => null]);

        // C（対照）：氏名・スキルとも非ヒット
        $c = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'Tanaka']);
        $c->skills()->create(['label' => 'Java', 'detail' => null]);

        $response = $this->actingAs($user)->get('/engineers?keyword=Ruby');

        // 氏名一致(A) と スキル一致(B) の OR が同一クエリで両立し、両方ヒット・C は除外
        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 2)
            ->where('engineers.data', fn ($data) => collect($data)
                ->pluck('name')->sort()->values()->all() === ['RubyDev', 'Sato'])
        );
    }

    public function test_index_escapes_backslash_in_keyword(): void
    {
        // LIKE のバックスラッシュエスケープは DB の「LIKE 既定エスケープ文字」に依存する。
        // 本番の MySQL は既定エスケープが '\' のため、パターン中の '\\' は \ リテラルにマッチする。
        // 一方テスト用の SQLite は ESCAPE 句なしでは '\' をエスケープ扱いしない（'\\' は \ 2 個の
        // リテラル）ため、この検証は本番と同じ MySQL 接続でのみ意味を持つ。SQLite ではスキップする。
        if (\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('LIKE のバックスラッシュエスケープ検証は MySQL でのみ有効（SQLite は LIKE の既定エスケープを持たない）。');
        }

        $user = User::factory()->create();
        // A：バックスラッシュを含む氏名（リテラルで一致させたい対象）
        Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'a\\b']);
        // B（対照）：バックスラッシュを含まない 'ab'。未エスケープだと \b が消えて '%ab%' 扱いになり誤ヒットする。
        Engineer::factory()->create(['main_user_id' => $user->id, 'name' => 'ab']);

        $response = $this->actingAs($user)->get('/engineers?keyword='.urlencode('a\\b'));

        // バックスラッシュがリテラルとして扱われ、'a\b' のみヒット（'ab' は非ヒット）
        $response->assertInertia(fn ($page) => $page
            ->count('engineers.data', 1)
            ->where('engineers.data.0.name', 'a\\b')
        );
    }

    public function test_index_accepts_keyword_at_max_length_100(): void
    {
        $user = User::factory()->create();

        // 100 文字（氏名 max:100 と同上限）は許容される
        $keyword = str_repeat('a', 100);
        $response = $this->actingAs($user)->get('/engineers?keyword='.$keyword);

        $response->assertOk();
        $response->assertSessionHasNoErrors();
        $response->assertInertia(fn ($page) => $page->where('filters.keyword', $keyword));
    }

    public function test_index_rejects_keyword_over_100(): void
    {
        $user = User::factory()->create();

        // 101 文字はサーバ側 FormRequest で弾く（フロント maxLength の安全網）
        $response = $this->actingAs($user)->get('/engineers?keyword='.str_repeat('a', 101));

        $response->assertSessionHasErrors('keyword');
    }

    public function test_index_does_not_search_keyword_by_appeal_note(): void
    {
        $user = User::factory()->create();
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'name' => 'Aさん',
            'appeal_note' => 'バックエンド開発が得意です',
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'name' => 'Bさん',
            'appeal_note' => 'デザインが得意です',
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
        $old = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '古い']);
        $old->created_at = now()->subDays(5);
        $old->save();
        $new = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '新しい']);
        $new->created_at = now();
        $new->save();

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
            'main_user_id' => $user->id,
            'name' => 'A',
            'available_from' => '2026-08-01',
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'name' => 'B',
            'available_from' => null,
        ]);
        Engineer::factory()->create([
            'main_user_id' => $user->id,
            'name' => 'C',
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
            .'&keyword=Java&sort=available_from&order=asc&per_page=10&page=1'
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
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'appeal_note' => 'これは表示されないはず',
            'remarks' => '特記事項も表示されないはず',
            'ai_summary' => 'AI要約も表示されないはず',
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

    public function test_index_sort_by_updated_at_desc(): void
    {
        $user = User::factory()->create();
        $e1 = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '古い更新']);
        $e1->updated_at = now()->subDays(3);
        $e1->saveQuietly();
        $e2 = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '新しい更新']);
        $e2->updated_at = now();
        $e2->saveQuietly();

        $response = $this->actingAs($user)->get('/engineers?sort=updated_at&order=desc');

        $response->assertInertia(fn ($page) => $page
            ->where('engineers.data.0.name', '新しい更新')
            ->where('engineers.data.1.name', '古い更新')
        );
    }

    public function test_index_tiebreak_by_id_asc_when_sort_key_is_equal(): void
    {
        $user = User::factory()->create();
        $now = now()->toDateTimeString();

        // created_at を同値に揃えて id の昇順が効くことを確認する
        $e1 = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '先に登録']);
        $e2 = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '後に登録']);
        \DB::table('engineers')->whereIn('id', [$e1->id, $e2->id])->update(['created_at' => $now]);

        $response = $this->actingAs($user)->get('/engineers?sort=created_at&order=desc');

        $response->assertInertia(fn ($page) => $page
            ->where('engineers.data.0.name', '先に登録')   // id が小さい方が先
            ->where('engineers.data.1.name', '後に登録')
        );
    }

    public function test_index_invalid_sort_key_falls_back_to_created_at_desc(): void
    {
        $user = User::factory()->create();
        $old = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '古い']);
        $old->created_at = now()->subDays(5);
        $old->saveQuietly();
        $new = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '新しい']);

        // order は省略 → デフォルト desc が適用される
        $response = $this->actingAs($user)->get('/engineers?sort=invalid_key');

        // sort が無効なので created_at DESC にフォールバック → 新しい方が先
        $response->assertInertia(fn ($page) => $page
            ->where('engineers.data.0.name', '新しい')
            ->where('filters.sort', 'created_at')
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_invalid_sort_key_with_asc_also_falls_back_to_desc(): void
    {
        $user = User::factory()->create();
        $old = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '古い']);
        $old->created_at = now()->subDays(5);
        $old->saveQuietly();
        $new = Engineer::factory()->create(['main_user_id' => $user->id, 'name' => '新しい']);

        // sort が無効なら order=asc が指定されていても desc にリセットされる
        $response = $this->actingAs($user)->get('/engineers?sort=invalid_key&order=asc');

        $response->assertInertia(fn ($page) => $page
            ->where('engineers.data.0.name', '新しい')
            ->where('filters.sort', 'created_at')
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_invalid_order_falls_back_to_desc(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers?sort=created_at&order=INVALID');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_disallowed_sort_order_pair_updated_at_asc_falls_back_to_default(): void
    {
        $user = User::factory()->create();

        // updated_at:asc は DB設計書 §8 の4パターンに存在しない仕様外の組み合わせ。
        // sort・order は個別には有効値だが、ペアとして許可されていないためデフォルトへフォールバックする。
        $response = $this->actingAs($user)->get('/engineers?sort=updated_at&order=asc');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'created_at')
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_disallowed_sort_order_pair_available_from_desc_falls_back_to_default(): void
    {
        $user = User::factory()->create();

        // available_from:desc も許可4組に無い仕様外の組み合わせ（許可は available_from:asc のみ）。
        $response = $this->actingAs($user)->get('/engineers?sort=available_from&order=desc');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'created_at')
            ->where('filters.order', 'desc')
        );
    }

    public function test_index_provides_sort_options_from_backend_as_ssot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/engineers');

        // 許可組はバックエンド一本化（SSOT）。props に4組の sort×order＋label が渡り、先頭がデフォルト。
        $response->assertInertia(fn ($page) => $page
            ->has('sortOptions', 4)
            ->where('sortOptions.0.sort', 'created_at')
            ->where('sortOptions.0.order', 'desc')
            ->where('sortOptions.0.label', '登録日順（新しい順）')
            ->where('sortOptions.3.sort', 'available_from')
            ->where('sortOptions.3.order', 'asc')
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
