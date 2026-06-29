export interface Phase {
    key: string;
    name: string;
}

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
}

export type FieldSettings = {
    birth_date:          { is_required: boolean };
    nearest_station:     { is_required: boolean };
    nearest_line:        { is_required: boolean };
    available_from:      { is_required: boolean };
    skills:              { is_required: boolean };
    proc_experience:     { is_required: boolean };
    has_negotiation_exp: { is_required: boolean };
    appeal_note:         { is_required: boolean };
    desired_rate:        { is_required: boolean };
    work_styles:         { is_required: boolean };
    remarks:             { is_required: boolean };
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
    sort: 'created_at' | 'updated_at' | 'available_from';
    order: 'asc' | 'desc';
    per_page: number;
    page: number;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export type EngineerListPageProps = {
    engineers: {
        data: EngineerListItem[];
        meta: PaginationMeta;
    };
    filters: EngineerFilters;
    statusOptions: StatusOption[];
    workStyleOptions: WorkTypeOption[];
    phaseOptions: Phase[];
};