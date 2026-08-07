export interface User {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'general';
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
};

/**
 * 一覧画面共通のページネーションメタ情報（人材・案件・パイプライン共通）。
 * 各ドメインのtypes/*.tsやComponents/Common/Pagination.tsxはここを参照し、
 * 個別に同じ形を再定義しないこと。
 */
export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

/**
 * 一覧画面共通のソート選択肢（sort×orderのペア＋表示ラベル）。
 * 各ドメインのtypes/*.tsやComponents/Common/SortSelect.tsxはここを参照し、
 * 個別に同じ形を再定義しないこと。
 */
export interface SortOption {
    sort: string;
    order: string;
    label: string;
}
