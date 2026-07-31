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
            'keyword' => ['nullable', 'string', 'max:100'],
            'work_styles' => ['nullable', 'array'],
            'work_styles.*' => [Rule::in(EngineerRules::workStyleValues())],
        ];
    }
}
