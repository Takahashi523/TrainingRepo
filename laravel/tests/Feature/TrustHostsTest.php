<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
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

    public function test_application_host_and_localhost_are_accepted(): void
    {
        config(['app.url' => 'https://app.example.com']);
        $this->applyTrustedHosts();

        $this->assertTrue($this->hostIsAccepted('https://app.example.com/login'));
        $this->assertTrue($this->hostIsAccepted('http://localhost/up'));
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
        $this->assertFalse($this->hostIsAccepted('https://evil-localhost.attacker.test/login'));
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
}
