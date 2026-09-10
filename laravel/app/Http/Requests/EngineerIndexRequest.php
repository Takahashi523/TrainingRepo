<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 人材一覧（GET /engineers）のクエリパラメータ検証。
 * キーワードのみを検証する。氏名 max:100・スキルラベル max:15 のため、
 * 100 文字を超えるキーワードは検索対象に一致し得ず、上限を氏名に揃える。
 * status[] / work_styles[] / phases[] / sort / order / per_page は
 * コントローラ側のホワイトリスト交差・クランプで無害化するため対象外。
 */
class EngineerIndexRequest extends FormRequest
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
