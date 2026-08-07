<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectIndexRequest extends FormRequest
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
            'keyword' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * バリデーションエラー文の項目名を日本語表示にする。
     */
    public function attributes(): array
    {
        return [
            'keyword' => 'キーワード',
        ];
    }
}
