import TruncatedText from '@/Components/Common/TruncatedText';
import { cn } from '@/lib/utils';
import { ActiveFilters, RankOption, UserOption } from '@/types/pipeline';
import { Search } from 'lucide-react';

interface Props {
    filters: ActiveFilters;
    users: UserOption[];
    ranks: RankOption[];
}

const CHIP_CLASS =
    'inline-flex items-center rounded-full border border-border bg-white px-2.5 py-0.5 text-[11px] font-normal text-muted-foreground';

/** 固定長のサマリーチップ（縮まず常時表示） */
function FixedChip({ children }: { children: React.ReactNode }) {
    return <span className={cn(CHIP_CLASS, 'shrink-0')}>{children}</span>;
}

/**
 * 表示条件バー（折りたたみバー）の適用条件サマリー（読み取り専用のチップ行）。
 * WF_10 filter-toggle-summary 準拠。折りたたんだ状態でも現在の絞り込み条件を一目で把握できるようにする。
 *
 * 各条件を独立チップとして描画する。固定長（担当スコープ・ランク・ステータス件数）は shrink-0 で常時表示し、
 * 可変長（キーワード・担当者名）だけ max-w＋TruncatedText で個別に省略する。
 * これにより長いキーワード・担当者名が入っても他条件（ステータス等）が隠れない
 * （1本の truncate 文字列だと末尾ごと消える問題への対処）。
 * 担当スコープは適用条件パネル（PipelineFilterPanel）と同じ挙動で、既定（自担当）も常時チップ表示する。
 */
export default function PipelineFilterSummary({ filters, users, ranks }: Props) {
    const chips: React.ReactNode[] = [];

    if (filters.keyword) {
        // キーワードは可変長。アイコン＋省略テキストのチップにし、このチップ内だけで省略する
        chips.push(
            <span key="kw" className={cn(CHIP_CLASS, 'min-w-0 max-w-[220px] shrink gap-1')}>
                <Search className="h-3 w-3 shrink-0" />
                <TruncatedText text={filters.keyword} className="min-w-0 flex-1 text-[11px]" />
            </span>,
        );
    }

    // 担当スコープは適用条件パネルと同じく常に表示する（既定の自担当も静的チップで示す）
    if (filters.user_id == null) {
        chips.push(<FixedChip key="user">自担当（サブ含む）</FixedChip>);
    } else if (filters.user_id === 'all') {
        chips.push(<FixedChip key="user">全担当（絞り込みなし）</FixedChip>);
    } else {
        // 担当者名は最大255文字になり得る可変長。「担当：」は固定表示し、氏名だけ省略する
        const name = users.find((u) => u.id === filters.user_id)?.name ?? `ID:${filters.user_id}`;
        chips.push(
            <span key="user" className={cn(CHIP_CLASS, 'min-w-0 max-w-[200px] shrink')}>
                <span className="shrink-0">担当：</span>
                <TruncatedText text={name} className="min-w-0 flex-1 text-[11px]" />
            </span>,
        );
    }

    if (filters.rank.length > 0) {
        const labels = filters.rank.map((v) => ranks.find((r) => r.value === v)?.label ?? v);
        chips.push(<FixedChip key="rank">ランク：{labels.join('・')}</FixedChip>);
    }

    if (filters.status.length > 0) {
        chips.push(<FixedChip key="status">ステータス：{filters.status.length}件</FixedChip>);
    }

    return (
        <span className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden">{chips}</span>
    );
}
