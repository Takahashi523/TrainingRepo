// ダッシュボード（WF_02 / 画面02）のフロント型定義。
// サーバ（DashboardController@index / UpcomingActionResource）が返す Props を忠実に型付けする。
// any は使用しない。Props 形状は docs/api/02_ダッシュボード_APIエンドポイント一覧.md に一致させる。

import { PipelineStatus } from './pipeline';

/** ① KPI サマリーバー */
export interface DashboardKpi {
    /** 提案可能人材（自分担当＝main/sub） */
    proposable_engineer_count: number;
    /** 提案可能人材（システム全体） */
    proposable_engineer_count_total: number;
    /** 稼働中案件（自分担当） */
    open_project_count: number;
    /** 稼働中案件（システム全体） */
    open_project_count_total: number;
    /** 進行中カード総数（自分担当・進行中12種） */
    active_pipeline_count: number;
}

/** ② パイプライン進捗サマリー1行（進行中12種すべてを固定順で返す） */
export interface PipelineSummaryRow {
    status: PipelineStatus;
    status_label: string;
    /** カンバングループ（entry / first_interview / final_interview / offer） */
    group: string;
    count: number;
    /** 進行中カード総数に対する割合（%・切り捨て）。総数0のときは0 */
    percentage: number;
}

/** ③ 近日アクション予定1行 */
export interface UpcomingAction {
    id: number;
    /** YYYY-MM-DD */
    next_action_date: string;
    /** true = 期限超過（フロントは日付を赤字表示） */
    is_overdue: boolean;
    status: PipelineStatus;
    status_label: string;
    engineer: {
        id: number;
        name: string;
    };
    project: {
        id: number;
        name: string;
    };
}

/** GET /dashboard の Props */
export interface DashboardProps {
    kpi: DashboardKpi;
    pipeline_summary: PipelineSummaryRow[];
    upcoming_actions: UpcomingAction[];
}
