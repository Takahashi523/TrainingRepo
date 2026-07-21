// 進捗管理（パイプライン）機能のフロント型定義。
// サーバ（PipelineController / 各 Resource）が返す Props を忠実に型付けする。
// any は使用しない。Props 形状は docs/api/06_進捗管理_APIエンドポイント一覧.md に一致させる。

/** 進行中12種 + 終了4種のステータス値（DB値・SSOT は Pipeline::STATUSES） */
export type PipelineStatus =
    // 進行中12種
    | 'proposed'
    | 'applied_by_candidate'
    | 'applying'
    | 'first_scheduling'
    | 'first_waiting'
    | 'first_result_waiting'
    | 'final_scheduling'
    | 'final_waiting'
    | 'final_result_waiting'
    | 'offered'
    | 'assign_waiting'
    | 'contracted'
    // 終了4種
    | 'rejected'
    | 'closed'
    | 'assign_declined'
    | 'declined';

/** カンバンの4グループキー（進行中の分類） */
export type KanbanGroupKey =
    | 'entry'
    | 'first_interview'
    | 'final_interview'
    | 'offer';

/** ステータス選択肢のグループキー（カンバン4種 + 終了グループ completed） */
export type StatusGroupKey = KanbanGroupKey | 'completed';

/** 担当営業などのユーザー選択肢 */
export interface UserOption {
    id: number;
    name: string;
}

/** マッチングランク選択肢（A〜D 固定） */
export interface RankOption {
    value: string;
    label: string;
    /** ランクに対応するスコア範囲（例: "80点以上"）。ドロップダウンで併記する */
    range: string;
}

/**
 * ステータス選択肢。
 * - index（フィルタ用 statuses）：value / label / group（is_terminal なし）
 * - completed（フィルタ用 statuses）：value / label のみ
 * - show（ドロワー用 statusOptions）：value / label / group / is_terminal
 * 3 形状を単一型で扱うため group / is_terminal は optional。
 */
export interface StatusOption {
    value: PipelineStatus;
    label: string;
    group?: StatusGroupKey;
    is_terminal?: boolean;
}

/** カード・詳細に共通する人材参照（main_user は null 許容） */
export interface PipelineEngineerRef {
    id: number;
    name: string;
    main_user: UserOption | null;
}

/** カード・詳細に共通する案件参照 */
export interface PipelineProjectRef {
    id: number;
    name: string;
    /** 顧客名。projects.client_name は nullable のため null を許容する */
    client_name: string | null;
}

/** カンバンカード（TEXT カラムは含まない） */
export interface PipelineCard {
    id: number;
    status: PipelineStatus;
    status_label: string;
    match_score: number | null;
    match_rank: string | null;
    next_action_date: string | null;
    updated_at: string;
    engineer: PipelineEngineerRef;
    project: PipelineProjectRef;
}

/** カンバンの1グループ列 */
export interface KanbanColumn {
    key: KanbanGroupKey;
    label: string;
    count: number;
    cards: PipelineCard[];
}

/** ドロワー詳細（TEXT カラムを含む全項目） */
export interface PipelineDetail {
    id: number;
    status: PipelineStatus;
    status_label: string;
    match_score: number | null;
    match_rank: string | null;
    ai_score_reason: string | null;
    ai_comment: string | null;
    ai_missing: string | null;
    client_comment: string | null;
    ng_reason: string | null;
    next_action_date: string | null;
    updated_at: string;
    engineer: PipelineEngineerRef;
    project: PipelineProjectRef;
}

/** 完了済みテーブル行（TEXT のうち ng_reason のみ含む） */
export interface CompletedRow {
    id: number;
    status: PipelineStatus;
    status_label: string;
    ng_reason: string | null;
    ended_at: string | null;
    engineer: PipelineEngineerRef;
    project: PipelineProjectRef;
}

/**
 * 進行中タブの適用中フィルタ（URL クエリ復元用）。
 * user_id: null＝自分の担当（デフォルト）/ 'all'＝全員 / number＝個別指定
 */
export interface ActiveFilters {
    keyword: string;
    user_id: number | 'all' | null;
    rank: string[];
    status: string[];
    sort: string;
    order: string;
}

/** 完了済みタブの適用中フィルタ（URL クエリ復元用） */
export interface CompletedFilters {
    keyword: string;
    status: string[];
    user_id: number | null;
    ended_from: string | null;
    ended_to: string | null;
    sort: string;
    order: string;
}

/** Laravel ページネーション meta（完了済みタブ） */
export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/** ページネーション付きコレクション */
export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
}

/** GET /pipelines（進行中タブ）Props。show 部分リロードで selectedPipeline / statusOptions が付与される */
/**
 * ソート選択肢（sort×order のペア＋表示ラベル）。
 * バックエンド（PipelineController の SORT_OPTIONS_*）を SSOT として props で受け取り、
 * UI の選択肢と許可された組み合わせを常に一致させる。
 */
export interface SortOption {
    sort: string;
    order: string;
    label: string;
}

export type PipelineIndexPageProps = {
    columns: KanbanColumn[];
    filters: ActiveFilters;
    users: UserOption[];
    ranks: RankOption[];
    statuses: StatusOption[];
    sortOptions: SortOption[];
    selectedPipeline?: PipelineDetail | null;
    statusOptions?: StatusOption[] | null;
};

/** GET /pipelines/completed（完了済みタブ）Props */
export type PipelineCompletedPageProps = {
    pipelines: Paginated<CompletedRow>;
    filters: CompletedFilters;
    users: UserOption[];
    statuses: StatusOption[];
    sortOptions: SortOption[];
};
