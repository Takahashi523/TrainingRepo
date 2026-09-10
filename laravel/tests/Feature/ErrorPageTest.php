<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * 共通エラーページ（issue #70）。
 *
 * 検証の軸は 3 つ。
 *  1. 各ステータスでアプリの体裁を保った案内（Inertia の Error ページ）が返ること
 *  2. **ステータスコード自体は元の値のまま**であること（応答の意味を変えていない）
 *  3. 差し替えてはいけないもの（JSON クライアント・#44 のリダイレクト・debug 時の 500）を
 *     巻き込んでいないこと
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // エラーページはビルド成果物に依存しないため、マニフェストを参照させない。
        $this->withoutVite();
    }

    /**
     * Inertia のリクエストとして扱わせるためのヘッダー（StaleResourceHandlingTest と同じ）。
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        ];
    }

    /**
     * ルートビューが存在しない HandleInertiaRequests をミドルウェアとして使わせる。
     * 案内ページの描画自体が失敗する状況（Vite マニフェスト欠落など）の代替再現。
     */
    private function useBrokenRootView(): void
    {
        $this->app->bind(HandleInertiaRequests::class, fn () => new class extends HandleInertiaRequests
        {
            protected $rootView = 'no-such-root-view';
        });
    }

    // -------------------------------------------------------
    // 404：未定義 URL（Route::fallback 経由）
    // -------------------------------------------------------

    public function test_undefined_url_renders_error_page_with_missing_page_reason(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/no-such-page');

        $response->assertNotFound();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 404)
                ->where('reason', 'missing_page')
                // fallback ルートが web ミドルウェアを通ることで、ログイン済みの共有 Props が載る。
                // ここが null になると、ログイン中でも未認証向けの表示になってしまう。
                ->where('auth.user.id', $user->id)
        );
    }

    public function test_undefined_url_for_guest_renders_error_page_without_redirecting_to_login(): void
    {
        $response = $this->get('/no-such-page');

        // 未ログインでも 404 は 404 のまま返す（ログイン画面へ飛ばさない）。
        $response->assertNotFound();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 404)
                ->where('auth.user', null)
        );
    }

    // -------------------------------------------------------
    // 404：対象が存在しない（例外ハンドラ経由）
    // -------------------------------------------------------

    public function test_missing_record_renders_error_page_with_missing_resource_reason(): void
    {
        // 非 Inertia の直接アクセス（共有 URL・ブックマーク）。#44 の差し替え条件に合致しないため、
        // ここが共通エラーページの受け皿になる。未定義 URL とは文言が変わる。
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/99999');

        $response->assertNotFound();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 404)
                ->where('reason', 'missing_resource')
                // ルートモデルバインディング（SubstituteBindings）は HandleInertiaRequests より
                // 先に走るため、共有 Props が未設定のまま例外ハンドラに来る。ここが欠けると
                // ログイン済みでも未認証向けの表示になり、「ログイン画面へ」を押すと
                // ログイン済み判定でダッシュボードへ飛ぶ（実機で検出したバグ）。
                ->where('auth.user.id', $user->id)
        );
    }

    public function test_undefined_url_with_post_renders_error_page(): void
    {
        // Route::fallback() は GET / HEAD にしか登録されないため、未定義 URL への POST は
        // 404 ではなく 405 になる。405 を許可リストに入れていないと、古いタブからの送信が
        // 素のエラー画面に落ちる（本 PR 以前は 404 で案内できていた経路）。
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/no-such-page');

        $response->assertStatus(405);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 405)
                // 【既知の制限を固定する】メソッド不一致はルート確定より前に検出されるため
                // web グループ（StartSession / HandleInertiaRequests）が一度も走らず、
                // 共有 Props が **null ですらなく存在しない**（＝ログイン済みでも未認証向けの表示）。
                // 405 のためにセッション開始経路を増やす判断はしていないので、
                // 「直っていないこと」ではなく「現状こうであること」をここで固定しておく
                // （将来対応する場合、このアサーションが落ちて意図的な変更だと分かる）。
                ->missing('auth')
        );
    }

    // -------------------------------------------------------
    // 案内ページ自体が描画できないとき（フェイルセーフ）
    // -------------------------------------------------------

    public function test_render_failure_on_undefined_url_falls_back_to_plain_404(): void
    {
        // 案内ページの描画そのものが失敗する状況（Vite マニフェスト欠落・ビュー欠落など）を、
        // ルートビューが存在しない HandleInertiaRequests を差し込んで再現する。
        // Inertia::setRootView() を直接呼んでも、fallback ルートは web グループを通るため
        // ミドルウェアが既定値に戻してしまい、失敗を再現できない。
        $this->useBrokenRootView();
        Exceptions::fake();

        $response = $this->get('/no-such-page');

        // 描画に失敗しても、404 のアクセスは 404 のまま返す。ここで例外が外へ出ると
        // 例外ハンドラが 500 として扱い、単なる URL 誤りが素の 500（監視上はサーバー障害）に
        // 格上げされる。見た目は既定の 404 に劣化するが、意味は保つ。
        $response->assertNotFound();
        $this->assertStringNotContainsString('data-page', $response->getContent());

        // 失敗を無言で握りつぶさない（案内ページが出せないこと自体が検知したい不具合）。
        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function test_render_failure_on_missing_record_falls_back_to_original_response(): void
    {
        // 例外ハンドラ経由（[A]）でも同様に、元の応答へ退避する。
        // こちらは SubstituteBindings の 404 が HandleInertiaRequests より先に起きるため、
        // テストで設定したルートビューがそのまま描画時まで残る。
        $user = User::factory()->create();
        Inertia::setRootView('no-such-root-view');
        Exceptions::fake();

        $response = $this->actingAs($user)->get('/projects/99999');

        $response->assertNotFound();
        $this->assertStringNotContainsString('data-page', $response->getContent());
        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function test_error_page_exposes_no_technical_information(): void
    {
        // 受け入れ条件：スタックトレース・例外クラス名・ファイルパスを出さない。
        // Props を status / reason に限定していることを、キーの集合として固定する。
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/99999');

        $response->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            // 共有 Props（auth / flash / errors 等）以外にページ固有で渡すのは 2 つだけ。
            $this->assertSame(
                ['reason', 'status'],
                collect($props)->keys()->reject(
                    fn (string $key) => in_array($key, ['auth', 'flash', 'errors'], true)
                )->sort()->values()->all()
            );
        });
    }

    public function test_error_page_carries_asset_version_so_next_visit_is_not_a_full_reload(): void
    {
        // ルートモデルバインディングの 404 は HandleInertiaRequests より前に発生するため、
        // version を補わないと空文字のまま描画され、この画面からの次の Inertia 遷移が
        // バージョン不一致（409）と判定されて毎回フルリロードになる。
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/projects/99999');

        $expected = (string) (new HandleInertiaRequests)->version(request());
        $this->assertSame($expected, $response->viewData('page')['version']);
    }

    // -------------------------------------------------------
    // 403
    // -------------------------------------------------------

    public function test_forbidden_renders_error_page_and_keeps_status(): void
    {
        $general = User::factory()->create(['role' => 'general']);

        $response = $this->actingAs($general)->get('/master');

        $response->assertForbidden();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 403)
                ->where('reason', null)
                // 403 は権限のあるユーザーへの連絡を促す画面。認証状態が落ちると
                // 「ログイン画面へ」になってしまうため、ここでも共有 Props を検証する。
                ->where('auth.user.id', $general->id)
        );
    }

    // -------------------------------------------------------
    // 419（CSRF トークン失効）
    // -------------------------------------------------------

    public function test_token_expired_while_logged_in_returns_to_previous_screen_with_flash(): void
    {
        // セッションは生きていてトークンだけ失効した状態。ログイン画面へ送ると guest ミドルウェアが
        // ログイン済みを弾いてダッシュボードへ再リダイレクトし、フラッシュが描画されずに消える
        // （＝送信の失敗すら伝わらない Silent Rejection）。元の画面へ戻すこと。
        Route::middleware('web')->post('/__test/token-expired', fn () => abort(419));
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/pipelines')
            ->post('/__test/token-expired');

        $response->assertStatus(303);
        $response->assertRedirect('/pipelines');
        $response->assertSessionHas(
            'error',
            'ページを開いてから時間が経過したため、送信できませんでした。お手数ですが、もう一度操作してください。'
        );
    }

    public function test_token_expired_does_not_redirect_to_an_external_referer(): void
    {
        // 419 はクロスサイトからの送信でも発生する。url()->previous() は Referer をそのまま使うため、
        // 絞り込まないと攻撃者が指定した外部 URL へのリダイレクトを自ドメインから発行してしまう。
        Route::middleware('web')->post('/__test/token-expired', fn () => abort(419));
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('https://evil.example/attack')
            ->post('/__test/token-expired');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_token_expired_while_logged_in_falls_back_to_dashboard(): void
    {
        // 遷移元が取得できない場合（直接 POST・Referer 無し）でも、行き止まりにしない。
        Route::middleware('web')->post('/__test/token-expired', fn () => abort(419));
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/__test/token-expired');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_session_expired_redirects_to_login_with_status_message(): void
    {
        // テストでは ValidateCsrfToken がスキップされ実際の CSRF 失効を再現できないため、
        // 419 を返すルートを立てて例外ハンドラのマッピングを検証する
        // （CSRF ミドルウェア自体の挙動は Laravel の責務）。
        Route::middleware('web')->get('/__test/session-expired', fn () => abort(419));

        $response = $this->get('/__test/session-expired');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas(
            'status',
            'セッションの有効期限が切れました。もう一度ログインしてください。'
        );
    }

    public function test_session_expired_on_unsafe_method_redirects_with_303(): void
    {
        Route::middleware('web')->post('/__test/session-expired', fn () => abort(419));

        $response = $this->post('/__test/session-expired');

        // Inertia は POST への 302 を追えないため 303 See Other で返す。
        $response->assertStatus(303);
        $response->assertRedirect(route('login'));
    }

    // -------------------------------------------------------
    // 500
    // -------------------------------------------------------

    public function test_server_error_renders_error_page_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__test/boom', fn () => throw new RuntimeException('boom'));
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/__test/boom');

        $response->assertStatus(500);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 500)
                ->where('reason', null)
                ->where('auth.user.id', $user->id)
        );
    }

    public function test_server_error_keeps_debug_output_when_debug_is_enabled(): void
    {
        // 開発時のスタックトレースを潰さないこと（デバッグ能力の維持）。
        config(['app.debug' => true]);
        Route::middleware('web')->get('/__test/boom', fn () => throw new RuntimeException('boom'));

        $response = $this->get('/__test/boom');

        $response->assertStatus(500);
        // data-page は Blade でエスケープされるため、生の '"component":"Error"' は HTML 中に決して
        // 現れない＝否定形のアサーションは常に通ってしまう。例外情報が残っていることを肯定形で確かめる。
        // 案内ページに差し替わっていれば Props は status / reason だけになり、例外メッセージは残らない。
        $response->assertSee('boom');
    }

    // -------------------------------------------------------
    // 503
    // -------------------------------------------------------

    public function test_service_unavailable_renders_error_page(): void
    {
        // メンテナンス（php artisan down）で到達しうる。個別の到達経路は無いが、
        // 許可リストに含めている以上、案内が出ることを担保しておく。
        Route::middleware('web')->get('/__test/maintenance', fn () => abort(503));

        $response = $this->get('/__test/maintenance');

        $response->assertStatus(503);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 503)
                ->where('reason', null)
        );
    }

    // -------------------------------------------------------
    // 差し替えてはいけないもの
    // -------------------------------------------------------

    public function test_json_client_still_receives_plain_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/no-such-page');

        $response->assertNotFound();
        // API・JSON クライアントに HTML の案内ページを返さない。
        // （否定形で HTML 断片を探すと、エスケープの都合で常に通るテストになるため肯定形で確かめる）
        $response->assertHeaderMissing('X-Inertia');
        $response->assertJsonStructure(['message']);
    }

    public function test_stale_resource_redirect_is_not_replaced_by_error_page(): void
    {
        // #44 のリダイレクト（302 / 303）が respond() に巻き込まれていないことの直接確認。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->delete('/projects/99999', [], $this->inertiaHeaders());

        $response->assertStatus(303);
        $response->assertRedirect('/projects');
        $response->assertSessionHas('error', '対象の案件が見つかりません。既に削除された可能性があります。');
    }

    public function test_validation_error_is_not_replaced_by_error_page(): void
    {
        // 422 を差し替えると Inertia の onError（フォームのエラー表示）が無言で壊れるため、
        // 許可リストから外していることを確認する。
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/projects', [], $this->inertiaHeaders());

        $response->assertStatus(302);
        $response->assertSessionHasErrors();
        $this->assertSame(0, Project::query()->count());
    }
}
