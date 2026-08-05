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
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
