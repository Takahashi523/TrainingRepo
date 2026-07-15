<?php

namespace App\Http\Resources;

use App\Models\Engineer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class EngineerListResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
            'age'  => $this->birth_date
                ? Carbon::parse($this->birth_date)->age
                : null,
            'nearest_station' => $this->nearest_station,
            'nearest_line'    => $this->nearest_line,
            'status'          => $this->status,
            'available_from'  => $this->available_from,
            'available_label' => $this->available_from
                ? Carbon::parse($this->available_from)->format('Y/m/d') . '〜'
                : '未定',
            'users' => [
                'main' => ['id' => $this->mainUser->id, 'name' => $this->mainUser->name],
                'sub'  => $this->subUser
                    ? ['id' => $this->subUser->id, 'name' => $this->subUser->name]
                    : null,
            ],
            'skills' => $this->skills->map(fn ($s) => ['label' => $s->label])->values()->all(),
            'phases' => array_map(fn ($phase) => [
                'key'            => $phase['key'],
                'name'           => $phase['name'],
                'has_experience' => (bool) $this->{$phase['key']},
            ], Engineer::PHASES),
            'work_styles' => array_values(array_filter(
                array_map(fn ($ws) => $this->{'work_style_' . $ws['key']}
                    ? ['key' => $ws['key'], 'name' => $ws['name']]
                    : null,
                    Engineer::WORK_STYLES
                )
            )),
            'updated_at' => $this->updated_at,
        ];
    }
}
