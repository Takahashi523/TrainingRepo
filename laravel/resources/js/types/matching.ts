import { Engineer } from '@/types/engineer';

/** マッチング結果カード／ドロワーで表示する案件情報（TEXT は含まない）。 */
export interface MatchProject {
    id: number;
    name: string;
    client_name: string | null;
    commercial_flow: string | null;
    headcount: number | null;
    rate_min: number | null;
    rate_max: number | null;
    rate_note: string | null;
    work_style: string | null;
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
}

/**
 * 結果0件のときの理由。空状態の文言・アイコンを出し分ける（結果ありのときは null）。
 * サーバー MatchingController の EMPTY_* 定数と一致させること。
 *  - no_match     : 候補案件なし / スコア0件
 *  - engine_error : エンジン通信失敗（flash.error も併発）
 *  - unavailable  : マッチはあったが対象案件が削除・非掲出で全滅
 */
export type MatchingEmptyReason = 'no_match' | 'engine_error' | 'unavailable';

export type MatchingShowPageProps = {
    engineer: Engineer;
    results: MatchResult[];
    emptyReason: MatchingEmptyReason | null;
};
