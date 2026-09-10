import { RefObject, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * ホバー用ツールチップの共通実装。
 *
 * 元は TruncatedText 内に閉じていたが、担当者アバター（UserAvatar）でも同じ体裁の
 * ツールチップが必要になったため切り出した。ネイティブ `title` は表示までの遅延が
 * 変更できず見た目も OS 依存のため使わない。
 */

/**
 * ツールチップ本体。
 *
 * Portal で document.body 直下に描画することで、カンバン列など overflow:hidden /
 * スクロール領域内でもクリップされずに表示できる。描画後に自身のサイズを測り、
 * 画面の右端・下端からはみ出す場合は位置を補正する。
 */
export function TooltipBubble({ text, anchor }: { text: string; anchor: DOMRect }) {
    const ref = useRef<HTMLDivElement>(null);
    // 初期位置はアンカー要素の左下。描画後に useLayoutEffect で補正する
    const [pos, setPos] = useState({ left: anchor.left, top: anchor.bottom + 4 });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;

        const { width, height } = el.getBoundingClientRect();
        const margin = 8; // 画面端との最小マージン
        let left = anchor.left;
        let top = anchor.bottom + 4;

        // 右端はみ出しを防ぐ
        if (left + width > window.innerWidth - margin) left = window.innerWidth - margin - width;
        if (left < margin) left = margin;
        // 下端に収まらなければアンカーの上側に表示する
        if (top + height > window.innerHeight - margin) top = anchor.top - height - 4;

        setPos({ left, top });
    }, [anchor]);

    return createPortal(
        <div
            ref={ref}
            role="tooltip"
            className="pointer-events-none fixed z-50 max-w-xs break-words rounded bg-white px-2 py-1 text-xs text-foreground shadow-md"
            style={{ left: pos.left, top: pos.top }}
        >
            {text}
        </div>,
        document.body,
    );
}

interface Options {
    /**
     * 表示までの遅延(ms)。
     * 一覧のテキスト省略のように「マウスが横切るだけ」で出したくない場面は既定の 150ms、
     * アバターのように内容がホバーでしか分からない要素は 0（即時）を渡す。
     */
    delay?: number;
    /** false の間はホバーしても表示しない（例：省略が発生していないテキスト） */
    enabled?: boolean;
}

/**
 * ホバー（およびフォーカス）でツールチップを出すためのフック。
 * 呼び出し側は `ref` と `triggerProps` を自分の要素に渡し、`anchor` があるとき
 * `<TooltipBubble />` を描画する。ラッパー要素を挟まないのでレイアウトに影響しない。
 */
export function useHoverTooltip<T extends HTMLElement = HTMLElement>({
    delay = 150,
    enabled = true,
}: Options = {}): {
    ref: RefObject<T>;
    anchor: DOMRect | null;
    triggerProps: {
        onMouseEnter: () => void;
        onMouseLeave: () => void;
        onFocus: () => void;
        onBlur: () => void;
    };
} {
    const ref = useRef<T>(null);
    // 表示中はアンカー要素の矩形を保持する（null なら非表示）
    const [anchor, setAnchor] = useState<DOMRect | null>(null);
    const timer = useRef<number | null>(null);

    // アンマウント時に遅延タイマーを後始末する
    useEffect(
        () => () => {
            if (timer.current) window.clearTimeout(timer.current);
        },
        [],
    );

    // 表示中にスクロールされると位置がずれるため閉じる
    useEffect(() => {
        if (!anchor) return;
        const close = () => setAnchor(null);
        window.addEventListener('scroll', close, true);
        return () => window.removeEventListener('scroll', close, true);
    }, [anchor]);

    const open = () => {
        const el = ref.current;
        if (el) setAnchor(el.getBoundingClientRect());
    };

    const show = () => {
        if (!enabled) return;
        // 遅延 0 のときは setTimeout を挟まず即座に出す。
        if (delay <= 0) {
            open();
            return;
        }
        timer.current = window.setTimeout(open, delay);
    };

    const hide = () => {
        if (timer.current) window.clearTimeout(timer.current);
        setAnchor(null);
    };

    return {
        ref,
        anchor,
        triggerProps: { onMouseEnter: show, onMouseLeave: hide, onFocus: show, onBlur: hide },
    };
}
