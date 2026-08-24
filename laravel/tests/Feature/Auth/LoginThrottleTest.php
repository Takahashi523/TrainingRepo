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
 *   - 第2段：メールアドレス単独で 10回 / 15分（送信元を分散されても効く）
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
     *
     * 上限ちょうど（5回目）はまだ通ることも併せて固定する。
     * 「上限＋1 が止まること」だけを見るテストは、しきい値を**下げる**変更
     * （5 → 4 など）を検出できず、正規ユーザーを締め出す方向の劣化が素通りする。
     */
    public function test_login_is_throttled_after_five_failures_from_the_same_ip(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 4; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // 5 回目：まだレート制限には掛からず、認証失敗として扱われること
        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
        $this->assertStringContainsString('メールアドレスまたはパスワードが正しくありません', $this->loginErrorMessage());

        // 6 回目：レート制限に掛かること
        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('ログイン試行回数が上限に達しました', $this->loginErrorMessage());
        $this->assertGuest();
    }

    /**
     * 送信元 IP を変えても、同一アカウントへの試行は 10 回で止まる（第2段）。
     *
     * この第2段が無いと、X-Forwarded-For を差し替えるだけで
     * 「5回 × 送信元数」まで無制限に試行できてしまう。
     */
    public function test_login_is_throttled_per_account_even_when_the_source_ip_changes(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        // 毎回別 IP から 1 回だけ失敗させる。第1段（IP ごと 5 回）には決して掛からない。
        for ($i = 0; $i < 10; $i++) {
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

        for ($i = 0; $i < 10; $i++) {
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
     * 解除し忘れると、日常的な打ち間違いが窓の時間をかけて蓄積し、正規ユーザーが締め出される。
     *
     * 上限−1 回まで失敗させてから成功させるため、上限を**下げる**変更
     * （10 → 9 など）はこのテストが検出する（成功するはずのログインが弾かれる）。
     */
    public function test_successful_login_clears_the_account_level_counter(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 9; $i++) {
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

        for ($i = 0; $i < 10; $i++) {
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

    /**
     * アカウント単位のロックが「窓の長さ」で自然に解けること。
     *
     * ロック時間は攻撃者から見れば「上限回数の投資で買えるサービス停止時間」であり、
     * 正規ユーザーから見れば「復帰までの待ち時間」。しきい値の回数だけを検証していると
     * 保持時間を延ばす変更（900 → 3600 など）が素通りするため、実測値で挟んで固定する。
     */
    public function test_account_level_lock_expires_within_fifteen_minutes(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        $availableIn = RateLimiter::availableIn('login-email|victim@example.com');

        // 窓を延ばす変更（上限側）／短くしすぎる変更（下限側）の両方を検出する
        $this->assertLessThanOrEqual(900, $availableIn);
        $this->assertGreaterThan(600, $availableIn);
    }

    /**
     * ロック時のメッセージが「分」で表示されること。
     *
     * Laravel 既定の :seconds は窓が 60 秒である前提の文言で、15 分窓の第2段では
     * 「900 秒後に再度お試しください」と表示され、読み手に暗算を要求してしまう。
     */
    public function test_throttle_message_is_expressed_in_minutes(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->from('/login')
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        $message = $this->loginErrorMessage();

        $this->assertStringContainsString('15 分後', $message);
        $this->assertStringNotContainsString('秒後', $message);
    }

    /**
     * php artisan auth:unlock でアカウント単位のロックを即時解除できること。
     *
     * 第2段は送信元 IP を見ないため、外部から任意のアカウントを狙ってロックできる。
     * 全管理者を同時にロックされると復旧操作の入口が消えるため、時間経過を待たずに
     * 戻す手段が無いこと自体が障害になる（cache:clear は全レート制限を巻き添えにする）。
     */
    public function test_unlock_command_releases_the_account_level_lock(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', [
                    'email' => $user->email,
                    'password' => 'wrong-password',
                ]);
        }

        // 解除前：正しいパスワードでもログインできない（Auth::attempt の手前で弾かれる）
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);
        $this->assertGuest();

        $this->artisan('auth:unlock', ['email' => 'victim@example.com'])
            ->assertSuccessful();

        // 解除後：本人はログインできる
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    /**
     * 解除コマンドが LoginRequest と同じキー生成（小文字化・transliterate）を使うこと。
     *
     * キーの組み立てが二重定義になっていると、大文字混じりで入力された途端に
     * 「解除したのに解除されない」が無言で起きる。SSOT を共有していることを固定する。
     */
    public function test_unlock_command_matches_the_key_built_by_the_login_request(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post('/login', [
                    // ログイン画面から大文字混じりで入力された場合
                    'email' => 'Victim@Example.com',
                    'password' => 'wrong-password',
                ]);
        }

        $this->artisan('auth:unlock', ['email' => 'VICTIM@EXAMPLE.COM'])
            ->assertSuccessful();

        $this->assertSame(0, RateLimiter::attempts('login-email|victim@example.com'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
    }
}
