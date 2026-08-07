<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * ユーザーのメールアドレス（ログインID）バリデーションルールを組み立てる共通処理。
 *
 * 一意制約と、config/organization.php の許容ドメイン（環境変数由来・複数対応）による
 * ドメイン制限を、マスタ管理の Store / Update と、ログイン（二重チェック）で共有する。
 * ドメイン未設定時は形式チェックのみ（TBD#3）。
 */
trait ResolvesEmailRules
{
    /**
     * 一意制約つきのメール検証ルール（マスタ管理の登録・編集用）。
     *
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

        // 既存挙動を維持するため ends_with は unique の後に付ける（エラー優先順位を変えない）。
        if ($constraint = $this->emailDomainConstraint()) {
            $rules[] = $constraint;
        }

        return $rules;
    }

    /**
     * 一意制約を含まないメール検証ルール（形式＋ドメイン制限のみ）。
     *
     * ログインのように「既存ユーザーの照合」を行う文脈では unique を付けてはならない
     * （必ず失敗する）。ドメイン制限だけを Store / Update と共有するために分離する。
     *
     * @return array<int, mixed>
     */
    protected function emailDomainRules(): array
    {
        $rules = ['required', 'string', 'email', 'max:255'];

        if ($constraint = $this->emailDomainConstraint()) {
            $rules[] = $constraint;
        }

        return $rules;
    }

    /**
     * 許容ドメイン設定時の ends_with ルール（未設定なら null）。
     * いずれかの許容ドメインで終わればOK（@ 込みで境界を固定し部分一致を防ぐ）。
     */
    private function emailDomainConstraint(): ?string
    {
        $list = $this->allowedEmailDomains();
        if (empty($list)) {
            return null;
        }

        return 'ends_with:'.implode(',', array_map(fn ($d) => '@'.$d, $list));
    }

    /**
     * メールアドレスのバリデーションメッセージ（許容ドメインを明示する・登録文脈）。
     *
     * ends_with の既定文言は機械的で分かりづらいため、許可ドメインを列挙した
     * 依頼形メッセージに差し替える。ドメイン未設定時は差し替え不要（空配列）。
     *
     * @return array<string, string>
     */
    protected function emailMessages(): array
    {
        $label = $this->allowedEmailDomainLabel();
        if ($label === '') {
            return [];
        }

        return [
            'email.ends_with' => "社内メールアドレス（{$label}）で登録してください。",
        ];
    }

    /**
     * 許容ドメインの表示用ラベル（@付き・「、」区切り）。未設定なら空文字。
     * メッセージ組み立てをログイン側と共有し、列挙ロジックの二重化を防ぐ。
     */
    protected function allowedEmailDomainLabel(): string
    {
        return implode('、', array_map(fn ($d) => '@'.$d, $this->allowedEmailDomains()));
    }

    /**
     * 設定済みの許容ドメイン配列（未設定なら空配列）。
     *
     * @return array<int, string>
     */
    private function allowedEmailDomains(): array
    {
        return (array) config('organization.allowed_email_domains', []);
    }
}
