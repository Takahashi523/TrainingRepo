import { useCallback, useRef, type RefObject } from 'react';

type UseScrollContainerResult = {
    /** `AuthenticatedLayout` の `mainRef` に渡す ref。スクロール境界の `<main>` が入る。 */
    scrollContainerRef: RefObject<HTMLElement>;
    /** スクロール境界を先頭へ戻す。ref が未接続なら no-op。 */
    scrollToTop: () => void;
};

/**
 * スクロール境界（`AuthenticatedLayout` の `<main>`）を掴み、先頭へ戻す手段を返すフック（issue #107）。
 *
 * 一覧のページ送りのように**コンテンツが総入れ替わりになる操作**で使う。
 * フィルタ変更・キーワードのデバウンスのように「同じ内容の一部だけが変わる」操作では
 * 呼ばない（スクロール位置を保つのが正しい挙動のため）。
 *
 * ```tsx
 * const { scrollContainerRef, scrollToTop } = useScrollContainer();
 *
 * const handlePageChange = (page: number) => visit({ page }, scrollToTop);
 *
 * return <AuthenticatedLayout mainRef={scrollContainerRef}>…</AuthenticatedLayout>;
 * ```
 *
 * ※ **ref をページ側で持ち、レイアウトへ渡す向きにしている。** React context で
 *   レイアウトから配る形にはできない。ページは `<AuthenticatedLayout>` を子として描画する側
 *   ＝ Provider の親であり、自分の子孫が提供する context は受け取れない（常に既定値になり、
 *   スクロールが無言で行われない no-op になる）。
 *
 * ※ 対象は `AuthenticatedLayout` の `<main>` だけ。進捗管理（進行中／カンバン）と
 *   マッチング結果は自前の全高コンテナで実スクロールしており（issue #82 の例外）、
 *   `mainRef` に渡しても実際のスクロール箱は動かない。どちらもページ送りを持たないため現状は問題ない。
 */
export function useScrollContainer(): UseScrollContainerResult {
    const scrollContainerRef = useRef<HTMLElement>(null);

    const scrollToTop = useCallback(() => {
        // behavior は既定（瞬時）。ページ送りは内容が総入れ替わりになるため、
        // スクロール中の中間状態（前ページと新ページが混ざって見える区間）に意味がない。
        scrollContainerRef.current?.scrollTo({ top: 0 });
    }, []);

    return { scrollContainerRef, scrollToTop };
}
