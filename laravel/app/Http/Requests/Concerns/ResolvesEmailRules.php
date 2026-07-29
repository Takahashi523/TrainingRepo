<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * ユーザーのメールアドレス（ログインID）バリデーションルールを組み立てる共通処理。
 *
 * 一意制約と、config/organization.php の許容ドメイン（環境変数由来・複数対応）による
 * ドメイン制限を Store / Update で共有する。ドメイン未設定時は形式チェックのみ（TBD#3）。
 */
trait ResolvesEmailRules
{
    /**
     * @param  int|null  $ignoreId  更新時に一意制約から除外する自ユーザーID
     * @return array<int, mixed>
     */
    protected function emailRules(?int $ignoreId = null): array
    {
        $rules = [
            'required',
            'string',
            'email',
            'max:255',
            $ignoreId
                ? Rule::unique('users', 'email')->ignore($ignoreId)
                : Rule::unique('users', 'email'),
        ];

        $domains = config('organization.allowed_email_domains');
        if (! empty($domains)) {
            // いずれかの許容ドメインで終わればOK（@ 込みで境界を固定し部分一致を防ぐ）
            $rules[] = 'ends_with:'.implode(',', array_map(fn ($d) => '@'.$d, $domains));
        }

        return $rules;
    }

    /**
     * メールアドレスのバリデーションメッセージ（許容ドメインを明示する）。
     *
     * ends_with の既定文言は機械的で分かりづらいため、許可ドメインを列挙した
     * 依頼形メッセージに差し替える。ドメイン未設定時は差し替え不要（空配列）。
     *
     * @return array<string, string>
     */
    protected function emailMessages(): array
    {
        $domains = config('organization.allowed_email_domains');
        if (empty($domains)) {
            return [];
        }

        $list = implode('、', array_map(fn ($d) => '@'.$d, $domains));

        return [
            'email.ends_with' => "社内メールアドレス（{$list}）で登録してください。",
        ];
    }
}
