import { TooltipBubble, useHoverTooltip } from '@/Components/Common/HoverTooltip';
import { emptyText } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';

/**
 * 担当者を表すイニシャルアバター（一覧カード用）。
 *
 * 一覧では同じ属性を何枚ものカードで見比べるため、幅が可変で長い氏名テキストは
 * 他の情報の比較を妨げる。氏名を先頭 MAX_CHARS 文字までに切り詰めたバッジに圧縮し、
 * 幅の上限をそろえる（日本語の氏名は4文字前後が多く、多くの場合は切り詰めずに収まる）。
 * 画像を持たないシステムでイニシャル1文字にすると誰か分からなくなるため、文字数で抑える方式を採る。
 *
 * 氏名はホバーのツールチップで即座に確認でき、支援技術には sr-only の
 * 「担当：氏名」を読ませる（アバターだけで役割・氏名が失われないようにする）。
 */
interface Props {
    /** 役割ラベル（担当／サブ）。ツールチップと読み上げに使う */
    role: string;
    /** 氏名。未割当は null */
    name: string | null;
    className?: string;
}

/** バッジに表示する最大文字数。日本人の氏名（姓名合わせて4文字前後）が収まる長さ。 */
const MAX_CHARS = 4;

export default function UserAvatar({ role, name, className }: Props) {
    const trimmed = name?.trim() ?? '';
    // 4文字を超える場合だけ末尾を省略記号にする（全文はツールチップと sr-only で担保）。
    const shortName =
        trimmed.length > MAX_CHARS ? `${trimmed.slice(0, MAX_CHARS)}…` : trimmed;
    // 未割当も語彙は SSOT（emptyValue）に合わせる。
    const label = `${role}：${name ?? emptyText('subUser')}`;
    // 頭文字だけでは誰か分からないため、遅延なし（delay 0）で即座に氏名を出す。
    const { ref, anchor, triggerProps } = useHoverTooltip<HTMLSpanElement>({ delay: 0 });

    return (
        <>
        <span
            ref={ref}
            {...triggerProps}
            className={cn(
                // サイズ・ウェイトは同じ行に並ぶ他のバッジ（面談回数・募集人数）に合わせる。
                'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-[11px]',
                name
                    ? // 割当あり：実線＋淡い塗り（確定した割当）。塗りは同じ行に並ぶ
                      // 面談回数・募集人数バッジと同じ bg-muted/50 にそろえる。
                      'border-border bg-muted/50 text-foreground'
                    : // 未割当：点線（尚可スキルや未確定条件と同じ「点線＝未確定」の扱い）
                      'border-dashed border-border bg-white text-muted-foreground',
                className,
            )}
        >
            <span className="sr-only">{label}</span>
            <span aria-hidden="true">{name ? shortName : emptyText('subUser')}</span>
        </span>
        {anchor && <TooltipBubble text={label} anchor={anchor} />}
        </>
    );
}
