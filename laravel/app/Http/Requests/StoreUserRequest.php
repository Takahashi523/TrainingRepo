<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesEmailRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * マスタ管理：ユーザー追加のバリデーション。
 * 認可はルートの admin ミドルウェアで担保済みのため authorize は true。
 */
class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => $this->emailRules(),
            'password' => ['required', 'max:255', Password::defaults()],
            // 一致チェックは確認欄側に付け、エラーを「パスワード（確認）」に表示する
            'password_confirmation' => ['required', 'same:password'],
            'role' => ['required', 'in:admin,general'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->emailMessages(), [
            'password_confirmation.same' => '確認用のパスワードが一致しません。',
        ]);
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
}
