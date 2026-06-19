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
        $systemRequiredFields = ['name', 'status', 'main_user_id'];

        foreach ($systemRequiredFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => true, 'is_system_required' => true],
            );
        }

        $engineerFields = [
            'client_name',
            'required_skills',
            'preferred_skills',
            'rate',
            'start_date',
            'work_style',
            'work_location',
            'commercial_flow',
            'interview_count',
            'headcount',
            'work_env',
            'billing_range',
            'proc_experience',
            'negotiation_required',
            'description',
            'remarks',
        ];

        foreach ($engineerFields as $key) {
            FormFieldSetting::updateOrCreate(
                ['form_type' => 'project', 'field_key' => $key],
                ['is_required' => false, 'is_system_required' => false],
            );
        }
    }
}
