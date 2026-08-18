import RankBadge from '@/Components/Common/RankBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import StatusSelect from '@/Components/Pipelines/StatusSelect';
import { emptyText } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';
import { PipelineCard as PipelineCardType, PipelineStatus, StatusOption } from '@/types/pipeline';
import { router } from '@inertiajs/react';

/** 日付文字列（YYYY-MM-DD 等）を日本語表記に整形。null は空文字。 */
function formatDate(value: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

interface Props {
    card: PipelineCardType;
    /** カード上のステータス変更プルダウン用の全ステータス選択肢（進行中12種＋終了4種） */
    statusOptions: StatusOption[];
    /** ドロワー表示中のカードを強調するための現在選択 id */
    activeId?: number | null;
    onOpen: (id: number) => void;
}

/**
 * カンバンカード。人材名・案件名・ランク・スコア・担当メイン・更新日を表示し、
 * カード内のプルダウンで直接ステータスを変更できる（WF_10 の card-status-select 準拠）。
 * カード本体クリックで onOpen(id) を呼びドロワーを開く。プルダウン操作はカードクリックへ伝播させない。
 */
export default function PipelineCard({ card, statusOptions, activeId, onOpen }: Props) {
    const isActive = activeId === card.id;

    // カード上でのステータス変更。終了ステータスの不可逆確認は StatusSelect 側で行うため、
    // ここでは確認通過後の即時反映（部分リロード）のみを担う。
    const changeStatus = (value: PipelineStatus) => {
        router.patch(
            route('pipelines.update', card.id),
            { status: value },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => onOpen(card.id)}
            onKeyDown={(e) => {
                if (e.key === 'Enter') onOpen(card.id);
            }}
            className={cn(
                // ホバーは「淡いプライマリの塗り＋枠線＋影」で示す。カンバン列自体がグレー地のため、
                // グレーで塗ると列の背景と同化してホバーが分からなくなる（色相を変えて区別する）。
                'w-full cursor-pointer rounded border border-transparent bg-white p-2.5 text-left transition hover:border-primary/50 hover:bg-primary/5 hover:shadow-md',
                isActive ? 'border-primary shadow-[0_0_0_2px_rgba(0,0,0,0.1)]' : 'border-border',
            )}
        >
            <TruncatedText as="p" className="text-xs font-bold text-foreground" text={card.engineer.name} />
            {/* 顧客名・案件名は個別に省略。短い時は内容幅でぴったり、溢れた時だけ各自 truncate（長い顧客名で案件名が丸ごと消えるのを防ぎ、全文はホバーで確認） */}
            <p className="mt-0.5 flex min-w-0 items-center gap-1 text-[10px] text-muted-foreground">
                {/* 顧客名は nullable。無い場合は区切り「/」を出さず案件名のみ表示する（孤立セパレータ防止） */}
                {card.project.client_name && (
                    <>
                        <TruncatedText text={card.project.client_name} className="min-w-0" />
                        <span className="shrink-0">/</span>
                    </>
                )}
                <TruncatedText text={card.project.name} className="min-w-0" />
            </p>

            <div className="mt-1.5 flex items-center gap-1.5">
                <RankBadge rank={card.match_rank} />
                <span className="text-[11px] font-bold text-muted-foreground">
                    {card.match_score != null ? `${card.match_score}点` : emptyText('matchScore')}
                </span>
            </div>

            {/*
             * WF_10 のカードに「次回」表示は無いが、フィルタのソートに「次回アクション日（近い順）」が存在する。
             * 画面に出ていない項目でソートさせるのは UX 上不適切なため、あえてカードにも「次回」を表示している。
             */}
            <div className="mt-1.5 space-y-0.5 text-[10px] text-muted-foreground">
                <TruncatedText as="div" text={`担当：${card.engineer.main_user?.name ?? '未割当'}`} />
                <div className="flex items-center gap-1">
                    <span className="shrink-0">
                        次回：{card.next_action_date ? formatDate(card.next_action_date) : emptyText('nextActionDate')}
                    </span>
                    <span className="shrink-0 text-muted-foreground/50">/</span>
                    <span className="shrink-0">更新：{formatDate(card.updated_at)}</span>
                </div>
            </div>

            {/* ステータス変更プルダウン（共通 StatusSelect）。
                ステータス操作（プルダウン・終了確認ダイアログ）のクリックがカード本体に伝播して
                ドロワーが開かないよう、領域全体でクリック伝播を止める。 */}
            <div className="mt-1.5" onClick={(e) => e.stopPropagation()}>
                <StatusSelect
                    variant="card"
                    value={card.status}
                    statusOptions={statusOptions}
                    onChange={changeStatus}
                    stopPropagation
                />
            </div>
        </div>
    );
}
