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
}
