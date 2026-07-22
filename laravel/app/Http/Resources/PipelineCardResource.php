<?php

namespace App\Http\Resources;

use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 進行中カンバンカード用。TEXT カラム（ai_* / client_comment / ng_reason）は含めない。
 */
class PipelineCardResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => Pipeline::label($this->status),
            'match_score' => $this->match_score,
            'match_rank' => $this->match_rank,
            'next_action_date' => $this->next_action_date?->format('Y-m-d'),
            'updated_at' => $this->updated_at,
            'engineer' => [
                'id' => $this->engineer->id,
                'name' => $this->engineer->name,
                // main_user_id は NOT NULL 想定だが Resource 側で null 安全に防御
                'main_user' => $this->engineer->mainUser
                    ? ['id' => $this->engineer->mainUser->id, 'name' => $this->engineer->mainUser->name]
                    : null,
            ],
            'project' => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'client_name' => $this->project->client_name,
            ],
        ];
    }
}
