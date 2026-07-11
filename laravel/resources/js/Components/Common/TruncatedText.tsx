import { cn } from '@/lib/utils';
import { ElementType, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

interface Props {
    /** 表示するテキスト。CSS で省略された場合のみ、この全文をツールチップに出す */
    text: string;
    /** レンダリングする要素。デフォルトは <span>。<p> / <div> などに差し替え可能 */
    as?: ElementType;
    /** truncate に追加するクラス（フォントサイズ・色など） */
    className?: string;
    /** ツールチップ表示までの遅延(ms)。カード間をマウスが横切る際のちらつき防止に既定150ms */
    delay?: number;
}

/**
 * ツールチップ本体。
 *
 * Portal で document.body 直下に描画することで、カンバン列など overflow:hidden /
 * スクロール領域内でもクリップされずに全文を表示できる。描画後に自身のサイズを測り、
 * 画面の右端・下端からはみ出す場合は位置を補正する。
 */
function Tooltip({ text, anchor }: { text: string; anchor: DOMRect }) {
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
            className="pointer-events-none fixed z-50 max-w-xs rounded bg-white px-2 py-1 text-xs break-words text-foreground shadow-md"
            style={{ left: pos.left, top: pos.top }}
        >
            {text}
        </div>,
        document.body,
    );
}

/**
 * 1行省略（truncate）テキスト。
 *
 * 実際に省略が発生している（scrollWidth > clientWidth）ときだけ、ホバーで全文の
 * カスタムツールチップを表示する。省略されていない場合は何も出さない。
 * ネイティブ title の遅延・スタイル固定の制約を避けるため独自ツールチップを採用している。
 */
export default function TruncatedText({ text, as: Tag = 'span', className, delay = 150 }: Props) {
    // scrollWidth / clientWidth を測るため DOM 要素への参照を保持する
    const ref = useRef<HTMLElement>(null);
    const [isTruncated, setIsTruncated] = useState(false);
    // ツールチップ表示中はアンカー要素の矩形を保持する（null なら非表示）
    const [anchor, setAnchor] = useState<DOMRect | null>(null);
    const timer = useRef<number | null>(null);

    // 実際に省略が発生しているか判定。カード幅の変化（リサイズ）にも追従する
    useEffect(() => {
        const el = ref.current;
        if (!el) return;

        const check = () => setIsTruncated(el.scrollWidth > el.clientWidth);
        check();

        const observer = new ResizeObserver(check);
        observer.observe(el);
        return () => observer.disconnect();
    }, [text]);

    // アンマウント時に遅延タイマーを後始末する
    useEffect(() => () => {
        if (timer.current) window.clearTimeout(timer.current);
    }, []);

    // ツールチップ表示中にスクロールされると位置がずれるため閉じる
    useEffect(() => {
        if (!anchor) return;
        const close = () => setAnchor(null);
        window.addEventListener('scroll', close, true);
        return () => window.removeEventListener('scroll', close, true);
    }, [anchor]);

    const show = () => {
        if (!isTruncated) return;
        timer.current = window.setTimeout(() => {
            const el = ref.current;
            if (el) setAnchor(el.getBoundingClientRect());
        }, delay);
    };

    const hide = () => {
        if (timer.current) window.clearTimeout(timer.current);
        setAnchor(null);
    };

    return (
        <>
            <Tag
                ref={ref}
                className={cn('truncate', className)}
                onMouseEnter={show}
                onMouseLeave={hide}
            >
                {text}
            </Tag>
            {anchor && <Tooltip text={text} anchor={anchor} />}
        </>
    );
}
