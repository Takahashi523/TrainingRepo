import PipelineCard from '@/Components/Pipelines/PipelineCard';
import { KanbanColumn as KanbanColumnType, StatusOption } from '@/types/pipeline';

interface Props {
    column: KanbanColumnType;
    /** 進行中ステータス一覧（group 付き）。列見出し下の内訳表示に使う。 */
    statuses: StatusOption[];
    /** カード上のステータス変更プルダウン用の全ステータス選択肢（16種）。 */
    statusOptions: StatusOption[];
    activeId?: number | null;
    onOpenCard: (id: number) => void;
}

/**
 * カンバンの1グループ列。グループ見出し・件数・内訳（含まれる詳細ステータス）・カード群・空表示を描画する。
 */
export default function KanbanColumn({ column, statuses, statusOptions, activeId, onOpenCard }: Props) {
    // この列（グループ）に属する詳細ステータスの表示名（WF_10 の列サブ見出し「上位提案 / 求職者応募済み / 応募中」相当）
    const breakdown = statuses
        .filter((s) => s.group === column.key)
        .map((s) => s.label)
        .join(' / ');

    return (
        <div className="flex min-w-[240px] flex-1 flex-col border-r border-border bg-muted/40 last:border-r-0">
            {/* 列ヘッダ：グループ名 + 件数 */}
            <div className="flex shrink-0 items-center gap-1.5 border-b border-border bg-muted px-2.5 py-1.5">
                <span className="truncate text-[11px] font-bold text-foreground">{column.label}</span>
                <span className="ml-auto shrink-0 rounded-full bg-muted-foreground px-1.5 text-[9px] font-bold text-white">
                    {column.count}
                </span>
            </div>

            {/* 内訳（含まれる詳細ステータス） */}
            {breakdown && (
                <div className="shrink-0 border-b border-border bg-muted/70 px-2.5 py-1 text-[9px] leading-tight text-muted-foreground">
                    {breakdown}
                </div>
            )}

            {/* カード群 or 空表示 */}
            {column.cards.length === 0 ? (
                <div className="flex flex-1 items-center justify-center py-8 text-[10px] text-muted-foreground/60">
                    カードなし
                </div>
            ) : (
                <div className="flex flex-1 flex-col gap-2 overflow-y-auto p-2">
                    {column.cards.map((card) => (
                        <PipelineCard
                            key={card.id}
                            card={card}
                            statusOptions={statusOptions}
                            activeId={activeId}
                            onOpen={onOpenCard}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
