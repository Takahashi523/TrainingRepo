<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * ログイン試行のレート制限（LoginRequest::ensureIsNotRateLimited）を検証する。
 *
 * 2段構えになっている：
 *   - 第1段：メールアドレス＋送信元 IP で 5回 / 分（Breeze 既定）
 *   - 第2段：メールアドレス単独で 30回 / 時（送信元を分散されても効く）
 *
 * リバースプロキシ配下で X-Forwarded-For を信頼するようになったことで、
 * 第1段のキーに入る IP が「前段 Nginx の固定 IP」から「実クライアント IP」に変わり、
 * 送信元を変えるだけで第1段を回避できるようになった。第2段はその穴を塞ぐもの。
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログイン失敗時に email へ入ったエラーメッセージを取り出す。
     *
     * session('errors') は ViewErrorBag で入るが、同一テスト内でリクエストを重ねると
     * セッションの保存・復元を経て生の配列として返ることがある。
     * 「何が表示されたか」を検証したいだけなので、両方の形を受ける。
     */
    private function loginErrorMessage(): string
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return $errors->get('email')[0];
        }

        return $errors['default']['messages']['email'][0];
    }

    /**
     * 同一 IP からの試行は 5 回で止まる（第1段）。
     */
    public function test_login_is_throttled_after_five_failures_from_the_same_ip(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('ログイン試行回数が上限に達しました', $this->loginErrorMessage());
        $this->assertGuest();
    }

    /**
     * 送信元 IP を変えても、同一アカウントへの試行は 30 回で止まる（第2段）。
     *
     * この第2段が無いと、X-Forwarded-For を差し替えるだけで
     * 「5回 × 送信元数」まで無制限に試行できてしまう。
     */
    public function test_login_is_throttled_per_account_even_when_the_source_ip_changes(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        // 毎回別 IP から 1 回だけ失敗させる。第1段（IP ごと 5 回）には決して掛からない。
        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->from('/login')
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('ログイン試行回数が上限に達しました', $this->loginErrorMessage());
        $this->assertGuest();
    }

    /**
     * 第2段は「アカウント単位」であり、別アカウントを巻き添えにしないこと。
     * 巻き添えにすると、1 アカウントへの攻撃で全社員がログイン不能になる。
     */
    public function test_account_level_throttle_does_not_lock_out_other_accounts(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);

        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', [
                    'email' => $victim->email,
                    'password' => 'wrong-password',
                ]);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/login', [
                'email' => $other->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    /**
     * ログインに成功したらアカウント単位のカウンタも解除されること。
     * 解除し忘れると、日常的な打ち間違いが1時間かけて蓄積し、正規ユーザーが締め出される。
     */
    public function test_successful_login_clears_the_account_level_counter(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 29; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);
        $this->assertAuthenticated();

        $this->assertSame(0, RateLimiter::attempts('login-email|victim@example.com'));
    }

    /**
     * 第2段で止めた場合も Lockout イベントが発火すること（監査・通知の起点になる）。
     */
    public function test_account_level_throttle_dispatches_the_lockout_event(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        Event::fake([Lockout::class]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        Event::assertDispatched(Lockout::class);
    }
}
