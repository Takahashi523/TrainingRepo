<?php

namespace App\Services;

use App\Models\FormFieldSetting;
use Illuminate\Support\Facades\DB;

/**
 * マスタ管理：フォーム設定（必須/任意）の更新を担うサービス。
 * 即時反映のため通常は1件だが、settings[] を 1〜N 件まとめて更新できる。
 * is_system_required = true のフィールドは更新対象から除外する（DB 条件でも二重にガード）。
 */
class FormSettingService
{
    /**
     * @param  array<int, array{form_type: string, field_key: string, is_required: bool}>  $settings
     */
    public function update(array $settings, int $userId): void
    {
        DB::transaction(function () use ($settings, $userId) {
            foreach ($settings as $setting) {
                FormFieldSetting::where('form_type', $setting['form_type'])
                    ->where('field_key', $setting['field_key'])
                    ->where('is_system_required', false)
                    ->update([
                        'is_required' => $setting['is_required'],
                        'updated_by' => $userId,
                    ]);
            }
        });
    }
}
