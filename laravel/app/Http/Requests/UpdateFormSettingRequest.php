<?php

namespace App\Http\Requests;

use App\Models\FormFieldSetting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * マスタ管理：フォーム設定（必須/任意）の更新。
 * 即時反映のため通常は1件だが、エンドポイント仕様として settings[] を 1〜N 件受け付ける（docs/api/09）。
 * is_system_required のフィールドはエラーにせず、Service 側で更新対象から除外する。
 */
class UpdateFormSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.form_type' => ['required', 'in:engineer,project'],
            'settings.*.field_key' => ['required', 'string'],
            'settings.*.is_required' => ['required', 'boolean'],
        ];
    }

    /**
     * form_type と field_key の組み合わせが実在するかを検証（存在しない場合は 422）。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('settings', []) as $i => $setting) {
                $formType = $setting['form_type'] ?? null;
                $fieldKey = $setting['field_key'] ?? null;

                if ($formType === null || $fieldKey === null) {
                    continue; // 基本ルール側でエラーになる
                }

                $exists = FormFieldSetting::where('form_type', $formType)
                    ->where('field_key', $fieldKey)
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        "settings.{$i}.field_key",
                        "指定されたフィールド（{$formType} / {$fieldKey}）は存在しません。",
                    );
                }
            }
        });
    }
}
