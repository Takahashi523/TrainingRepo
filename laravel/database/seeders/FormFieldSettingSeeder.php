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

        $systemRequiredFields = ['name', 'status', 'main_user_id'];

        foreach ($systemRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'engineer', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => true],
            );
        }

        $engineerFields = [
            'birth_date', 'nearest_station', 'nearest_line', 'available_from',
            'skills', 'proc_experience', 'has_negotiation_exp', 'appeal_note',
            'desired_rate', 'work_styles', 'remarks',
        ];

        foreach ($engineerFields as $key) {
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
