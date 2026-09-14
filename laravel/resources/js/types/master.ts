/**
 * マスタ管理画面（WF_12）の Props 型。
 * サーバの MasterUserResource / FormSettingResource と一致させること。
 */

export type UserRole = 'admin' | 'general';

export interface MasterUser {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    role_label: string;
    /** 最終ログイン日時（ISO8601）。未ログインは null */
    last_login_at: string | null;
    version: number;
}

export interface FormSetting {
    field_key: string;
    field_label: string;
    is_required: boolean;
    /** システム固定必須（true の場合トグル不可） */
    is_system_required: boolean;
}

export type FormType = 'engineer' | 'project';

export interface MasterPageProps {
    users: MasterUser[];
    form_settings: {
        engineer: FormSetting[];
        project: FormSetting[];
    };
    /** 許容メールドメイン（@なし・例: ["nexus.co.jp"]）。未設定時は空配列＝制限なし */
    allowed_email_domains: string[];
    [key: string]: unknown;
}
