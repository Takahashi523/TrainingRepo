<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * ユーザーのメールアドレス（ログインID）バリデーションルールを組み立てる共通処理。
 *
 * 一意制約と、config/master.php の許容ドメイン（環境変数由来・複数対応）による
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

        $domains = config('master.allowed_email_domains');
        if (! empty($domains)) {
            // いずれかの許容ドメインで終わればOK（@ 込みで境界を固定し部分一致を防ぐ）
            $rules[] = 'ends_with:'.implode(',', array_map(fn ($d) => '@'.$d, $domains));
        }

        return $rules;
    }
}
