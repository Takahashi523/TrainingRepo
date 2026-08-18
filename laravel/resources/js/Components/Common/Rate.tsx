import EmptyValue from '@/Components/Common/EmptyValue';
import { cn } from '@/lib/utils';

/**
 * 単価（月額）の表示（表示規約の「原子」）。
 *
 * 同じ単価の表示が3実装に分かれていたため統合する（#57）。
 *  - MatchCard.formatRate      … min/max 片側にも対応。最後は '—' を返し呼び出し側で読み替えていた
 *  - ProjectCard.RateValue     … 両方揃わないと note か '—'（片側レンジを表示できないバグ）
 *  - Pages/Projects/Show       … 非太字プレーン・「〜」前後に全角空白
 *
 * 統合後は「片側レンジも表示する」「数値と単位で強調を分ける」「欠損は未定トークン」に揃える。
 */
interface Props {
    min: number | null;
    max: number | null;
    note: string | null;
    /**
     * strong … 数値=太字濃色 / 単位=細字淡色（一覧カード・詳細画面。WF_06 / PR #53 の決定）
     * plain  … 淡色一律（圧縮メタ行。行全体が muted なので単価だけ浮かせない）
     */
    variant?: 'strong' | 'plain';
    /** 欠損時に項目名を含めるか（ラベルを持たない圧縮メタ行＝型2 で true） */
    withFieldName?: boolean;
    className?: string;
}

export default function Rate({ min, max, note, variant = 'strong', withFieldName = false, className }: Props) {
    const isStrong = variant === 'strong';
    const valueClass = cn(
        'break-words',
        isStrong ? 'text-[13px] font-semibold text-foreground' : '',
        className,
    );

    // 単位「万円」は数値より弱く見せる（strong のときのみ。plain は行全体が同じトーン）。
    const unit = <span className={isStrong ? 'text-[11px] font-normal text-muted-foreground' : undefined}>万円</span>;

    // 範囲記号「〜」も値ではなく連結記号なので単位と同じ扱い（淡色・細字）にする。
    // 濃色のままだと 60(濃)万円(淡)〜(濃)90(淡) と濃淡が交互になり、数値が読み取りにくい。
    // サイズは詰まって見えないよう数値と同じまま。
    const connector = <span className={isStrong ? 'font-normal text-muted-foreground' : undefined}>〜</span>;

    if (min != null && max != null) {
        return (
            <span className={valueClass}>
                {min}
                {unit}
                {connector}
                {max}
                {unit}
            </span>
        );
    }

    // 片側のみのレンジも表示する（下限だけ決まっている案件は実在するため）。
    if (min != null) {
        return (
            <span className={valueClass}>
                {min}
                {unit}
                {connector}
            </span>
        );
    }

    if (max != null) {
        return (
            <span className={valueClass}>
                {connector}
                {max}
                {unit}
            </span>
        );
    }

    // レンジが無い場合は備考（「応相談」等の自由文）。自由文は数値ではないため強調しない。
    if (note) {
        return <span className={cn('break-words', isStrong && 'text-[13px] text-foreground', className)}>{note}</span>;
    }

    return <EmptyValue field="rate" withFieldName={withFieldName} className={cn('break-words', className)} />;
}
