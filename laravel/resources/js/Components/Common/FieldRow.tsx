import { cn } from '@/lib/utils';

/**
 * 項目ラベル＋値の行（表示規約の「型1：ラベル列」）。
 *
 * 一覧カードのローカル `Section`（人材・案件で別々に定義）と、詳細画面のローカル `DetailRow`
 * （人材・案件で別々に定義）が同じ役割で4重に存在していたため1つに統合する（#57）。
 * 密度だけが違いなので `density` で切り替える。
 *
 * 値側の欠損表示は項目名を含めない（例：「単価」ラベルの右は「未定」）。
 * 項目名はラベルが担うため二重に出さない、という規約による（docs/UI表示規約.md）。
 */
interface Props {
    label: string;
    /**
     * card   … 一覧カードの条件ブロック（ラベル幅 w-14・10px）
     * detail … 詳細画面の項目表（ラベル幅 w-44・12px・行間の区切り線あり）
     */
    density?: 'card' | 'detail';
    /**
     * start（既定） … 縦積みの行。値が複数行になってもラベルを上端にそろえる
     * center        … 複数の FieldRow を横一列に並べる場合。値ごとに高さが違っても
     *                 縦ズレして見えないよう中央でそろえ、行間マージンも持たない
     */
    align?: 'start' | 'center';
    children: React.ReactNode;
    className?: string;
}

export default function FieldRow({
    label,
    density = 'card',
    align = 'start',
    children,
    className,
}: Props) {
    const isDetail = density === 'detail';
    const isCentered = align === 'center';

    return (
        <div
            className={cn(
                'flex min-w-0',
                isCentered ? 'items-center' : 'items-start',
                isDetail
                    ? 'border-b border-border/50 px-4 py-2.5 last:border-b-0'
                    : cn('gap-2', !isCentered && 'mb-1.5'),
                className,
            )}
        >
            <span
                className={cn(
                    'shrink-0 text-muted-foreground',
                    // 上端そろえのときだけ、小さいラベルの視覚位置を値の1行目に合わせる。
                    !isCentered && 'pt-0.5',
                    isDetail ? 'w-44 pr-4 text-xs font-semibold' : 'w-14 text-[10px] font-bold',
                )}
            >
                {label}
            </span>
            <div
                className={cn(
                    'min-w-0 flex-1',
                    // 詳細画面は長い自由文（備考・作業環境）を折り返す前提の体裁を維持する。
                    isDetail && 'break-words text-sm text-foreground',
                    // 一覧カードは値がタグ（枠線＋padding で約22px）のときとテキスト（約16px）のときで
                    // 行の高さが変わり、カードを並べたときにラベル位置がそろわない。最低高さをタグに合わせる。
                    !isDetail && 'flex min-h-[22px] flex-wrap items-center',
                )}
            >
                {children}
            </div>
        </div>
    );
}
