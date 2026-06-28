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