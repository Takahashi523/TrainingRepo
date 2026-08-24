<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
     * TRUSTED_HOSTS は APP_URL のホストへの「追加」であり、置き換えではない。
     * ホスト移行中に新旧2ホストを同時に通す運用が .env だけで成立することを固定する。
     */
    public function test_additional_trusted_hosts_are_accepted_alongside_the_application_host(): void
    {
        config([
            'app.url' => 'https://app.example.com',
            'app.trusted_hosts' => 'new.example.com, 10.0.1.20',
        ]);
        $this->applyTrustedHosts();

        $this->assertTrue($this->hostIsAccepted('https://app.example.com/login'));
        $this->assertTrue($this->hostIsAccepted('https://new.example.com/login'));
        // 前後の空白を許容する（.env にカンマ区切りで書くと空白が混ざりやすい）
        $this->assertTrue($this->hostIsAccepted('http://10.0.1.20/up'));
        // 追加したホスト以外は従来どおり拒否される
        $this->assertFalse($this->hostIsAccepted('https://evil.example.com/login'));
    }

    /**
     * TRUSTED_HOSTS 由来のパターンにもアンカーとエスケープが付いていること。
     * APP_URL 側だけアンカーしても、追加分が部分一致だと穴になる。
     */
    public function test_additional_trusted_hosts_are_anchored(): void
    {
        config([
            'app.url' => 'https://app.example.com',
            'app.trusted_hosts' => 'new.example.com',
        ]);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://new.example.com.attacker.test/login'));
        $this->assertFalse($this->hostIsAccepted('https://prefix-new.example.com/login'));
        // ドットがワイルドカードとして働かないこと（preg_quote の担保）
        $this->assertFalse($this->hostIsAccepted('https://newxexample.com/login'));
    }

    /**
     * 空要素（末尾カンマ・空白のみ）が許可パターンに混ざらないこと。
     * 空文字を ^$ で囲むと「ホスト名が空のリクエスト」を通す穴になりうる。
     */
    public function test_blank_entries_in_trusted_hosts_are_ignored(): void
    {
        config([
            'app.url' => 'https://app.example.com',
            'app.trusted_hosts' => 'new.example.com,, ,',
        ]);

        $patterns = array_filter($this->app->make(TrustHosts::class)->hosts());

        $this->assertSame(['^app\.example\.com$', '^new\.example\.com$'], array_values($patterns));
    }

    /**
     * TRUSTED_HOSTS に「ホスト名以外の書き方」を入れても一致しないことを固定する。
     *
     * preg_quote で全てエスケープされるため安全側（余計に通らない）に倒れるが、
     * その代わり誤設定がエラーにならず「許可したつもりで 400 が返り続ける」形になる。
     * 挙動を固定しておかないと、将来 preg_quote を外すリファクタで
     * 「ワイルドカードを効かせたつもり」の穴が静かに開く。
     * 対応する注意書きは .env.example と docs/インフラ構成図.md に置いている。
     */
    public function test_wildcard_port_and_scheme_forms_are_not_accepted_in_trusted_hosts(): void
    {
        config([
            'app.url' => 'https://app.example.com',
            'app.trusted_hosts' => '*.example.com,new.example.com:8443,https://other.example.com',
        ]);
        $this->applyTrustedHosts();

        // ワイルドカードは展開されない
        $this->assertFalse($this->hostIsAccepted('https://any.example.com/login'));
        // ポート付きで書いた分は、照合対象（getHost()）にポートが含まれないため一致しない
        $this->assertFalse($this->hostIsAccepted('https://new.example.com:8443/login'));
        $this->assertFalse($this->hostIsAccepted('https://new.example.com/login'));
        // スキーム付きで書いた分も一致しない
        $this->assertFalse($this->hostIsAccepted('https://other.example.com/login'));
        // APP_URL のホストは従来どおり通る（誤設定が既存の許可を壊していないこと）
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
        config(['app.url' => '', 'app.trusted_hosts' => '']);
        $this->applyTrustedHosts();

        $this->assertFalse($this->hostIsAccepted('https://evil.example.com/login'));
        $this->assertFalse($this->hostIsAccepted('http://localhost/up'));
    }

    /**
     * フェイルクローズが働くのは「許可パターンが1件も無いとき」だけ。
     * APP_URL が壊れていても TRUSTED_HOSTS が生きていれば、そのホストは通る
     * （＝フェイルクローズが TRUSTED_HOSTS を巻き添えに殺さないこと）。
     */
    public function test_trusted_hosts_still_apply_when_application_url_is_unusable(): void
    {
        config(['app.url' => '', 'app.trusted_hosts' => 'new.example.com']);
        $this->applyTrustedHosts();

        $this->assertTrue($this->hostIsAccepted('https://new.example.com/login'));
        $this->assertFalse($this->hostIsAccepted('https://evil.example.com/login'));
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

        // 同上（詳細レンダラのメモリ消費を避ける）
        config(['app.debug' => false]);

        Log::spy();

        // 相対パスを渡すと prepareUrlForRequest() 内の url() が先に getHost() を呼んで
        // 例外になるため（UrlGenerator::formatRoot 経由）、絶対 URL を渡して回避する。
        // Host が localhost（許可外）になるため、ルーティング前に弾かれる。
        $this->get('http://localhost/__untrusted-host-probe')->assertStatus(400);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['host'] === 'localhost'
                && str_contains($context['reason'], 'Untrusted Host'));
    }

    /**
     * Host 拒否**以外**の 400 をログに巻き込まないこと。
     *
     * prepareException() は RequestExceptionInterface 以外の経路で発生した 400 も
     * BadRequestHttpException として render コールバックに渡すため、
     * bootstrap/app.php 側の getPrevious() ガードが無いと、アプリが投げたあらゆる 400 が
     * 「不正なリクエストを拒否しました」として記録され、ログの意味が壊れる。
     * このケースが無いとガードを削除してもテストが1件も落ちない。
     */
    public function test_other_bad_requests_are_not_logged_as_suspicious_operations(): void
    {
        Route::get('/__bad-request-probe', function () {
            // ⚠️ 元例外を持たせる。previous が null の 400 だけで検証すると、
            //    ガードを外しても後続の $previous->getMessage() が TypeError になり
            //    catch(Throwable) に握られてログが出ない＝テストが通ってしまい、
            //    ガードの有無を検出できない。
            throw new BadRequestHttpException(
                'アプリが投げた通常の 400',
                new RuntimeException('Host とは無関係の失敗'),
            );
        });

        // 検証対象はログの有無だけ。debug 有効のままだと詳細レンダラが大量にメモリを使い、
        // 全件実行で memory_limit に当たるため、本番と同じ簡易応答で確認する。
        config(['app.debug' => false]);

        Log::spy();

        $this->get('/__bad-request-probe')->assertStatus(400);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * ログ出力に失敗しても、400 応答が 500 に化けないこと。
     *
     * bootstrap/app.php の catch (Throwable) はこのためのガードだが、
     * try ごと消しても catch だけ消しても既存ケースは両方通ってしまい、
     * 「何のための握りつぶしか」が固定されていなかった。
     *
     * 机上の心配ではない：ログ抑制のカウンタ（RateLimiter）は既定ストア＝
     * database（.env.example の CACHE_STORE）を使うため、この経路は DB が
     * 生きていることに依存する。DB 障害中＝運用者が最もログを見たい瞬間に
     * 例外が飛ぶが、そこで 500 を返すと「Host 拒否」という本来の応答まで失われる。
     */
    public function test_log_failure_does_not_turn_the_400_into_a_500(): void
    {
        Request::setTrustedHosts(['^app\\.example\\.com$']);

        // 同上（詳細レンダラのメモリ消費を避ける）
        config(['app.debug' => false]);

        Log::spy()->shouldReceive('warning')->andThrow(new RuntimeException('log backend down'));

        $this->get('http://localhost/__untrusted-host-probe')->assertStatus(400);
    }
}
