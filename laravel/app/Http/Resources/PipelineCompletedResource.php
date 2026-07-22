<?php

namespace App\Http\Resources;

use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 完了済みテーブル行用。TEXT のうち ng_reason（NG理由/備考列）のみ含む。
 * client_comment / ai_* は含めない。
 */
class PipelineCompletedResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => Pipeline::label($this->status),
            'ng_reason' => $this->ng_reason,
            'ended_at' => $this->ended_at,
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
