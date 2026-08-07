<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ProjectListResource extends JsonResource
{
    public static $wrap = null;

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
            'start_label' => $this->start_date
                ? Carbon::parse($this->start_date)->format('Y/m/d') . '〜'
                : '未定',
            'rate_min' => $this->rate_min,
            'rate_max' => $this->rate_max,
            'rate_note' => $this->rate_note,
            'work_style' => $this->work_style,
            'interview_count' => $this->interview_count,
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
                ])
                ->values()
                ->all(),

            'preferred_skills' => $this->projectSkills
                ->where('skill_type', 'preferred')
                ->map(fn ($s) => [
                    'label' => $s->label,
                ])
                ->values()
                ->all(),

            'updated_at' => $this->updated_at,
        ];
    }
}