<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesEmailRules;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * マスタ管理：ユーザー編集のバリデーション。
 * パスワードは省略可（省略時は変更なし）。最後の管理者を general 化する操作を事前検査する。
 */
class UpdateUserRequest extends FormRequest
{
    use ResolvesEmailRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => $this->emailRules($user->id),
            'password' => ['nullable', 'max:255', Password::defaults()],
            // 一致チェックは確認欄側に付け、エラーを「パスワード（確認）」に表示する。
            // パスワードを入力した場合のみ確認欄を必須にする（省略時は変更なし）。
            'password_confirmation' => ['nullable', 'required_with:password', 'same:password'],
            'role' => ['required', 'in:admin,general'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_confirmation.same' => '確認用のパスワードが一致しません。',
            'password_confirmation.required_with' => '確認用のパスワードを入力してください。',
        ];
    }

    /**
     * バリデーションメッセージ用の日本語項目名（案件・進捗と同様に attributes() で定義）。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'email' => 'メールアドレス',
            'password' => 'パスワード',
            'password_confirmation' => 'パスワード（確認）',
            'role' => 'ロール',
        ];
    }

    /**
     * 最後の管理者を一般に降格させる操作をガードする（共通ケースの 422 表示）。
     * 並行時の最終防波堤は UserService 側で行ロックにより再検査する。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User $user */
            $user = $this->route('user');

            $demotingAdmin = $user->role === 'admin' && $this->input('role') === 'general';
            if ($demotingAdmin && User::where('role', 'admin')->count() <= 1) {
                $validator->errors()->add('role', '管理者が不在になるため、最後の管理者を一般に変更できません。');
            }
        });
    }
}
