import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

type TabKey = 'active' | 'completed';

interface Props {
    /** 現在アクティブなタブ */
    active: TabKey;
    /** 進行中の件数（進行中タブでのみ算出可能。未指定時はバッジ非表示） */
    activeCount?: number;
    /** 完了済みの件数（完了済みタブでのみ算出可能。未指定時はバッジ非表示） */
    completedCount?: number;
}

/**
 * 進行中 / 完了済みのタブ切り替えヘッダ。
 * タブクリックで各一覧ルートへ遷移する（WF_10 のタブ相当）。
 * 件数は取得できるタブ側でのみ算出できるため、値がある場合のみバッジ表示する。
 */
export default function PipelineTabHeader({ active, activeCount, completedCount }: Props) {
    return (
        <div className="flex items-end border-b-2 border-border bg-white px-6">
            <TabItem
                label="進行中"
                count={activeCount}
                isActive={active === 'active'}
                onClick={() => router.get(route('pipelines.index'))}
            />
            <TabItem
                label="完了済み"
                count={completedCount}
                isActive={active === 'completed'}
                onClick={() => router.get(route('pipelines.completed'))}
            />
        </div>
    );
}

function TabItem({
    label,
    count,
    isActive,
    onClick,
}: {
    label: string;
    count?: number;
    isActive: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                '-mb-0.5 border-b-[3px] px-4 py-2.5 text-[13px] font-semibold whitespace-nowrap transition-colors',
                isActive
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
            {count != null && (
                <span
                    className={cn(
                        'ml-1.5 inline-block rounded-full px-1.5 text-[10px] font-bold text-white','bg-primary'
                    )}
                >
                    {count}
                </span>
            )}
        </button>
    );
}
