<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public static $wrap = null;

    private const PHASES = [
        ['key' => 'proc_requirements',  'name' => '要件定義'],
        ['key' => 'proc_basic_design',  'name' => '基本設計'],
        ['key' => 'proc_detail_design', 'name' => '詳細設計'],
        ['key' => 'proc_development',   'name' => '開発'],
        ['key' => 'proc_testing',       'name' => 'テスト'],
        ['key' => 'proc_maintenance',   'name' => '保守・運用'],
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_name' => $this->client_name,
            'status' => $this->status,
            'commercial_flow' => $this->commercial_flow,
            'headcount' => $this->headcount,
            'start_date' => $this->start_date,
            'rate_min' => $this->rate_min,
            'rate_max' => $this->rate_max,
            'rate_note' => $this->rate_note,
            'work_style' => $this->work_style,
            'work_location_line' => $this->work_location_line,
            'work_location_station' => $this->work_location_station,
            'interview_count' => $this->interview_count,
            'negotiation_required' => $this->negotiation_required,
            'description' => $this->description,
            'work_env' => $this->work_env,
            'billing_range' => $this->billing_range,
            'remarks' => $this->remarks,

            'users' => [
                'main' => [
                    'id' => $this->mainUser->id,
                    'name' => $this->mainUser->name,
                ],
                'sub' => $this->subUser
                    ? ['id' => $this->subUser->id, 'name' => $this->subUser->name]
                    : null,
            ],

            'required_skills' => $this->projectSkills
                ->where('skill_type', 'required')
                ->map(fn ($s) => [
                    'label' => $s->label,
                    'detail' => $s->detail,
                ])
                ->values()
                ->all(),

            'preferred_skills' => $this->projectSkills
                ->where('skill_type', 'preferred')
                ->map(fn ($s) => [
                    'label' => $s->label,
                    'detail' => $s->detail,
                ])
                ->values()
                ->all(),

            'phases' => array_map(fn ($phase) => [
                'key' => $phase['key'],
                'name' => $phase['name'],
                'is_target' => (bool) $this->{$phase['key']},
            ], self::PHASES),

            'updated_at' => $this->updated_at,
        ];
    }
}
