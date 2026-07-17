<?php

namespace App\Http\Requests;

use App\Models\Engineer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * マッチング結果画面からのパイプライン生成（POST /pipelines）の形式バリデーション。
 *
 * 重複・上限（1案件5件）チェックは競合制御を伴うため PipelineService@create の
 * トランザクション内で行う（本 Request は形式検証に限定する）。
 * status は 'proposed' 固定（QA #49）・担当は engineers.main_user_id 参照（QA #83）のため
 * いずれもフロントから受け取らない。
 */
class PipelineStoreRequest extends FormRequest
{
    /**
     * 生成はロール不問（管理者・一般営業とも可）。auth ミドルウェアで保護する。
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'engineer_id' => ['required', 'integer', 'exists:engineers,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            // マッチング実行時点のスナップショット（サーバー側で再計算しない・QA #45）
            'match_score' => ['required', 'integer', 'between:0,100'],
            'match_rank' => ['required', 'in:A,B,C,D'],
            'ai_score_reason' => ['nullable', 'string'],
            'ai_comment' => ['nullable', 'string'],
            'ai_missing' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'engineer_id' => '人材',
            'project_id' => '案件',
            'match_score' => 'マッチングスコア',
            'match_rank' => 'マッチングランク',
        ];
    }

    /**
     * バリデーション失敗時の遷移先を制御する。
     *
     * 対象人材が存在しない（マッチング計算中〜結果表示中に管理者が削除した等）場合、既定の back
     * リダイレクトだと削除済み人材のマッチング画面（/engineers/{engineer}/matching）へ戻り、
     * route model binding が 404 になってしまう。人材はもう存在しないため、404 ではなく人材一覧へ
     * 誘導し、フラッシュで理由を通知する（Silent Rejection・不親切な 404 を回避）。
     *
     * それ以外（案件削除・スコア不正など、人材が存命のケース）は従来どおり back へ戻し、
     * ドロワー内にフィールドエラーを表示する。
     */
    protected function failedValidation(Validator $validator): void
    {
        $engineerId = $this->input('engineer_id');

        if ($engineerId !== null && ! Engineer::whereKey($engineerId)->exists()) {
            throw new HttpResponseException(
                redirect()->route('engineers.index')
                    ->with('error', '対象の人材が見つかりません。削除された可能性があります。')
            );
        }

        parent::failedValidation($validator);
    }
}
