import { Engineer } from '@/types/engineer';

/** マッチング結果カード／ドロワーで表示する案件情報（TEXT は含まない）。 */
export interface MatchProject {
    id: number;
    name: string;
    client_name: string | null;
    /** 商流の表示ラベル（サーバー解決。未設定は null）。 */
    commercial_flow_label: string | null;
    headcount: number | null;
    rate_min: number | null;
    rate_max: number | null;
    rate_note: string | null;
    /** 勤務形態の表示ラベル（サーバー解決。未設定は null）。 */
    work_style_label: string | null;
    /** 掲載状態の表示ラベル（募集中 / 終了 / ペンディング。サーバー解決）。 */
    status_label: string;
    start_date: string | null;
    start_label: string;
    required_skills: Array<{ label: string }>;
    preferred_skills: Array<{ label: string }>;
    phases: Array<{ key: string; name: string; is_target: boolean }>;
}

/** マッチング結果1件（results[] の要素）。スコア5点セット＋案件＋追加状態。 */
export interface MatchResult {
    match_score: number;
    match_rank: string;
    ai_score_reason: string | null;
    ai_comment: string | null;
    ai_missing: string | null;
    project: MatchProject;
    is_in_pipeline: boolean;
    /** 募集中(open)か。false（掲載停止 closed/pending）なら「掲載停止」表示＋追加無効化。 */
    is_available: boolean;
    /** パイプライン上限（5件）到達済みか。true なら「上限到達」表示＋追加無効化。 */
    is_project_full: boolean;
}

/**
 * 結果0件のときの理由。空状態の文言・アイコンを出し分ける（結果ありのときは null）。
 * サーバー MatchingController の EMPTY_* 定数と一致させること。
 *  - no_match     : 候補案件なし / スコア0件
 *  - engine_error : エンジン通信失敗（flash.error も併発）
 *  - unavailable  : マッチはあったが対象案件が全てハード削除で全滅（掲載停止は残して無効表示するため該当しない）
 */
export type MatchingEmptyReason = 'no_match' | 'engine_error' | 'unavailable';

export type MatchingShowPageProps = {
    engineer: Engineer;
    results: MatchResult[];
    emptyReason: MatchingEmptyReason | null;
};
