<?php

namespace App\Http\Requests;

use App\Models\SavedSearch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 保存検索条件（POST /saved-searches）の検証。
 *
 * conditions の中身（status/work_styles/phases/sort/order 等）は
 * EngineerIndexRequest / ProjectIndexRequest と同じ方針で個別バリデーションしない。
 * ここでは conditions が配列であることのみ確認し、各キーの許可値との突き合わせは
 * Controller 側でホワイトリストとの積を取って無害化する。
 */
class SavedSearchRequest extends FormRequest
{
    /**
     * ログイン済みなら誰でも自分の保存検索条件を作成できる（auth ミドルウェアで保護済み）。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'search_type' => ['required', Rule::in(SavedSearch::SEARCH_TYPES)],
            'conditions' => ['required', 'array'],
        ];
    }
}
