<?php

namespace App\Console\Commands;

use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ログイン試行のアカウント単位ロックを解除するコマンド。
 *
 * 第2段のレート制限（LoginRequest::ensureIsNotRateLimited）は送信元 IP を見ないため、
 * 攻撃者が任意のアカウントを狙って上限まで叩ける＝意図的にロックアウトできる。
 * ロック中は正しいパスワードを持つ本人でも Auth::attempt に到達せず弾かれ、
 * 本アプリの管理操作はすべてログインの先にあるため、全管理者が同時にロックされると
 * 復旧の入口自体が消える。時間経過（EMAIL_DECAY_SECONDS）を待たずに戻す手段が要る。
 *
 * `php artisan cache:clear` でも解除できるが、それは**全レート制限を一斉に消す**ため、
 * 進行中のブルートフォースに対する防御まで巻き添えで消える。ここは1アカウントだけを戻す。
 *
 * 例:
 *   php artisan auth:unlock user@example.co.jp
 */
class UnlockLogin extends Command
{
    protected $signature = 'auth:unlock {email : ロックを解除するメールアドレス}';

    protected $description = 'ログイン試行のアカウント単位ロックを解除する';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        // キーの組み立ては LoginRequest 側の SSOT を必ず経由する
        // （二重定義にすると「解除したのに解除されない」が無言で起きる）
        $key = LoginRequest::emailThrottleKeyFor($email);

        $attempts = RateLimiter::attempts($key);

        if ($attempts === 0) {
            $this->info("{$email} はロックされていません（試行回数 0 回）。");

            return self::SUCCESS;
        }

        RateLimiter::clear($key);

        $this->info("{$email} のロックを解除しました（試行回数 {$attempts} 回をリセット）。");

        // 第1段（メールアドレス＋送信元 IP）は攻撃元の IP に紐づくため、ここでは触らない。
        // 本人の IP から 1 分待てば自然に解けるうえ、IP を指定して消せる形にすると
        // 「攻撃元 IP の制限を運用者が誤って外す」経路を作ることになる。
        $this->line('※ 送信元 IP 単位の制限（5回/分）は最大1分で自然に解除されます。');

        return self::SUCCESS;
    }
}
