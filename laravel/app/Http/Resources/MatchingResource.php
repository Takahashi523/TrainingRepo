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
        $isAvailable = (bool) $this->resource['is_available'];
        $isProjectFull = (bool) $this->resource['is_project_full'];

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
                // 表示ラベルは Project の ENUM ラベル SSOT から解決してサーバー側で返す（フロントでの
                // ラベル再定義をなくす）。未設定（null）は null のまま返し、フロントで「未設定/未定」を出す。
                'commercial_flow_label' => $project->commercial_flow !== null
                    ? (Project::COMMERCIAL_FLOW_LABELS[$project->commercial_flow] ?? $project->commercial_flow)
                    : null,
                'headcount' => $project->headcount,
                'rate_min' => $project->rate_min,
                'rate_max' => $project->rate_max,
                'rate_note' => $project->rate_note,
                'work_style_label' => $project->work_style !== null
                    ? (Project::WORK_STYLE_LABELS[$project->work_style] ?? $project->work_style)
                    : null,
                // 掲載状態ラベル（open=募集中 / closed=終了 / pending=ペンディング）。フロントで
                // is_available=false のとき「終了／ペンディング」の正確な表示に使う。
                'status_label' => Project::STATUS_LABELS[$project->status] ?? $project->status,
                // start_date は Carbon キャストされていない文字列のため optional()->format() は常に no-op
                // （常に null→生値フォールバック）になる。Carbon::parse で明示的に Y-m-d へ正規化する
                // （直下の start_label と同じ生成規則）。null は null のまま返す。
                'start_date' => $project->start_date
                    ? Carbon::parse($project->start_date)->format('Y-m-d')
                    : null,
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
            // --- 追加可否。false（open 以外＝終了/ペンディング）ならステータス表示＋追加無効化（フロント） ---
            'is_available' => $isAvailable,
            // --- 上限到達（既存5件）。true なら「上限到達」表示＋追加無効化（フロント） ---
            'is_project_full' => $isProjectFull,
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
