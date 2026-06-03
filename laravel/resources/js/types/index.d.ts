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
