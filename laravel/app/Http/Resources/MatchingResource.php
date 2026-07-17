<?php

namespace App\Http\Resources;

use App\Models\Engineer;
use App\Models\Project;
use App\Services\Matching\MatchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * マッチング結果1件（results[] の1要素）を整形する。
 *
 * マッチングエンジン（Python）の5点セット（{@see MatchResult}）と、DB から取得した
 * 案件情報を突合して返す。マッチング API 設計書（05_マッチング）の results[] Props に準拠。
 * 案件の TEXT（description / work_env / remarks）はカード・ドロワーの表示対象外のため含めない。
 *
 * 期待する resource 構造（Controller で組み立てる）:
 *   ['result' => MatchResult, 'project' => Project(projectSkills ロード済), 'is_in_pipeline' => bool]
 */
class MatchingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var MatchResult $result */
        $result = $this->resource['result'];
        /** @var Project $project */
        $project = $this->resource['project'];
        $isInPipeline = (bool) $this->resource['is_in_pipeline'];

        return [
            // --- カード表示用スコア情報 ---
            'match_score' => $result->matchScore,
            'match_rank' => $result->matchRank,

            // --- ドロワー表示用AIテキスト ---
            'ai_score_reason' => $result->aiScoreReason,
            'ai_comment' => $result->aiComment,
            'ai_missing' => $result->aiMissing,

            // --- 案件情報（カード・ドロワー共通。TEXT は除外） ---
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client_name,
                'commercial_flow' => $project->commercial_flow,
                'headcount' => $project->headcount,
                'rate_min' => $project->rate_min,
                'rate_max' => $project->rate_max,
                'rate_note' => $project->rate_note,
                'work_style' => $project->work_style,
                'start_date' => optional($project->start_date)->format('Y-m-d') ?? $project->start_date,
                // 開始時期ラベルは人材の available_label と同じ生成規則で揃える。
                'start_label' => $project->start_date
                    ? Carbon::parse($project->start_date)->format('Y/m/d').'〜'
                    : '未定',
                'required_skills' => $this->skillLabels($project, 'required'),
                'preferred_skills' => $this->skillLabels($project, 'preferred'),
                // 対象工程（6固定）。キー/名称は人材と共通の Engineer::PHASES を再利用する（DRY）。
                'phases' => array_map(fn ($phase) => [
                    'key' => $phase['key'],
                    'name' => $phase['name'],
                    'is_target' => (bool) $project->{$phase['key']},
                ], Engineer::PHASES),
            ],

            // --- パイプライン追加状態（追加ボタンの活性制御） ---
            'is_in_pipeline' => $isInPipeline,
        ];
    }

    /**
     * 指定 skill_type のスキルラベル配列を返す（detail は表示対象外のため含めない）。
     *
     * @return array<int, array{label: string}>
     */
    private function skillLabels(Project $project, string $skillType): array
    {
        return $project->projectSkills
            ->where('skill_type', $skillType)
            ->map(fn ($skill) => ['label' => $skill->label])
            ->values()
            ->all();
    }
}
