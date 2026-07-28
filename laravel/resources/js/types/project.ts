export interface Phase {
    key: string;
    name: string;
}

export interface WorkStyleOption {
    key: string;
    name: string;
}

export interface StatusOption {
    value: string;
    label: string;
}

export interface CommercialFlowOption {
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

// GET /projects/{id}・/projects/{id}/edit の project 詳細形状（ProjectResource と1対1対応）
export interface Project {
    id: number;
    name: string;
    client_name: string | null;
    status: string;
    commercial_flow: string | null;
    headcount: number | null;
    start_date: string | null;
    start_label: string;
    rate_min: number | null;
    rate_max: number | null;
    rate_note: string | null;
    work_style: string | null;
    work_location_line: string | null;
    work_location_station: string | null;
    interview_count: number | null;
    negotiation_required: boolean | null;
    description: string | null;
    work_env: string | null;
    billing_range: string | null;
    remarks: string | null;
    users: {
        main: UserOption;
        sub: UserOption | null;
    };
    required_skills: Skill[];
    preferred_skills: Skill[];
    phases: Array<Phase & { is_target: boolean }>;
    updated_at: string;
}

export type FieldSetting = {
    is_required: boolean;
};

export type FieldSettings = {
    client_name: FieldSetting;
    headcount: FieldSetting;
    start_date: FieldSetting;
    rate: FieldSetting;
    commercial_flow: FieldSetting;
    work_style: FieldSetting;
    work_location: FieldSetting;
    interview_count: FieldSetting;
    required_skills: FieldSetting;
    preferred_skills: FieldSetting;
    proc_experience: FieldSetting;
    negotiation_required: FieldSetting;
    description: FieldSetting;
    work_env: FieldSetting;
    billing_range: FieldSetting;
    remarks: FieldSetting;
};

export type SkillPair = {
    id?: string;
    label: string;
    detail: string | null;
};

export type ProjectFormData = {
    name: string;
    client_name: string;
    headcount: string;
    start_date: string;
    rate_is_negotiable: boolean;
    rate_min: string;
    rate_max: string;
    rate_note: string;
    commercial_flow: string;
    work_style: string;
    interview_count: string;
    work_location_line: string;
    work_location_station: string;
    required_skills: SkillPair[];
    preferred_skills: SkillPair[];
    proc_requirements: boolean;
    proc_basic_design: boolean;
    proc_detail_design: boolean;
    proc_development: boolean;
    proc_testing: boolean;
    proc_maintenance: boolean;
    negotiation_required: boolean;
    description: string;
    work_env: string;
    status: string;
    main_user_id: string;
    sub_user_id: string;
    billing_range: string;
    remarks: string;
};

// type alias (not interface) to satisfy PageProps<T extends Record<string, unknown>>
export type ProjectCreatePageProps = {
    fieldSettings: FieldSettings;
    phases: Phase[];
    work_styles: WorkStyleOption[];
    commercial_flows: CommercialFlowOption[];
    statuses: StatusOption[];
    users: UserOption[];
    // 登録者を主担当のデフォルト選択にするために使用（ProjectController@commonFormProps）
    authUserId: number;
};

export type ProjectShowPageProps = {
    project: Project;
};

/**
 * 一覧画面（暫定実装）。バックエンドが id / name のみ返すため、
 * Project 詳細型のサブセットとして定義する（検索・絞り込み・ページネーションは別途実装予定）。
 */
export type ProjectIndexPageProps = {
    projects: Pick<Project, "id" | "name">[];
};

export const PROJECT_STATUS_LABELS: Record<string, string> = {
    open: "募集中",
    pending: "ペンディング",
    closed: "終了",
};

export const COMMERCIAL_FLOW_LABELS: Record<string, string> = {
    prime: "プライム",
    secondary: "2次",
    tertiary: "3次",
    other: "その他",
};

export const WORK_STYLE_LABELS: Record<string, string> = {
    onsite: "常駐",
    hybrid: "一部リモート可",
    remote: "フルリモート",
};

export type ProjectEditPageProps = {
    project: Project;
    fieldSettings: FieldSettings;
    phases: Phase[];
    work_styles: WorkStyleOption[];
    commercial_flows: CommercialFlowOption[];
    statuses: StatusOption[];
    users: UserOption[];
};
