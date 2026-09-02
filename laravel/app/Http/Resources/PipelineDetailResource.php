<?php

namespace App\Http\Resources;

use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ドロワー詳細用。API設計書 #3 の全項目（TEXT カラム含む）を返す。
 */
class PipelineDetailResource extends JsonResource
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
            'ai_score_reason' => $this->ai_score_reason,
            'ai_comment' => $this->ai_comment,
            'ai_missing' => $this->ai_missing,
            'client_comment' => $this->client_comment,
            'ng_reason' => $this->ng_reason,
            'next_action_date' => $this->next_action_date?->format('Y-m-d'),
            'updated_at' => $this->updated_at,
            'version' => $this->version,
            'engineer' => [
                'id' => $this->engineer->id,
                'name' => $this->engineer->name,
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
