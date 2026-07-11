<?php

namespace App\Services;

use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;

/**
 * パイプラインの状態変更ロジックをコントローラから切り出す（単一責任・薄いコントローラ）。
 * バリデーション（終了ロックガード）は PipelineUpdateRequest 側に残し、
 * 本 Service は「検証済みデータの永続化」に責務を限定する。
 */
class PipelineService
{
    /**
     * パイプラインを部分更新する。
     * 進行中 → 終了ステータスへ遷移した場合は ended_at を now() で記録する。
     * （終了済みパイプラインの更新は PipelineUpdateRequest で一切 422 ブロック済みのため、ここには到達しない）
     *
     * @param  array<string, mixed>  $data  status / client_comment / ng_reason / next_action_date のうち送信されたキー
     */
    public function update(Pipeline $pipeline, array $data): Pipeline
    {
        return DB::transaction(function () use ($pipeline, $data) {
            if (array_key_exists('status', $data)
                && Pipeline::isTerminal($data['status'])
                && ! Pipeline::isTerminal($pipeline->status)) {
                $data['ended_at'] = now();
            }

            $pipeline->update($data);

            return $pipeline;
        });
    }

    /**
     * パイプラインを物理削除する（認可はコントローラ側の Policy で担保）。
     */
    public function delete(Pipeline $pipeline): void
    {
        $pipeline->delete();
    }
}
