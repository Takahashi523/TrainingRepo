<?php

namespace App\Http\Requests;

use App\Models\FormFieldSetting;
use App\Validation\EngineerRules;
use Illuminate\Foundation\Http\FormRequest;

class EngineerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: 認証済みユーザーであることは auth ミドルウェアで担保。
        // ロール制御が必要な場合はここに追加する。
        return true;
    }

    protected function prepareForValidation(): void
    {
        // 氏名・氏名カナともに半角スペースを全角スペースに正規化する（#21-3）。
        // CSV インポートでも同様に正規化し、フォームと挙動を一致させる。
        foreach (['name', 'name_kana'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => str_replace(' ', '　', $value)]);
            }
        }
    }

    public function rules(): array
    {
        // 書式・範囲ルールは共有の単一出所（EngineerRules）から取得し、
        // 必須/任意はここで form_field_settings に応じて前置きする（DRY）。
        $shared = EngineerRules::formatRules();

        $settings = FormFieldSetting::where('form_type', 'engineer')
            ->pluck('is_required', 'field_key');

        // 第1層：システム固定必須
        $rules = [
            'name' => ['required', ...$shared['name']],
            'name_kana' => ['required', ...$shared['name_kana']],
            'status' => ['required', ...$shared['status']],
            'main_user_id' => ['required', ...$shared['main_user_id']],
            'sub_user_id' => ['nullable', ...$shared['sub_user_id']],
        ];

        // 第2層：form_field_settings で必須/任意を制御する動的フィールド
        $dynamicFields = [
            'birth_date', 'nearest_station', 'nearest_line', 'available_from',
            'has_negotiation_exp', 'appeal_note', 'desired_rate', 'remarks',
        ];
        foreach ($dynamicFields as $field) {
            $required = (bool) $settings->get($field, 0);
            $rules[$field] = [$required ? 'required' : 'nullable', ...$shared[$field]];
        }

        // 対象工程：proc_experience の1設定で proc_* 6フィールドをまとめて制御する
        $procRequired = (bool) $settings->get('proc_experience', 0);
        foreach (['proc_requirements', 'proc_basic_design', 'proc_detail_design',
            'proc_development', 'proc_testing', 'proc_maintenance'] as $field) {
            $rules[$field] = [$procRequired ? 'required' : 'nullable', ...$shared[$field]];
        }

        // 勤務形態：二重定義（固定 nullable → 後から上書き）を廃し、設定に応じて1回で確定する（#21-1）
        $rules['work_styles'] = (bool) $settings->get('work_styles', 0)
            ? ['required', 'array', 'min:1']
            : ['nullable', 'array'];
        $rules['work_styles.*'] = $shared['work_styles.*'];

        // スキル：work_styles と同様に1回で確定する（define-then-override をしない）
        if ((bool) $settings->get('skills', 0)) {
            $rules['skills'] = ['required', 'array', 'min:1'];
            $rules['skills.*.label'] = ['required', ...$shared['skills.*.label']];
        } else {
            $rules['skills'] = ['nullable', 'array'];
            $rules['skills.*.label'] = ['required_with:skills.*.detail', 'nullable', ...$shared['skills.*.label']];
        }
        $rules['skills.*.detail'] = ['nullable', ...$shared['skills.*.detail']];

        return $rules;
    }

    /**
     * バリデーションメッセージ用の日本語属性名（#21-4a）。
     * トップレベルのルールメッセージ（lang/ja/validation.php）と組み合わせることで、
     * フィールド固有の custom メッセージを持たない項目でも自然な日本語エラーになる。
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'name_kana' => '氏名カナ',
            'status' => 'ステータス',
            'main_user_id' => '主担当営業',
            'sub_user_id' => 'サブ担当営業',
            'birth_date' => '生年月日',
            'nearest_station' => '最寄駅',
            'nearest_line' => '路線名',
            'available_from' => '稼働可能時期',
            'has_negotiation_exp' => '顧客折衝経験',
            'appeal_note' => 'アピールポイント',
            'desired_rate' => '希望単価',
            'remarks' => '特記事項',
            'work_styles' => '勤務形態',
            'work_styles.*' => '勤務形態',
            'skills' => 'スキル',
            'skills.*.label' => 'スキル名',
            'skills.*.detail' => 'スキル詳細',
            'proc_requirements' => '要件定義',
            'proc_basic_design' => '基本設計',
            'proc_detail_design' => '詳細設計',
            'proc_development' => '開発',
            'proc_testing' => 'テスト',
            'proc_maintenance' => '保守運用',
        ];
    }
}
