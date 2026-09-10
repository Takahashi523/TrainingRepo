import { cn } from '@/lib/utils';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { Children, useCallback, useLayoutEffect, useRef, useState } from 'react';

interface Props {
    /** 並べるタグ群（SkillTag など）。 */
    children: React.ReactNode;
    className?: string;
}

// タグ間・タグとボタンの間隔（gap-1.5 = 6px）。ボタン用の余白計算に使う。
const GAP_PX = 6;

/**
 * タグを既定で「1 行に収まる分だけ」表示し、あふれた分はトグルで全件展開する行コンテナ。
 *
 * 実幅で判定するため、不可視の計測用ミラー（全タグ＋トグルのプレースホルダを描画）で
 * 「タグ＋トグルが 1 行に収まる数」を求める。トグル幅を差し引いて数えるので、折りたたみ時は
 * 「収まる分のタグ＋トグル」が必ず同一行に収まる。表示側はそれをインラインで並べるため、
 * トグルは最後のタグの直後に置かれる（右端に飛んで余白が空かない）。展開時は全件＋トグルを並べる。
 * カード幅の変化には ResizeObserver で追従する。
 */
export default function CollapsibleTagRow({ children, className }: Props) {
    const items = Children.toArray(children);
    const mirrorRef = useRef<HTMLDivElement>(null);
    const [expanded, setExpanded] = useState(false);
    // 1 行に収まるタグ数。初期は全件（計測前は省略しない）。
    const [visibleCount, setVisibleCount] = useState(items.length);

    // ミラー DOM の実幅から「1 行に収まるタグ数」を測る。DOM のみを読むため依存なしで安定させる。
    const measure = useCallback(() => {
        const el = mirrorRef.current;
        if (!el) return;

        const nodes = Array.from(el.children) as HTMLElement[];
        // ミラーの末尾要素はトグルのプレースホルダ。それを除いたものがタグ。
        const tagNodes = nodes.slice(0, -1);
        const buttonNode = nodes[nodes.length - 1];
        if (tagNodes.length === 0) {
            setVisibleCount(0);
            return;
        }

        const firstTop = tagNodes[0].offsetTop;
        const firstRow = tagNodes.filter((n) => n.offsetTop === firstTop);

        // トグル不要（全タグが 1 行に収まる）なら省略しない。
        if (firstRow.length === tagNodes.length) {
            setVisibleCount(tagNodes.length);
            return;
        }

        // あふれる場合はトグル幅を確保し、「タグ＋トグル」が 1 行に収まる数を数える。
        const limit = el.clientWidth - GAP_PX - buttonNode.offsetWidth;
        let count = 0;
        for (const n of firstRow) {
            if (n.offsetLeft + n.offsetWidth <= limit) count++;
            else break;
        }
        setVisibleCount(count);
    }, []);

    // 内容（children）の変化に追従して毎レンダー後に再計測する。items.length だけに依存すると、
    // 件数が同じで各タグの内容/幅だけ変わったとき（別案件のスキルへ差し替え等）truncation が古い幅の
    // ままになる。measure は同値なら setState を no-op にするため、毎レンダー実行でも収束する。
    useLayoutEffect(() => {
        measure();
    });

    // コンテナ幅の変化には ResizeObserver で追従（マウント時に一度だけ観測）。
    useLayoutEffect(() => {
        const el = mirrorRef.current;
        if (!el) return;

        const observer = new ResizeObserver(() => measure());
        observer.observe(el);
        return () => observer.disconnect();
    }, [measure]);

    const overflow = visibleCount < items.length;
    const shownItems = expanded ? items : items.slice(0, visibleCount);

    // トグルの見た目（プレースホルダと実ボタンで共用）。
    const toggleClass =
        'inline-flex shrink-0 items-center gap-0.5 rounded border border-dashed border-border px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground';

    return (
        // w-full：flex コンテナの子として置かれても内容幅に縮まないようにする。
        // 幅が縮むと「1行に収まる数」を狭い幅で測ってしまい、余白があるのに畳まれる。
        <div className={cn('relative w-full', className)}>
            {/* 計測用ミラー：全タグ＋トグルのプレースホルダを不可視で描画し、収まる数を測る（表示側と同幅）。
                プレースホルダのラベルは最大幅（全件隠れる想定 = +items.length）にして、実ボタンが必ず収まるようにする。 */}
            <div
                ref={mirrorRef}
                aria-hidden
                className="pointer-events-none invisible absolute inset-x-0 top-0 flex flex-wrap gap-1.5"
            >
                {items}
                <span className={toggleClass}>
                    +{items.length}
                    <ChevronDown className="h-3 w-3" />
                </span>
            </div>

            {/* 表示側：折りたたみ時は収まる分＋トグル、展開時は全件＋トグルをインラインで並べる。 */}
            <div className="flex flex-wrap items-center gap-1.5">
                {shownItems}
                {overflow && (
                    <button
                        type="button"
                        // クリック可能なカード内でも使えるよう、伝播を止めて親のクリック（ドロワー展開）を防ぐ。
                        onClick={(e) => {
                            e.stopPropagation();
                            setExpanded((v) => !v);
                        }}
                        onKeyDown={(e) => e.stopPropagation()}
                        className={toggleClass}
                    >
                        {expanded ? (
                            <>
                                閉じる
                                <ChevronUp className="h-3 w-3" />
                            </>
                        ) : (
                            <>
                                +{items.length - visibleCount}
                                <ChevronDown className="h-3 w-3" />
                            </>
                        )}
                    </button>
                )}
            </div>
        </div>
    );
}
