// conditions の各フィールドが取りうる値。router.post/router.delete に渡す際、
// Inertia の型（FormDataConvertible）を満たすことをコンパイラに保証するための制約。
// EngineerSearchConditions / ProjectSearchConditions は全フィールドがこの範囲に収まる。
export type ConditionValue = string | number | string[] | number[];

export interface SavedSearchItem<TConditions extends Record<string, ConditionValue>> {
    id: number;
    name: string;
    conditions: TConditions;
}
