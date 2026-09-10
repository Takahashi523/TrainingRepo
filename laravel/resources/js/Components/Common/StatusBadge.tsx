import { cn } from '@/lib/utils';

export const STATUS_STYLES: Record<string, { badgeClass: string; accentClass: string }> = {
    // engineers.status
    proposable: { badgeClass: 'border-green-600 text-green-700 bg-green-50', accentClass: 'bg-green-600' },
    interviewing: { badgeClass: 'border-amber-500 text-amber-700 bg-amber-50', accentClass: 'bg-amber-500' },
    not_proposable: { badgeClass: 'border-gray-400 text-gray-600 bg-gray-50', accentClass: 'bg-gray-400' },

    // projects.status
    open: { badgeClass: 'border-green-600 text-green-700 bg-green-50', accentClass: 'bg-green-600' },
    pending: { badgeClass: 'border-amber-500 text-amber-700 bg-amber-50', accentClass: 'bg-amber-500' },
    closed: { badgeClass: 'border-gray-400 text-gray-600 bg-gray-50', accentClass: 'bg-gray-400' },
};

const DEFAULT_STYLE = { badgeClass: 'border-gray-400 text-gray-600 bg-gray-50', accentClass: 'bg-gray-400' };

/** 人材ステータスの表示ラベル（SSOT）。label 未指定時の既定表示に使う。 */
export const ENGINEER_STATUS_LABELS: Record<string, string> = {
    proposable: '提案可',
    interviewing: '面談中',
    not_proposable: '提案不可',
};

interface Props {
    status: string;
    /** 表示ラベル。未指定なら status から既定ラベル（ENGINEER_STATUS_LABELS）を解決し、未定義値は status を出す。 */
    label?: string;
    /** 追加クラス（レイアウト調整用。例：flex 内で縮ませない shrink-0）。 */
    className?: string;
}

export default function StatusBadge({ status, label, className }: Props) {
    const s = STATUS_STYLES[status] ?? DEFAULT_STYLE;
    const text = label ?? ENGINEER_STATUS_LABELS[status] ?? status;
    return (
        <span className={cn('rounded-full border px-3 py-0.5 text-xs font-bold', s.badgeClass, className)}>
            {text}
        </span>
    );
}
