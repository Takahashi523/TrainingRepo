<?php

namespace App\Services\Matching;

use App\Models\Pipeline;
use App\Models\Project;

/**
 * マッチング結果カード1件分の「追加可否に関わる現在状態」を DB から再計算する。
 *
 * パイプライン追加が失敗した際、フロントは results をローカル state で保持しており
 * （追加後の再スコアリングを避ける #4 / 10-7）、失敗しても対象カードのフラグが更新されない。
 * その結果、掲載停止・上限到達・追加済みになったカードが古い表示のまま残り、追加ボタンが
 * 再度押せてしまう。マッチングエンジンを再実行せず（コスト・並び替え・engine_error 化を回避）、
 * 「試行した案件1件の真実」だけを返してフロントが該当カードを差分更新できるようにする。
 *
 * MatchingController@show の突合ロジック（is_in_pipeline / is_available / is_project_full）の
 * 単一案件版。判定基準（open のみ追加可・進行中5件で上限）は同 Controller と揃える。
 */
final class MatchTargetStateResolver
{
    /**
     * @return array{project_id: int, exists: false}|array{project_id: int, exists: true, is_in_pipeline: bool, is_available: bool, is_project_full: bool, status_label: string}
     */
    public static function resolve(int $projectId, int $engineerId): array
    {
        // status のみ取得（表示は status_label に解決する）。ハード削除済みは exists=false でカード除去を促す。
        $status = Project::whereKey($projectId)->value('status');

        if ($status === null) {
            return ['project_id' => $projectId, 'exists' => false];
        }

        $isInPipeline = Pipeline::query()
            ->where('engineer_id', $engineerId)
            ->where('project_id', $projectId)
            ->exists();

        // 上限は進行中（アクティブ）のみで数える（QA #50・アクティブ5件。終了済みは枠を消費しない）。
        $activeCount = Pipeline::query()
            ->where('project_id', $projectId)
            ->whereIn('status', Pipeline::inProgressValues())
            ->count();

        return [
            'project_id' => $projectId,
            'exists' => true,
            'is_in_pipeline' => $isInPipeline,
            // 追加可能なのは募集中（open）のみ。closed/pending は「掲載停止」表示＋追加無効化。
            'is_available' => $status === 'open',
            'is_project_full' => $activeCount >= Pipeline::MAX_PER_PROJECT,
            'status_label' => Project::STATUS_LABELS[$status] ?? $status,
        ];
    }
}
