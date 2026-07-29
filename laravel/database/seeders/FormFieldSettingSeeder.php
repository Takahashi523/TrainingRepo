<?php

namespace Database\Seeders;

use App\Models\FormFieldSetting;
use Illuminate\Database\Seeder;

class FormFieldSettingSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------------------------------------------
        // engineer（人材登録フォーム）
        // ----------------------------------------------------------------

        // システム固定必須（管理者も変更不可）。氏名カナは EngineerRequest で常に required のため
        // docs/api/09 どおり system-required に含める（PR #21 指摘の是正）。
        $systemRequiredFields = ['name', 'name_kana', 'status', 'main_user_id'];

        foreach ($systemRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'engineer', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => true],
            );
        }

        // 初期値：必須（docs/api/09 の WF_12 初期値に準拠）
        $engineerRequiredFields = [
            'birth_date', 'nearest_station', 'nearest_line', 'available_from',
            'skills', 'proc_experience', 'has_negotiation_exp',
        ];

        foreach ($engineerRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'engineer', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => false],
            );
        }

        // 初期値：任意
        $engineerOptionalFields = [
            'appeal_note', 'desired_rate', 'work_styles', 'remarks',
        ];

        foreach ($engineerOptionalFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'engineer', 'field_key' => $key],
                ['is_required' => false, 'is_system_required' => false],
            );
        }

        // ----------------------------------------------------------------
        // project（案件登録フォーム）
        // ----------------------------------------------------------------

        // システム固定必須
        $projectSystemRequiredFields = ['name', 'status', 'main_user_id'];

        foreach ($projectSystemRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => true],
            );
        }

        // 初期値：必須
        $projectRequiredFields = [
            'required_skills',
            'rate',
            'start_date',
            'work_style',
            'work_location',
            'commercial_flow',
            'proc_experience',
            'negotiation_required',
            'description',
        ];

        foreach ($projectRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => false],
            );
        }

        // 初期値：任意
        $projectOptionalFields = [
            'client_name',
            'preferred_skills',
            'interview_count',
            'headcount',
            'work_env',
            'billing_range',
            'remarks',
        ];

        foreach ($projectOptionalFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => false, 'is_system_required' => false],
            );
        }
    }
}
