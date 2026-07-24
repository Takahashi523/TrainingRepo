<?php

namespace App\Http\Resources;

use App\Models\FormFieldSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * マスタ管理のフォーム設定（必須/任意）用 Resource。
 * 表示名は FormFieldSetting::FIELD_LABELS（SSOT）から取得する。
 *
 * @mixin FormFieldSetting
 */
class FormSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'field_key' => $this->field_key,
            'field_label' => $this->fieldLabel(),
            'is_required' => $this->is_required,
            'is_system_required' => $this->is_system_required,
        ];
    }
}
