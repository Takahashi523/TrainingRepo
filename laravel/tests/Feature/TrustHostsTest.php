<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Host ヘッダーによる絶対 URL の乗っ取りを防ぐ許可リストを検証する。
 *
 * TrustHosts ミドルウェア自体は local 環境とテスト実行時に自動で無効化されるため
 * （TrustHosts::shouldSpecifyTrustedHosts）、通常の HTTP テストでは効果を観測できない。
 * そこで hosts() が返すパターンを取り出し、Symfony の照合機構に直接かけて検証する。
 */
class TrustHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        // 静的プロパティのため、他のテストへ漏れないよう必ず戻す
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * bootstrap/app.php が設定した許可パターンを実際の照合機構に適用する。
     */
    private function applyTrustedHosts(): void
    {
        Request::setTrustedHosts(array_filter($this->app->make(TrustHosts::class)->hosts()));
    }

    private function hostIsAccepted(string $url): bool
    {
        try {
            Request::create($url)->getHost();

            return true;
        } catch (SuspiciousOperationException) {
            return false;
        }
    }

    public function test_application_host_is_accepted(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertTrue($this->hostIsAccepted('https://app.example.com/login'));
    }

    /**
     * 許可するのは APP_URL のホストのみ。localhost も例外ではない。
     * （コンテナ内ヘルスチェック等で localhost を通す必要が出た場合は、
     *   実際に届く Host を確認したうえで明示的に追加すること）
     */
    public function test_localhost_is_not_accepted(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('http://localhost/up'));
    }

    /**
     * Host にポートが付いていても、ポートは getHost() で除去されてから照合される。
     * 許可パターンにポートを含めていないことが正しいと保証する。
     */
    public function test_host_with_port_is_accepted(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertTrue($this->hostIsAccepted('https://app.example.com:8443/login'));
    }

    /**
     * APP_URL が空だと許可パターンが 0 件になり、Symfony は
     * 「パターンが無い＝検証しない」と判断して全ホストを素通しする（フェイルオープン）。
     * 決して一致しないパターンを返して閉じる側に倒していることを固定する。
     */
    public function test_empty_application_url_fails_closed_instead_of_allowing_everything(): void
    {
        config(['app.url' => '']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://evil.example.com/login'));
        $this->assertFalse($this->hostIsAccepted('http://localhost/up'));
    }

    /**
     * APP_URL にスキームが無いと parse_url(..., PHP_URL_HOST) は NULL を返す。
     * 「ホスト名に更新する」という手順書の文言を素直に読むと起こりうる誤設定のため、
     * 素通し（フェイルオープン）ではなく拒否側に倒れることを固定する。
     */
    public function test_application_url_without_scheme_fails_closed(): void
    {
        config(['app.url' => 'app.example.com']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://app.example.com/login'));
    }

    public function test_unrelated_host_is_rejected(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://evil.example.com/login'));
    }

    /**
     * Symfony は許可パターンを正規表現として **部分一致** で照合する
     * （Request::setTrustedHosts が {%s}i で包むだけでアンカーを付けない）。
     * ^...$ で囲み忘れると、許可ホストを含むだけの別ホストが通ってしまう。
     */
    public function test_lookalike_hosts_containing_the_allowed_name_are_rejected(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://app.example.com.attacker.test/login'));
        $this->assertFalse($this->hostIsAccepted('https://prefix-app.example.com/login'));
    }

    /**
     * サブドメインは許可しない（subdomains: false）。
     */
    public function test_subdomains_are_not_accepted(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://sub.app.example.com/login'));
    }

    /**
     * 許可外の Host が 400 で拒否され、かつ warning が1件記録されることを固定する。
     *
     * ⚠️ Handler::render() は renderViaCallbacks() の前に prepareException() を通し、
     *    SuspiciousOperationException を BadRequestHttpException へ差し替える
     *    （Handler.php:651-653 / :710）。bootstrap/app.php のハンドラを元例外の型で
     *    宣言すると一度も呼ばれないデッドコードになるため、ログ出力まで検証する。
     */
    public function test_untrusted_host_returns_400_and_writes_a_warning_log(): void
    {
        Request::setTrustedHosts(['^app\\.example\\.com$']);

        Log::spy();

        // 相対パスを渡すと prepareUrlForRequest() 内の url() が先に getHost() を呼んで
        // 例外になるため（UrlGenerator::formatRoot 経由）、絶対 URL を渡して回避する。
        // Host が localhost（許可外）になるため、ルーティング前に弾かれる。
        $this->get('http://localhost/__untrusted-host-probe')->assertStatus(400);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['host'] === 'localhost');
    }
}
