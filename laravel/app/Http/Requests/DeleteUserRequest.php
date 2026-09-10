<?php

namespace App\Http\Requests;

use App\Models\Engineer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * マスタ管理：ユーザー削除の業務ガード（DELETE はボディなし）。
 * 自己削除・最後の管理者・担当中（主担当）を 422 で弾く（メッセージは docs/api/09 準拠）。
 * 主担当（main_user_id）は FK RESTRICT のため削除不可。副担当（sub_user_id）は SET NULL のため対象外。
 */
class DeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User $user */
            $user = $this->route('user');

            // 1. 自己削除の防止
            if ($this->user()->id === $user->id) {
                $validator->errors()->add('delete', '自分自身のアカウントは削除できません。');

                return;
            }

            // 2. 最後の管理者の削除防止
            if ($user->role === 'admin' && User::where('role', 'admin')->count() <= 1) {
                $validator->errors()->add('delete', '管理者が不在になるため、最後の管理者は削除できません。');

                return;
            }

            // 3. 担当中（主担当）の人材・案件が残っている場合は削除不可
            $engineerCount = Engineer::where('main_user_id', $user->id)->count();
            $projectCount = Project::where('main_user_id', $user->id)->count();

            if ($engineerCount > 0 || $projectCount > 0) {
                $validator->errors()->add(
                    'delete',
                    "担当中の案件が{$projectCount}件、人材が{$engineerCount}件あるため削除できません。".
                    '一覧画面から別の担当者へ変更してから再度実行してください。',
                );
            }
        });
    }
}
