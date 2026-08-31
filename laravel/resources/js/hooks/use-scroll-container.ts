import { useCallback, useRef, type RefObject } from 'react';

type UseScrollContainerResult = {
    /** `AuthenticatedLayout` の `mainRef` に渡す ref。スクロール境界の `<main>` が入る。 */
    scrollContainerRef: RefObject<HTMLElement>;
    /** スクロール境界を先頭へ戻す。ref が未接続なら no-op（開発時のみ警告を出す）。 */
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
        // ref の接続漏れ（mainRef を渡し忘れた画面）は、型でもビルドでも検出できず
        // 「ページ送りで何も起きない」だけの無言の失敗になる。実際にこの実装は一度その形で
        // 実機まで気づけなかった（reason.md 5）。JS のテスト基盤が無く契約を自動で守れないため、
        // MetaRow の data-shrinkable と同じ方針で開発時だけ警告する。
        if (import.meta.env.DEV && !scrollContainerRef.current) {
            console.warn(
                'useScrollContainer: scrollContainerRef が接続されていません。' +
                    '<AuthenticatedLayout mainRef={scrollContainerRef}> を渡してください（渡さないとページ送りで先頭に戻りません）。',
            );
        }

        // behavior: 'instant' を明示する。省略時の既定 'auto' は「瞬時」ではなく
        // 「対象要素の computed scroll-behavior に従う」の意味で、将来 <main> や :root に
        // scroll-smooth が入るとページ送りが無言でアニメーションに変わる（途中でホイール操作に
        // 割り込まれて中途半端な位置で止まりうる）。ページ送りは内容が総入れ替わりになるため、
        // スクロール中の中間状態（前ページと新ページが混ざって見える区間）に意味がない。
        //
        // left も明示する。<main> は overflow-y のみ指定＝ CSS 仕様上 overflow-x が auto に
        // 計算されるため、横スクロールが発生しうる要素である（現状は内側で横溢れを止めている）。
        scrollContainerRef.current?.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    }, []);

    return { scrollContainerRef, scrollToTop };
}
