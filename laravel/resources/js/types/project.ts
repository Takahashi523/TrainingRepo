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
