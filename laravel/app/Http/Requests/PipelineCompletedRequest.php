<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 完了済みタブ（GET /pipelines/completed）のクエリパラメータ検証。
 * バリデーション設計書 §6「GET /pipelines/completed クエリパラメータ」に準拠する。
 * status[] / user_id / sort / order はコントローラ側のホワイトリスト交差で無害化するため対象外。
 */
class PipelineCompletedRequest extends FormRequest
{
    /**
     * 閲覧はロール不問（auth ミドルウェアで保護済み）。
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'ended_from' => ['nullable', 'date'],
            // after_or_equal は比較先（ended_from）が空だと常に失敗する Laravel の仕様のため、
            // ended_from が入力された場合のみ範囲の前後関係を検証する
            'ended_to' => array_values(array_filter([
                'nullable',
                'date',
                $this->filled('ended_from') ? 'after_or_equal:ended_from' : null,
            ])),
        ];
    }

    /**
     * バリデーションエラー文の項目名を日本語表示にする（PipelineUpdateRequest と同方式）。
     */
    public function attributes(): array
    {
        return [
            'keyword' => 'キーワード',
            'ended_from' => '終了日（開始）',
            'ended_to' => '終了日（終了）',
        ];
    }

    /**
     * 範囲の前後関係エラーはバリデーション設計書のメッセージ例に合わせる。
     */
    public function messages(): array
    {
        return [
            'ended_to.after_or_equal' => '開始日以降の日付を入力してください',
        ];
    }
}
