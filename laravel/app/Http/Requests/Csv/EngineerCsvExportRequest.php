<?php

namespace App\Http\Requests\Csv;

use App\Validation\EngineerRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 人材エクスポートの絞り込みパラメータ検証（api/08 #3 / §8）。
 * enum の許容値は共有ルール（Model 定数由来）から取得し二重管理を避ける。
 */
class EngineerCsvExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(EngineerRules::statusValues())],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'available_from_start' => ['nullable', 'date'],
            'available_from_end' => ['nullable', 'date', 'after_or_equal:available_from_start'],
            // スキルラベルは VARCHAR(15)・前方一致検索のため、16文字以上は構造上ゼロ件確定。上限をラベル長に揃える。
            'keyword' => ['nullable', 'string', 'max:15'],
            'work_styles' => ['nullable', 'array'],
            'work_styles.*' => [Rule::in(EngineerRules::workStyleValues())],
        ];
    }

    /**
     * エラーメッセージ用の属性名を日本語化する（既定だと user_id の exists 失敗が
     * 「選択されたuser idは存在しません。」と英語キーのまま表示されるため）。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => '担当者',
        ];
    }
}
