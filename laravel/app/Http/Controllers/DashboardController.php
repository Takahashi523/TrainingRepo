<?php

namespace App\Http\Controllers;

use App\Http\Resources\UpcomingActionResource;
use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ダッシュボード（WF_02 / 画面02）。
 *
 * 参照専用（GET のみ）。集計軸はすべてログインユーザー（auth()->id()）で決定する。
 * ロール差が無いため Policy は設けず auth ミドルウェアのみで認可する
 * （管理者・一般営業ともにアクセス可。他人のデータは担当条件で構造的に見えない）。
 *
 * 集計仕様は docs/api/02_ダッシュボード_APIエンドポイント一覧.md に厳密準拠する。
 */
class DashboardController extends Controller
{
    /**
     * 近日アクション予定の表示上限。
     * ダッシュボードは概況把握用（glanceable）のため最も緊急な上位数件に絞り、
     * 全件は「進捗管理を見る →」（/pipelines）へ誘導する（WF_02 も5行想定）。
     */
    private const UPCOMING_ACTIONS_LIMIT = 5;

    public function index(Request $request): Response
    {
        $uid = auth()->id();

        // 進行中12種の値一覧（is_terminal=false）。SSOT は Pipeline::STATUSES（ハードコード禁止）。
        $inProgress = Pipeline::inProgressValues();

        return Inertia::render('Dashboard', [
            'kpi' => $this->buildKpi($uid, $inProgress),
            'pipeline_summary' => $this->buildPipelineSummary($uid, $inProgress),
            'upcoming_actions' => $this->buildUpcomingActions($uid, $inProgress, $request),
        ]);
    }

    /**
     * ① KPI サマリーバー（設計書の指示に従い個別 count クエリ・可読性優先）。
     *
     * @param  array<int, string>  $inProgress
     * @return array<string, int>
     */
    private function buildKpi(int $uid, array $inProgress): array
    {
        return [
            // 提案可能人材（自分担当＝main/sub）
            'proposable_engineer_count' => Engineer::query()
                ->where('status', 'proposable')
                ->assignedTo($uid)
                ->count(),
            // 提案可能人材（システム全体）
            'proposable_engineer_count_total' => Engineer::query()
                ->where('status', 'proposable')
                ->count(),
            // 稼働中案件（自分担当）
            'open_project_count' => Project::query()
                ->where('status', 'open')
                ->assignedTo($uid)
                ->count(),
            // 稼働中案件（システム全体）
            'open_project_count_total' => Project::query()
                ->where('status', 'open')
                ->count(),
            // 進行中カード総数（自分担当人材のパイプラインで進行中12種）
            'active_pipeline_count' => Pipeline::query()
                ->whereHas('engineer', fn (Builder $q) => $q->assignedTo($uid))
                ->whereIn('status', $inProgress)
                ->count(),
        ];
    }

    /**
     * ② パイプライン進捗サマリー。
     * 進行中12種すべてを固定順（Pipeline::STATUSES の定義順）で返す（0 件ステータスも含む）。
     *
     * @param  array<int, string>  $inProgress
     * @return array<int, array{status: string, status_label: string, group: string, count: int, percentage: int}>
     */
    private function buildPipelineSummary(int $uid, array $inProgress): array
    {
        // status => count の集計（該当ステータスのみ返る）。
        $counts = Pipeline::query()
            ->whereHas('engineer', fn (Builder $q) => $q->assignedTo($uid))
            ->whereIn('status', $inProgress)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();

        // 固定順で12種すべてを組み立てる（0 件も明示的に返す）。
        return array_map(function (string $status) use ($counts, $total) {
            $count = (int) ($counts[$status] ?? 0);

            return [
                'status' => $status,
                'status_label' => Pipeline::label($status),
                'group' => Pipeline::STATUSES[$status]['group'],
                'count' => $count,
                // ゼロ除算防止：総数 0 のときは 0%（設計書の明示指示）。
                'percentage' => $total > 0 ? (int) floor($count / $total * 100) : 0,
            ];
        }, $inProgress);
    }

    /**
     * ③ 近日アクション予定。
     * 自分担当人材のパイプラインで next_action_date <= 今日+7日（＝7日以内＋期限超過）を
     * 日付昇順で返す。engineer / project は Eager Load（N+1 回避）。
     * is_overdue（next_action_date < 今日）は Controller で判定する。
     * 終了（is_terminal=true）のパイプラインは「アクション予定」の対象外のため進行中12種に限定する。
     *
     * @param  array<int, string>  $inProgress
     * @return array<int, array<string, mixed>>
     */
    private function buildUpcomingActions(int $uid, array $inProgress, Request $request): array
    {
        $today = Carbon::today();
        $limit = $today->copy()->addDays(7);

        $pipelines = Pipeline::query()
            ->select(['id', 'engineer_id', 'project_id', 'status', 'next_action_date'])
            ->whereHas('engineer', fn (Builder $q) => $q->assignedTo($uid))
            // 終了ステータスは次アクションの対象外（進行中12種のみ）。
            ->whereIn('status', $inProgress)
            ->whereNotNull('next_action_date')
            ->whereDate('next_action_date', '<=', $limit)
            ->with([
                'engineer:id,name',
                'project:id,name',
            ])
            ->orderBy('next_action_date')
            // 昇順のため上位＝最も緊急（期限超過→本日→近日）の数件に絞る。
            ->limit(self::UPCOMING_ACTIONS_LIMIT)
            ->get();

        // is_overdue を Controller で判定して属性付与（fillable 非対象・直接代入）。
        $pipelines->each(function (Pipeline $pipeline) use ($today) {
            $pipeline->is_overdue = $pipeline->next_action_date->lt($today);
        });

        // Inertia の Props には data ラッパを付けず素の配列で渡す（既存 Controller の作法に統一）。
        return UpcomingActionResource::collection($pipelines)->toArray($request);
    }
}
