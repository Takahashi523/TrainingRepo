<?php

namespace Tests\Feature;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------

    private function validEngineerPayload(): array
    {
        return [
            'name' => '提案可・リモートのエンジニア',
            'search_type' => 'engineer',
            'conditions' => [
                'status' => ['proposable'],
                'work_styles' => ['remote'],
                'phases' => ['proc_development'],
                'keyword' => 'Java',
                'sort' => 'created_at',
                'order' => 'desc',
            ],
        ];
    }

    private function validProjectPayload(): array
    {
        return [
            'name' => '募集中のプライム案件',
            'search_type' => 'project',
            'conditions' => [
                'status' => ['open'],
                'work_style' => ['remote'],
                'commercial_flow' => ['prime'],
                'interview_count' => [1, 2],
                'keyword' => 'AI',
                'sort' => 'created_at',
                'order' => 'desc',
            ],
        ];
    }

    private function createSavedSearch(array $overrides = []): SavedSearch
    {
        return SavedSearch::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => 'テスト検索',
            'search_type' => 'engineer',
            'conditions' => [
                'status' => [], 'work_styles' => [], 'phases' => [],
                'keyword' => '', 'sort' => '', 'order' => '',
            ],
        ], $overrides));
    }

    // -------------------------------------------------------
    // store: POST /saved-searches — 正常系
    // -------------------------------------------------------

    public function test_guest_cannot_post_to_store(): void
    {
        $response = $this->post('/saved-searches', $this->validEngineerPayload());

        $response->assertRedirect('/login');
    }

    public function test_engineer_saved_search_is_stored(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/saved-searches', $this->validEngineerPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('saved_searches', [
            'user_id' => $user->id,
            'name' => '提案可・リモートのエンジニア',
            'search_type' => 'engineer',
        ]);
    }

    public function test_project_saved_search_is_stored(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/saved-searches', $this->validProjectPayload());

        $response->assertRedirect();
        $this->assertDatabaseHas('saved_searches', [
            'user_id' => $user->id,
            'name' => '募集中のプライム案件',
            'search_type' => 'project',
        ]);
    }

    public function test_store_sets_success_flash_message(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/saved-searches', $this->validEngineerPayload());

        $response->assertSessionHas('success', '検索条件を保存しました。');
    }

    public function test_user_id_cannot_be_overridden_by_client(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $payload = array_merge($this->validEngineerPayload(), ['user_id' => $otherUser->id]);

        $this->actingAs($user)->post('/saved-searches', $payload);

        $this->assertDatabaseHas('saved_searches', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('saved_searches', ['user_id' => $otherUser->id]);
    }

    // -------------------------------------------------------
    // store: POST /saved-searches — バリデーション
    // -------------------------------------------------------

    public function test_name_is_required(): void
    {
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        unset($payload['name']);

        $response = $this->actingAs($user)->post('/saved-searches', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_name_exceeding_max_length_fails(): void
    {
        $user = User::factory()->create();
        $payload = array_merge($this->validEngineerPayload(), ['name' => str_repeat('あ', 101)]);

        $response = $this->actingAs($user)->post('/saved-searches', $payload);

        $response->assertSessionHasErrors('name');
    }

    public function test_search_type_is_required(): void
    {
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        unset($payload['search_type']);

        $response = $this->actingAs($user)->post('/saved-searches', $payload);

        $response->assertSessionHasErrors('search_type');
    }

    public function test_search_type_must_be_valid_enum(): void
    {
        $user = User::factory()->create();
        $payload = array_merge($this->validEngineerPayload(), ['search_type' => 'unknown']);

        $response = $this->actingAs($user)->post('/saved-searches', $payload);

        $response->assertSessionHasErrors('search_type');
    }

    public function test_conditions_is_required(): void
    {
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        unset($payload['conditions']);

        $response = $this->actingAs($user)->post('/saved-searches', $payload);

        $response->assertSessionHasErrors('conditions');
    }

    // -------------------------------------------------------
    // store: POST /saved-searches — conditions のサニタイズ（engineer）
    // -------------------------------------------------------

    public function test_engineer_conditions_invalid_status_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        $payload['conditions']['status'] = ['proposable', 'hacked_status'];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['proposable'], $saved->conditions['status']);
    }

    public function test_engineer_conditions_invalid_work_style_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        $payload['conditions']['work_styles'] = ['remote', 'invalid_style'];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['remote'], $saved->conditions['work_styles']);
    }

    public function test_engineer_conditions_invalid_phase_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        $payload['conditions']['phases'] = ['proc_development', 'invalid_phase'];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['proc_development'], $saved->conditions['phases']);
    }

    public function test_engineer_conditions_keyword_sort_order_are_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/saved-searches', $this->validEngineerPayload());

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame('Java', $saved->conditions['keyword']);
        $this->assertSame('created_at', $saved->conditions['sort']);
        $this->assertSame('desc', $saved->conditions['order']);
    }

    public function test_engineer_conditions_invalid_sort_order_pair_falls_back_to_default(): void
    {
        // EngineerController::SORT_OPTIONS に存在しない組み合わせは、
        // EngineerController::resolveSort() と同じくデフォルト（先頭）へフォールバックする。
        $user = User::factory()->create();
        $payload = $this->validEngineerPayload();
        $payload['conditions']['sort'] = 'available_from';
        $payload['conditions']['order'] = 'desc'; // available_from は asc のみ許可

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame('created_at', $saved->conditions['sort']);
        $this->assertSame('desc', $saved->conditions['order']);
    }

    // -------------------------------------------------------
    // store: POST /saved-searches — conditions のサニタイズ（project）
    // -------------------------------------------------------

    public function test_project_conditions_invalid_status_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validProjectPayload();
        $payload['conditions']['status'] = ['open', 'hacked_status'];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['open'], $saved->conditions['status']);
    }

    public function test_project_conditions_keyword_sort_order_are_stored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/saved-searches', $this->validProjectPayload());

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame('AI', $saved->conditions['keyword']);
        $this->assertSame('created_at', $saved->conditions['sort']);
        $this->assertSame('desc', $saved->conditions['order']);
    }

    public function test_project_conditions_invalid_sort_value_falls_back_to_default(): void
    {
        // ProjectController::SORT_OPTIONS に存在しないソート項目名は、
        // ProjectController::resolveSort() と同じくデフォルト（先頭）へフォールバックする。
        $user = User::factory()->create();
        $payload = $this->validProjectPayload();
        $payload['conditions']['sort'] = 'malicious_column';
        $payload['conditions']['order'] = 'desc';

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame('created_at', $saved->conditions['sort']);
        $this->assertSame('desc', $saved->conditions['order']);
    }

    public function test_project_conditions_use_singular_keys(): void
    {
        // 07番の仕様どおり work_style / commercial_flow / interview_count は単数形。
        // ここが複数形（work_styles 等）に退行すると常に空配列で保存されてしまう回帰防止。
        $user = User::factory()->create();

        $this->actingAs($user)->post('/saved-searches', $this->validProjectPayload());

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['remote'], $saved->conditions['work_style']);
        $this->assertSame(['prime'], $saved->conditions['commercial_flow']);
        $this->assertSame([1, 2], $saved->conditions['interview_count']);
    }

    public function test_project_conditions_invalid_work_style_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validProjectPayload();
        $payload['conditions']['work_style'] = ['remote', 'invalid_style'];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['remote'], $saved->conditions['work_style']);
    }

    public function test_project_conditions_invalid_commercial_flow_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validProjectPayload();
        $payload['conditions']['commercial_flow'] = ['prime', 'invalid_flow'];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame(['prime'], $saved->conditions['commercial_flow']);
    }

    public function test_project_conditions_invalid_interview_count_is_removed(): void
    {
        $user = User::factory()->create();
        $payload = $this->validProjectPayload();
        $payload['conditions']['interview_count'] = [1, 999];

        $this->actingAs($user)->post('/saved-searches', $payload);

        $saved = SavedSearch::where('user_id', $user->id)->first();
        $this->assertSame([1], $saved->conditions['interview_count']);
    }

    // -------------------------------------------------------
    // destroy: DELETE /saved-searches/{id}
    // -------------------------------------------------------

    public function test_guest_cannot_delete_saved_search(): void
    {
        $savedSearch = $this->createSavedSearch();

        $response = $this->delete("/saved-searches/{$savedSearch->id}");

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('saved_searches', ['id' => $savedSearch->id]);
    }

    public function test_owner_can_delete_own_saved_search(): void
    {
        $user = User::factory()->create();
        $savedSearch = $this->createSavedSearch(['user_id' => $user->id]);

        $response = $this->actingAs($user)->delete("/saved-searches/{$savedSearch->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    }

    public function test_destroy_sets_success_flash_message(): void
    {
        $user = User::factory()->create();
        $savedSearch = $this->createSavedSearch(['user_id' => $user->id]);

        $response = $this->actingAs($user)->delete("/saved-searches/{$savedSearch->id}");

        $response->assertSessionHas('success', '検索条件を削除しました。');
    }

    public function test_user_cannot_delete_other_users_saved_search(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $savedSearch = $this->createSavedSearch(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->delete("/saved-searches/{$savedSearch->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('saved_searches', ['id' => $savedSearch->id]);
    }

    public function test_destroy_returns_404_for_non_existent_saved_search(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete('/saved-searches/99999');

        $response->assertNotFound();
    }

    // -------------------------------------------------------
    // DB設計：ユーザー削除時の連動削除（ON DELETE CASCADE）
    // -------------------------------------------------------

    public function test_saved_searches_are_deleted_when_owning_user_is_deleted(): void
    {
        $user = User::factory()->create();
        $savedSearch = $this->createSavedSearch(['user_id' => $user->id]);

        $user->delete();

        $this->assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    }
}
