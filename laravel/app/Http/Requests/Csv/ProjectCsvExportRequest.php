<?php

namespace App\Http\Requests\Csv;

use App\Validation\ProjectRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 案件エクスポートの絞り込みパラメータ検証（api/08 #5 / §8）。
 * enum の許容値は共有ルール（Model 定数由来）から取得し二重管理を避ける。
 */
class ProjectCsvExportRequest extends FormRequest
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
            'status.*' => [Rule::in(ProjectRules::statusValues())],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date_from' => ['nullable', 'date'],
            'start_date_to' => ['nullable', 'date', 'after_or_equal:start_date_from'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'work_style' => ['nullable', 'array'],
            'work_style.*' => [Rule::in(ProjectRules::workStyleValues())],
        ];
    }
}
