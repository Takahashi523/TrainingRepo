<?php

namespace App\Http\Requests;

use App\Models\Pipeline;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PipelineUpdateRequest extends FormRequest
{
    /**
     * 更新はロール不問（管理者・一般営業とも可）。削除のみ PipelinePolicy で認可する。
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ドロワーで変更した項目のみ送信する部分更新のため各項目 nullable
            'status' => ['nullable', Rule::in(array_keys(Pipeline::STATUSES))],
            'client_comment' => ['nullable', 'string', 'max:1000'],
            'ng_reason' => ['nullable', 'string', 'max:1000'],
            'next_action_date' => ['nullable', 'date'],
        ];
    }

    /**
     * バリデーションエラー文の項目名を日本語表示にする（案件側 ProjectRequest と同方式）。
     * これがないと :attribute にフィールド名（client_comment 等）が英語のまま出てしまう。
     */
    public function attributes(): array
    {
        return [
            'status' => 'ステータス',
            'client_comment' => '顧客コメント',
            'ng_reason' => 'NG理由',
            'next_action_date' => '次回アクション予定日',
        ];
    }

    /**
     * 終了ステータス完全ロック（QA #64）。
     * 対象が既に終了済みの場合、更新を一切拒否する（ステータス変更・管理情報の追記とも 422）。
     * 進行中への復帰・別終了ステータスへの変更に加え、client_comment / ng_reason /
     * next_action_date の変更も終了後は不可とする。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Pipeline $pipeline */
            $pipeline = $this->route('pipeline');

            if (Pipeline::isTerminal($pipeline->status)) {
                $validator->errors()->add(
                    'status',
                    '終了したパイプラインは変更できません。'
                );
            }
        });
    }
}
