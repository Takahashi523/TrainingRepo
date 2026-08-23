<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ResolvesEmailRules;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    use ResolvesEmailRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * ドメイン制限（許容ドメイン設定時）はマスタ管理のユーザー登録と同じ SSOT
     * （ResolvesEmailRules）を共有する二重チェック。ログインは既存ユーザーの照合のため
     * emailDomainRules() を使い、unique は付けない。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailDomainRules(),
            'password' => ['required', 'string'],
        ];
    }

    /**
     * バリデーションメッセージ内の :attribute を日本語表示名に置き換える。
     * （lang/ja/validation.php の attributes は空で、各 FormRequest 側で定義する規約）
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'メールアドレス',
            'password' => 'パスワード',
        ];
    }

    /**
     * ルール個別のメッセージ上書き。
     * email 形式エラーは共通文言だと「メールアドレスに有効なメールアドレスを…」と冗長になるため、
     * ログイン画面では :attribute を含まない簡潔な文言にする。
     *
     * ドメイン制限（ends_with）のエラーでは、マスタ管理（admin 限定）と違い
     * **許可ドメイン名を出さない**。ログイン画面は未認証で誰でも到達できるため、
     * 許可ドメインを晒すと社内ドメインの情報漏えい・標的型フィッシングの足がかりになる。
     * ends_with は許容ドメイン設定時のみ発火するため、常に定義しても未設定時は表示されない。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => '有効なメールアドレスを入力してください。',
            'email.ends_with' => '社内メールアドレスでログインしてください。',
        ];
    }

    /**
     * 送信元 IP ごとの試行上限（1分あたり）。Breeze 既定。
     */
    private const MAX_ATTEMPTS_PER_IP = 5;

    /**
     * アカウント単位の試行上限（1時間あたり）。送信元 IP を分散されても効く第2段。
     */
    private const MAX_ATTEMPTS_PER_EMAIL = 30;

    /**
     * アカウント単位のカウンタの保持時間（秒）。
     */
    private const EMAIL_DECAY_SECONDS = 3600;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->emailThrottleKey(), self::EMAIL_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->emailThrottleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * 2段構えにしている理由：
     *
     * リバースプロキシ配下で X-Forwarded-For を信頼するようになった結果（bootstrap/app.php）、
     * throttleKey() の IP は「前段 Nginx の固定 IP」から「実クライアント IP」に変わった。
     * これは「攻撃者が他人のアカウントを意図的にロックアウトできる」問題を解消した一方で、
     * 送信元を分散されると `5 × 送信元数` 回/分まで試行できることを意味し、
     * 総当たりに対しては**弱くなる**。IP 単独の上限では塞げないため、
     * アカウント単位の上限を第2段として重ねる。
     *
     * ⚠️ トレードオフ：アカウント単位の上限は、原理上「攻撃者が特定ユーザーを
     *    ロックアウトできる」経路を作り直すことになる。社内ツールで正規ユーザーが
     *    1時間に30回も失敗しないことを踏まえ、しきい値を高めに置いて
     *    「通常利用では踏まないが、総当たりは止まる」水準に寄せている。
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $limits = [
            [$this->throttleKey(), self::MAX_ATTEMPTS_PER_IP],
            [$this->emailThrottleKey(), self::MAX_ATTEMPTS_PER_EMAIL],
        ];

        foreach ($limits as [$key, $maxAttempts]) {
            if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                continue;
            }

            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /**
     * 送信元 IP を含まない、アカウント単位のレート制限キー。
     *
     * throttleKey() と衝突しないよう接頭辞を付ける（IP 部分が無いだけの文字列だと、
     * 「メールアドレスに `|` を含む入力」で別キーと重なりうる）。
     */
    public function emailThrottleKey(): string
    {
        return 'login-email|'.Str::transliterate(Str::lower($this->string('email')));
    }
}
