import { TooltipBubble, useHoverTooltip } from '@/Components/Common/HoverTooltip';
import { cn } from '@/lib/utils';
import { ElementType, useEffect, useState } from 'react';

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
 * 1行省略（truncate）テキスト。
 *
 * 実際に省略が発生している（scrollWidth > clientWidth）ときだけ、ホバーで全文の
 * カスタムツールチップを表示する。省略されていない場合は何も出さない。
 * ネイティブ title の遅延・スタイル固定の制約を避けるため独自ツールチップ（HoverTooltip）を使う。
 */
export default function TruncatedText({ text, as: Tag = 'span', className, delay = 150 }: Props) {
    const [isTruncated, setIsTruncated] = useState(false);
    // 省略が起きているときだけツールチップを出す（enabled）。ref は scrollWidth の計測にも使う。
    const { ref, anchor, triggerProps } = useHoverTooltip<HTMLElement>({
        delay,
        enabled: isTruncated,
    });

    // 実際に省略が発生しているか判定。カード幅の変化（リサイズ）にも追従する
    useEffect(() => {
        const el = ref.current;
        if (!el) return;

        const check = () => setIsTruncated(el.scrollWidth > el.clientWidth);
        check();

        const observer = new ResizeObserver(check);
        observer.observe(el);
        return () => observer.disconnect();
    }, [text, ref]);

    return (
        <>
            <Tag ref={ref} className={cn('truncate', className)} {...triggerProps}>
                {text}
            </Tag>
            {anchor && <TooltipBubble text={text} anchor={anchor} />}
        </>
    );
}
