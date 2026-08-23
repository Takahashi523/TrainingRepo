<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * リバースプロキシ（Nginx）配下での転送ヘッダーの扱いを検証する。
 *
 * bootstrap/app.php の trustProxies は「Nginx が proxy_set_header で明示的にセットしている
 * ヘッダーだけを信頼する」方針で、X-Forwarded-For / X-Forwarded-Proto の 2 種のみを信頼する。
 * Nginx がセットしないヘッダー（Host / Prefix / Port）はクライアントの送信値が素通しされるため、
 * 信頼すると生成される絶対 URL を外部から書き換えられてしまう。
 *
 * DB を一切参照しないため RefreshDatabase は使用しない。
 */
class TrustProxiesTest extends TestCase
{
    /**
     * 転送ヘッダーの解釈結果を観測するための検証用ルート。
     *
     * TrustProxies はグローバルミドルウェアとして全リクエストに適用されるため、
     * web グループを付けなくても効果を確認できる。
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__trust-proxies-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'asset' => asset('build/app.js'),
        ]));
    }

    /**
     * ヘッダーを付けずにアクセスしたときの生成 URL を基準値として取得する。
     *
     * 期待値を 'http://localhost/...' と直書きすると APP_URL に依存してしまうため、
     * 「注入しても基準値から変化しない」という形で検証する。
     */
    private function baselineAssetUrl(): string
    {
        return $this->get('/__trust-proxies-probe')->json('asset');
    }

    public function test_x_forwarded_proto_is_trusted_and_assets_use_https(): void
    {
        $response = $this->get('/__trust-proxies-probe', ['X-Forwarded-Proto' => 'https']);

        $response->assertOk();
        $this->assertTrue($response->json('secure'), 'X-Forwarded-Proto: https が信頼されていない');
        $this->assertStringStartsWith('https://', $response->json('asset'));
    }

    public function test_request_without_forwarded_headers_stays_http(): void
    {
        $response = $this->get('/__trust-proxies-probe');

        $response->assertOk();
        $this->assertFalse($response->json('secure'));
        $this->assertStringStartsWith('http://', $response->json('asset'));
    }

    public function test_client_ip_is_taken_from_x_forwarded_for(): void
    {
        $response = $this->get('/__trust-proxies-probe', ['X-Forwarded-For' => '203.0.113.10']);

        $response->assertOk();
        // ログイン試行のレート制限キー（LoginRequest::throttleKey）がこの値に依存する
        $this->assertSame('203.0.113.10', $response->json('ip'));
    }

    /**
     * 本番の Nginx は $proxy_add_x_forwarded_for を使っており、
     * クライアントが偽装値を送ると「偽装値, 実IP」の連鎖になって届く。
     * 単一値のテストではこの形状を再現できないため、連鎖でも実 IP が採られることを固定する。
     */
    public function test_client_ip_ignores_spoofed_value_in_forwarded_for_chain(): void
    {
        $response = $this->get('/__trust-proxies-probe', [
            'X-Forwarded-For' => '1.2.3.4, 203.0.113.99',
        ]);

        $response->assertOk();
        $this->assertSame('203.0.113.99', $response->json('ip'), '偽装値が採用されている');
    }

    public function test_x_forwarded_prefix_is_not_trusted(): void
    {
        $baseline = $this->baselineAssetUrl();

        $asset = $this->get('/__trust-proxies-probe', ['X-Forwarded-Prefix' => '/INJECTED'])->json('asset');

        $this->assertStringNotContainsString('/INJECTED', $asset);
        $this->assertSame($baseline, $asset);
    }

    public function test_x_forwarded_host_is_not_trusted(): void
    {
        $baseline = $this->baselineAssetUrl();

        $asset = $this->get('/__trust-proxies-probe', ['X-Forwarded-Host' => 'evil.example.com'])->json('asset');

        $this->assertStringNotContainsString('evil.example.com', $asset);
        $this->assertSame($baseline, $asset);
    }

    public function test_x_forwarded_port_is_not_trusted(): void
    {
        $baseline = $this->baselineAssetUrl();

        $asset = $this->get('/__trust-proxies-probe', ['X-Forwarded-Port' => '1234'])->json('asset');

        $this->assertStringNotContainsString(':1234', $asset);
        $this->assertSame($baseline, $asset);
    }

    /**
     * セッションクッキーの Secure 属性は config/session.php の 'secure' が null のとき、
     * Symfony Response::prepare() が isSecure() を見て自動付与する
     * （Cookie::isSecure() は `secure ?? secureDefault`）。
     *
     * つまり .env で SESSION_SECURE_COOKIE=false を明示すると、この自動判定が打ち消され、
     * HTTPS でも Secure が付かなくなる。.env.example がその値を配ってしまう事故を防ぐため、
     * 「未設定なら HTTPS で Secure が付く」という前提そのものを固定する。
     */
    public function test_session_cookie_gets_secure_flag_over_https_when_not_configured(): void
    {
        config(['session.secure' => null, 'session.driver' => 'file']);

        Route::middleware('web')->get('/__session-probe', fn () => response('ok'));

        $response = $this->get('/__session-probe', ['X-Forwarded-Proto' => 'https']);

        $response->assertOk();

        // Symfony の Cookie はプロパティが private のため、getName() で突き合わせる
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'セッションクッキーが発行されていない');
        $this->assertTrue($cookie->isSecure(), 'HTTPS 経由なのに Secure 属性が付いていない');
    }
}
