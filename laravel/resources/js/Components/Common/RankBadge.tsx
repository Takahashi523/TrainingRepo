import { emptyText } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';

/**
 * マッチングランク（A≥最良 〜 D＝低マッチ）別のバッジ配色。
 * 業界標準のレターグレード配色（緑→赤グラデーション）を、既存 StatusBadge と同じ
 * 「bg-*-50 地色＋色付きボーダー＋text-*-700」規約に揃え、プロダクトの青系ニュートラルに馴染ませる。
 * ランク A は StatusBadge「提案可」、C は「面談中」と同一トークンを用い、配色語彙を統一する。
 * カンバン・ドロワーに加えマッチング結果画面でも流用するため、色定義を export する。
 */
export const RANK_STYLES: Record<string, string> = {
    A: 'border border-green-600 text-green-700 bg-green-50',
    B: 'border border-lime-600 text-lime-700 bg-lime-50',
    C: 'border border-amber-500 text-amber-700 bg-amber-50',
    D: 'border border-rose-500 text-rose-700 bg-rose-50',
};

/** 想定外のランク値・フォールバック用の中立グレー（StatusBadge のデフォルトと同一） */
export const RANK_FALLBACK_STYLE = 'border border-gray-400 text-gray-600 bg-gray-50';

/**
 * スコアバー等の「塗りつぶし」用ランク配色。バッジは淡色地（bg-*-50）だが、
 * バーは視認性のため実色（RANK_STYLES のボーダー色と同一トーン）で塗る。
 * ランク未算出（null）や想定外値は中立グレーにフォールバックする。
 */
export const RANK_BAR_STYLES: Record<string, string> = {
    A: 'bg-green-600',
    B: 'bg-lime-600',
    C: 'bg-amber-500',
    D: 'bg-rose-500',
};

/** ランク未算出・想定外値のバー色（中立グレー） */
export const RANK_BAR_FALLBACK_STYLE = 'bg-muted-foreground';

interface Props {
    /** マッチングランク（A/B/C/D）。null は「未算出」を表示（記号は使わない：docs/UI表示規約.md §3） */
    rank: string | null;
    /** 追加クラス（フォントサイズなど呼び出し側の調整用） */
    className?: string;
}

/**
 * マッチングランクバッジ。ランクにより色を変える（WF_10 の rank-badge 系スタイル準拠）。
 * カンバンカード・詳細ドロワー・マッチング結果画面で共通利用する。
 */
export default function RankBadge({ rank, className }: Props) {
    if (!rank) {
        return (
            <span
                className={cn(
                    'inline-block rounded-sm border border-dashed border-border px-1.5 py-px text-[10px] font-normal text-muted-foreground',
                    className,
                )}
            >
                {emptyText('matchRank')}
            </span>
        );
    }

    const style = RANK_STYLES[rank] ?? RANK_FALLBACK_STYLE;

    return (
        <span className={cn('inline-block rounded-sm px-1.5 py-px text-[10px] font-bold', style, className)}>
            {rank}
        </span>
    );
}
