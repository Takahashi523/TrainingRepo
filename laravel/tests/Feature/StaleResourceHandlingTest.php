<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 削除済みリソースへの操作で発生する 404 のグローバルハンドリング（issue #44）。
 *
 * 「404 をリダイレクトに差し替える条件」は 4 つの分岐（Inertia か／ルートモデルバインディング
 * 由来か／対応表にあるか／メソッドが安全か）で構成される。ここでは各分岐を真・偽の両方で通し、
 * 「差し替えるべきでないものが差し替わっていない（404 が握りつぶされていない）」ことも検証する。
 */
class StaleResourceHandlingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Inertia のリクエストとして扱わせるためのヘッダー。
     *
     * GET はバージョン不一致だと Inertia ミドルウェアが 409（強制リロード）を返すため、
     * サーバーと同じ計算方法（マニフェストのハッシュ）でバージョンを合わせる。
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        ];
    }

    // -------------------------------------------------------
    // 案件（AC-1 / AC-2 / AC-3）
    // -------------------------------------------------------

    public function test_inertia_delete_on_deleted_project_redirects_to_index_with_flash(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->delete('/projects/99999', [], $this->inertiaHeaders());

        // Inertia は PUT/PATCH/DELETE への 302 を追えないため 303 であること
        $response->assertStatus(303);
        $response->assertRedirect('/projects');
        $response->assertSessionHas('error', '対象の案件が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_get_edit_on_deleted_project_redirects_to_index_with_flash(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/projects/99999/edit', $this->inertiaHeaders());

        $response->assertStatus(302);
        $response->assertRedirect('/projects');
        $response->assertSessionHas('error', '対象の案件が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_put_on_deleted_project_redirects_to_index_with_flash(): void
    {
        // 編集画面を開いている間に別ユーザーが削除したケース（並行削除）。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->put('/projects/99999', ['name' => '編集中だった案件'], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/projects');
        $response->assertSessionHas('error', '対象の案件が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_get_show_on_deleted_project_redirects_to_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/projects/99999', $this->inertiaHeaders());

        $response->assertRedirect('/projects');
        $response->assertSessionHas('error', '対象の案件が見つかりません。既に削除された可能性があります。');
    }

    // -------------------------------------------------------
    // 人材（AC-1 / AC-2 / AC-3 / AC-4）
    // -------------------------------------------------------

    public function test_inertia_delete_on_deleted_engineer_redirects_to_engineer_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->delete('/engineers/99999', [], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/engineers');
        $response->assertSessionHas('error', '対象の人材が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_get_edit_on_deleted_engineer_redirects_to_engineer_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/engineers/99999/edit', $this->inertiaHeaders());

        $response->assertRedirect('/engineers');
        $response->assertSessionHas('error', '対象の人材が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_put_on_deleted_engineer_redirects_to_engineer_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->put('/engineers/99999', ['name' => '編集中だった人材'], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/engineers');
        $response->assertSessionHas('error', '対象の人材が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_get_matching_on_deleted_engineer_redirects_to_engineer_index(): void
    {
        // ネストしたルート（engineers/{engineer}/matching）でも、URL ではなく
        // モデルクラスで戻り先を決めているため人材一覧へ戻ること。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/engineers/99999/matching', $this->inertiaHeaders());

        $response->assertRedirect('/engineers');
        $response->assertSessionHas('error', '対象の人材が見つかりません。既に削除された可能性があります。');
    }

    // -------------------------------------------------------
    // 進捗管理・マスタ管理・保存済み条件（AC-4）
    // -------------------------------------------------------

    public function test_inertia_patch_on_deleted_pipeline_redirects_to_pipeline_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patch('/pipelines/99999', ['status' => 'proposed'], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/pipelines');
        $response->assertSessionHas('error', '対象のパイプラインが見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_delete_on_deleted_pipeline_redirects_to_pipeline_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->delete('/pipelines/99999', [], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/pipelines');
        $response->assertSessionHas('error', '対象のパイプラインが見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_delete_on_deleted_master_user_redirects_to_master_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->delete('/master/users/99999', [], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/master');
        $response->assertSessionHas('error', '対象のユーザーが見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_delete_on_deleted_saved_search_redirects_back(): void
    {
        // 保存済み条件は専用の一覧を持たないため、操作元の画面へ戻す。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->delete('/saved-searches/99999', [], $this->inertiaHeaders() + ['referer' => '/engineers']);

        $response->assertStatus(303);
        $response->assertRedirect('/engineers');
        $response->assertSessionHas('error', '対象の検索条件が見つかりません。既に削除された可能性があります。');
    }

    public function test_inertia_delete_on_deleted_saved_search_falls_back_to_dashboard(): void
    {
        // 参照元が取れない場合でも行き先を失わないこと。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->delete('/saved-searches/99999', [], $this->inertiaHeaders());

        $response->assertRedirect('/dashboard');
    }

    // -------------------------------------------------------
    // 差し替えてはいけないケース（AC-5・回帰ガード）
    // -------------------------------------------------------

    public function test_non_inertia_request_still_returns_404(): void
    {
        // API・CSV ダウンロード・既存テストのような非 Inertia リクエストは 404 のまま。
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->delete('/projects/99999');

        $response->assertNotFound();
        $this->assertNull(session('error'));
    }

    public function test_inertia_request_to_undefined_url_still_returns_404(): void
    {
        // ルート自体が存在しない 404（ModelNotFoundException 由来でない）は差し替えない。
        // ここが共通エラーページ（issue #70）が受け持つ側になる。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/no-such-page', $this->inertiaHeaders());

        $response->assertNotFound();
    }

    public function test_general_user_is_not_redirected_into_admin_only_screen(): void
    {
        // ルートモデルバインディング（web グループ）は admin ミドルウェアより先に走るため、
        // 一般ユーザーがマスタ管理の URL を直接叩くと 403 ではなく 404 になる（本対応前からの挙動）。
        // 差し替えると「開けない画面へのリダイレクト」になるため、従来どおり 404 のままとする。
        $general = User::factory()->create(['role' => 'general']);

        $response = $this->actingAs($general)
            ->delete('/master/users/99999', [], $this->inertiaHeaders());

        $response->assertNotFound();
        $this->assertNull(session('error'));
    }

    public function test_general_user_on_existing_admin_route_still_returns_403(): void
    {
        // 存在するユーザーに対しては従来どおり admin ミドルウェアの 403 が返ること
        // （本対応が認可の結果を変えていないことの確認）。
        $general = User::factory()->create(['role' => 'general']);
        $target = User::factory()->create();

        $response = $this->actingAs($general)
            ->delete("/master/users/{$target->id}", [], $this->inertiaHeaders());

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_guest_is_redirected_to_login_not_to_index(): void
    {
        // 未ログインは auth ミドルウェアが先に効くため、一覧ではなくログインへ。
        $response = $this->delete('/projects/99999', [], $this->inertiaHeaders());

        $response->assertRedirect('/login');
        $this->assertNull(session('error'));
    }

    // -------------------------------------------------------
    // 通常操作が壊れていないこと
    // -------------------------------------------------------

    public function test_existing_project_delete_still_succeeds_with_success_flash(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $project = Project::factory()->create();

        $response = $this->actingAs($admin)
            ->delete("/projects/{$project->id}", [], $this->inertiaHeaders());

        $response->assertRedirect('/projects');
        $response->assertSessionHas('success', '案件情報を削除しました。');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_existing_engineer_detail_is_still_rendered(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create();

        $response = $this->actingAs($user)
            ->get("/engineers/{$engineer->id}", $this->inertiaHeaders());

        $response->assertOk();
    }

    public function test_existing_pipeline_and_saved_search_are_not_affected(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->create();
        $savedSearch = SavedSearch::create([
            'user_id' => $user->id,
            'name' => '提案可の人材',
            'search_type' => 'engineer',
            'conditions' => ['status' => ['proposable']],
        ]);

        $this->actingAs($user)
            ->get("/pipelines/{$pipeline->id}", $this->inertiaHeaders())
            ->assertOk();

        $this->actingAs($user)
            ->delete("/saved-searches/{$savedSearch->id}", [], $this->inertiaHeaders())
            ->assertRedirect();

        $this->assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    }
}
