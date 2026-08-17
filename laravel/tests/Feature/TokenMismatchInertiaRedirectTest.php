<?php

namespace Tests\Feature;

use App\Exceptions\TokenMismatchInertiaRedirector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * CSRFトークン不一致（419）で生の例外画面が出る問題への対応（issue #63・②）。
 *
 * Laravelは runningUnitTests() が真の間（= このテストスイート全体）、
 * ValidateCsrfToken ミドルウェアの検証を素通りさせる（フレームワーク標準の挙動）。
 * そのため、実際のHTTPリクエストを投げてもTokenMismatchExceptionを自然に
 * 発生させることができない。ここでは TokenMismatchInertiaRedirector::handle() を
 * 直接呼び出し、例外を受け取った後の応答生成ロジックだけを検証する。
 *
 * back()/route() はコンテナに束縛された「現在のリクエスト」を参照するため、
 * handle() に渡す $request と同じインスタンスを app('request') にも束縛しておく
 * （本番では例外ハンドラに渡される $request は元々コンテナ束縛のものと同一）。
 */
class TokenMismatchInertiaRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function bindRequest(Request $request): Request
    {
        $request->headers->set('X-Inertia', 'true');
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_inertia_request_with_referer_redirects_back_with_303_and_flash(): void
    {
        $request = $this->bindRequest(Request::create('/saved-searches/1', 'DELETE'));
        $request->headers->set('referer', url('/engineers'));

        $response = (new TokenMismatchInertiaRedirector)->handle(new TokenMismatchException, $request);

        // Inertia は非GETへの302を追えないため、①や既存のStaleResourceRedirectorと同様303であること
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(url('/engineers'), $response->headers->get('Location'));
        $this->assertSame(
            'セッションの有効期限が切れました。もう一度お試しください。',
            $this->app['session']->driver()->get('error')
        );
    }

    public function test_inertia_request_without_referer_falls_back_to_dashboard(): void
    {
        // セッションが新規発行され参照元が取れないケースでも行き先を失わないこと。
        $request = $this->bindRequest(Request::create('/saved-searches/1', 'DELETE'));

        // back(fallback: ...) は UrlGenerator::previous() 経由でセッションの
        // previousUrl() を参照する。通常はStartSessionミドルウェアがリゾルバを
        // 登録するが、ここでは直接呼び出しているためテスト側で結び付けておく。
        $session = $this->app['session']->driver();
        $session->start();
        $this->app['url']->setSessionResolver(fn () => $session);

        $response = (new TokenMismatchInertiaRedirector)->handle(new TokenMismatchException, $request);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame(route('dashboard'), $response->headers->get('Location'));
    }

    public function test_non_inertia_request_returns_null_and_leaves_default_419_behavior(): void
    {
        $request = Request::create('/saved-searches/1', 'DELETE');

        $response = (new TokenMismatchInertiaRedirector)->handle(new TokenMismatchException, $request);

        $this->assertNull($response);
    }
}
