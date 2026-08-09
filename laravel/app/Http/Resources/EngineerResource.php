<?php

namespace App\Http\Resources;

use App\Models\Engineer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngineerResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_kana' => $this->name_kana,
            'birth_date' => $this->birth_date,
            'age' => $this->age,
            'status' => $this->status,
            'nearest_station' => $this->nearest_station,
            'nearest_line' => $this->nearest_line,
            'available_from' => $this->available_from,
            'available_label' => $this->available_label,
            'users' => [
                'main' => ['id' => $this->mainUser->id, 'name' => $this->mainUser->name],
                'sub' => $this->subUser
                    ? ['id' => $this->subUser->id, 'name' => $this->subUser->name]
                    : null,
            ],
            'skills' => $this->skills->map(fn ($s) => [
                'label' => $s->label,
                'detail' => $s->detail,
            ])->values()->all(),
            'phases' => array_map(fn ($phase) => [
                'key' => $phase['key'],
                'name' => $phase['name'],
                'has_experience' => (bool) $this->{$phase['key']},
            ], Engineer::PHASES),
            'work_styles' => array_values(array_filter(
                array_map(fn ($ws) => $this->{'work_style_'.$ws['key']}
                    ? ['key' => $ws['key'], 'name' => $ws['name']]
                    : null,
                    Engineer::WORK_STYLES
                )
            )),
            'has_negotiation_exp' => $this->has_negotiation_exp,
            'appeal_note' => $this->appeal_note,
            'desired_rate' => $this->desired_rate,
            'remarks' => $this->remarks,
            'ai_summary' => $this->ai_summary,
            'ai_summary_generated_at' => $this->ai_summary_generated_at,
            'updated_at' => $this->updated_at,
            // 削除確認ダイアログの件数警告用。show() の loadCount('pipelines') で付与される。
            'pipelines_count' => (int) ($this->pipelines_count ?? 0),
        ];
    }
}
