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

export type MatchingShowPageProps = {
    engineer: Engineer;
    results: MatchResult[];
};
