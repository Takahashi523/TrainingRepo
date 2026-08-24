import { PaginationMeta, Phase, SortOption } from "@/types";
import { SavedSearchItem } from "@/types/savedSearch";

// 工程の形は人材・案件・マッチングで共通のため @/types に集約している（重複定義を避ける）。
// 既存の `@/types/engineer` 経由の import を壊さないよう、ここから再エクスポートして後方互換を保つ。
export type { Phase };

export interface WorkTypeOption {
    key: string;
    name: string;
}

export interface StatusOption {
    value: string;
    label: string;
}

export interface UserOption {
    id: number;
    name: string;
}

export interface Skill {
    label: string | null;
    detail: string | null;
}

export interface Engineer {
    id: number;
    name: string;
    name_kana: string;
    age: number | null;
    status: string;
    nearest_station: string | null;
    nearest_line: string | null;
    available_from: string | null;
    available_label: string;
    birth_date: string | null;
    users: {
        main: UserOption;
        sub: UserOption | null;
    };
    skills: Skill[];
    phases: Array<Phase & { has_experience: boolean }>;
    work_styles: WorkTypeOption[];
    has_negotiation_exp: boolean | null;
    appeal_note: string | null;
    desired_rate: number | null;
    remarks: string | null;
    ai_summary: string | null;
    ai_summary_generated_at: string | null;
    updated_at: string;
    /** 紐づくパイプライン件数。削除確認ダイアログの件数警告に使用（show() の loadCount 由来）。 */
    pipelines_count: number;
}

export type FieldSettings = {
    birth_date: { is_required: boolean };
    nearest_station: { is_required: boolean };
    nearest_line: { is_required: boolean };
    available_from: { is_required: boolean };
    skills: { is_required: boolean };
    proc_experience: { is_required: boolean };
    has_negotiation_exp: { is_required: boolean };
    appeal_note: { is_required: boolean };
    desired_rate: { is_required: boolean };
    work_styles: { is_required: boolean };
    remarks: { is_required: boolean };
};

// type alias (not interface) to satisfy PageProps<T extends Record<string, unknown>>
export type EngineerCreatePageProps = {
    fieldSettings: FieldSettings;
    phases: Phase[];
    work_styles: WorkTypeOption[];
    statuses: StatusOption[];
    users: UserOption[];
};

export type EngineerShowPageProps = {
    engineer: Engineer;
};

export type EngineerEditPageProps = {
    engineer: Engineer;
    fieldSettings: FieldSettings;
    phases: Phase[];
    work_styles: WorkTypeOption[];
    statuses: StatusOption[];
    users: UserOption[];
};

// 一覧画面で扱う軽量スキル型（detail を持たない）
export interface SkillListItem {
    label: string | null;
}

// 一覧用 Engineer（appeal_note / ai_summary / remarks / skills[].detail を含まない）
export interface EngineerListItem {
    id: number;
    name: string;
    age: number | null;
    nearest_station: string | null;
    nearest_line: string | null;
    status: string;
    available_from: string | null;
    available_label: string;
    users: {
        main: UserOption;
        sub: UserOption | null;
    };
    skills: SkillListItem[];
    phases: Array<Phase & { has_experience: boolean }>;
    work_styles: WorkTypeOption[];
    updated_at: string;
}

export interface EngineerFilters {
    status: string[];
    work_styles: string[];
    phases: string[];
    keyword: string;
    sort: "created_at" | "updated_at" | "available_from";
    order: "asc" | "desc";
    per_page: number;
    page: number;
}

export type EngineerSearchConditions = Omit<
    EngineerFilters,
    "page" | "per_page"
>;

// PaginationMeta / SortOption は一覧画面共通の型のため types/index.d.ts に集約している
// （バックエンドの EngineerController の SORT_OPTIONS を SSOT として props で受け取る点は変わらない）。

export type EngineerListPageProps = {
    engineers: {
        data: EngineerListItem[];
        meta: PaginationMeta;
    };
    filters: EngineerFilters;
    statusOptions: StatusOption[];
    workStyleOptions: WorkTypeOption[];
    phaseOptions: Phase[];
    sortOptions: SortOption[];
    savedSearches: SavedSearchItem<EngineerSearchConditions>[];
};
