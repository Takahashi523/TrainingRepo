<?php

namespace Database\Seeders;

use App\Models\FormFieldSetting;
use Illuminate\Database\Seeder;

class ProjectFormFieldSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // システム固定必須
        $systemRequiredFields = ['name', 'status', 'main_user_id'];

        foreach ($systemRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => true],
            );
        }

        // 初期値：必須
        $requiredFields = [
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

        foreach ($requiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => false],
            );
        }

        // 初期値：任意
        $optionalFields = [
            'client_name',
            'preferred_skills',
            'interview_count',
            'headcount',
            'work_env',
            'billing_range',
            'remarks',
        ];

        foreach ($optionalFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => false, 'is_system_required' => false],
            );
        }
    }
}
