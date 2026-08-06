<?php

namespace App\Http\Resources;

use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ダッシュボード「近日アクション予定」1行を整形する。
 *
 * - status_label は Pipeline::STATUSES（SSOT）から解決する（重複定義しない）。
 * - is_overdue は Controller 側で判定して属性として付与済み（このリソースは転記のみ）。
 * - engineer / project は Controller で Eager Load 済み（N+1 回避）。
 *
 * @property Pipeline $resource
 */
class UpcomingActionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'next_action_date' => $this->next_action_date?->format('Y-m-d'),
            'is_overdue' => (bool) $this->is_overdue,
            'status' => $this->status,
            'status_label' => Pipeline::label($this->status),
            'engineer' => [
                'id' => $this->engineer->id,
                'name' => $this->engineer->name,
            ],
            'project' => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ],
        ];
    }
}
