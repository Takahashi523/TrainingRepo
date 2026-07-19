<?php

namespace App\Http\Requests;

use App\Models\FormFieldSetting;
use Illuminate\Foundation\Http\FormRequest;

class EngineerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name_kana')) {
            $this->merge(['name_kana' => str_replace(' ', '　', $this->name_kana)]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'name'            => ['required', 'string', 'max:100'],
            'name_kana'       => ['required', 'string', 'max:100', 'regex:/^[ァ-ヶー　]+$/u'],
            'status'          => ['required', 'in:proposable,interviewing,not_proposable'],
            'main_user_id'    => ['required', 'integer', 'exists:users,id'],
            'sub_user_id'     => ['nullable', 'integer', 'exists:users,id', 'different:main_user_id'],
            'skills'          => ['nullable', 'array'],
            'skills.*.label'  => ['required_with:skills.*.detail', 'nullable', 'string', 'max:15'],
            'skills.*.detail' => ['nullable', 'string', 'max:500'],
            'work_styles'     => ['nullable', 'array'],
            'work_styles.*'   => ['in:onsite,hybrid,remote'],
        ];

        $settings = FormFieldSetting::where('form_type', 'engineer')
            ->pluck('is_required', 'field_key');

        $dynamicFields = [
            'birth_date'          => ['date', 'before_or_equal:today'],
            'nearest_station'     => ['string', 'max:100'],
            'nearest_line'        => ['string', 'max:100'],
            'available_from'      => ['date'],
            'has_negotiation_exp' => ['boolean'],
            'appeal_note'         => ['string', 'max:4000'],
            'desired_rate'        => ['integer', 'min:0', 'max:999'],
            'remarks'             => ['string', 'max:1000'],
        ];

        foreach ($dynamicFields as $field => $fieldRules) {
            $required = (bool) $settings->get($field, 0);
            $rules[$field] = array_merge([$required ? 'required' : 'nullable'], $fieldRules);
        }

        $procRequired = (bool) $settings->get('proc_experience', 0);
        foreach (['proc_requirements', 'proc_basic_design', 'proc_detail_design', 'proc_development', 'proc_testing', 'proc_maintenance'] as $field) {
            $rules[$field] = [$procRequired ? 'required' : 'nullable', 'boolean'];
        }

        if ((bool) $settings->get('work_styles', 0)) {
            $rules['work_styles'] = ['required', 'array', 'min:1'];
        }

        if ((bool) $settings->get('skills', 0)) {
            $rules['skills']         = ['required', 'array', 'min:1'];
            $rules['skills.*.label'] = ['required', 'string', 'max:15'];
        }

        return $rules;
    }
}
