<?php

namespace Database\Seeders;

use App\Models\FormFieldSetting;
use Illuminate\Database\Seeder;

class FormFieldSettingSeeder extends Seeder
{
    public function run(): void
    {
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
    }
}
