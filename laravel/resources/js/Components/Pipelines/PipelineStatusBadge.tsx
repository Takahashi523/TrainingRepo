import { cn } from '@/lib/utils';

interface Props {
    /** 表示名（status_label） */
    label: string;
    className?: string;
}

/**
 * パイプラインのステータスバッジ（進行中ドロワー・完了済みテーブル共通）。
 * 進行中12種＋終了4種は色分けせず、輪郭付きの中立ピルで統一表示する
 * （色はランク（RankBadge）に一任し、ステータスは無彩色で示す方針。ランク色との競合を避ける）。
 */
export default function PipelineStatusBadge({ label, className }: Props) {
    return (
        <span
            className={cn(
                'inline-block rounded-full border border-foreground px-2.5 py-0.5 text-[11px] font-bold text-foreground',
                className,
            )}
        >
            {label}
        </span>
    );
}
