import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { cn } from '@/lib/utils';

export interface SortOption {
    sort: string;
    order: string;
    label: string;
}

function toValue(sort: string, order: string) {
    return `${sort}:${order}`;
}

interface Props {
    options: SortOption[];
    currentSort: string;
    currentOrder: string;
    onChange: (sort: string, order: string) => void;
    // トリガの追加クラス（主に幅の上書き用）。ラベル長が画面ごとに異なるため、
    // デフォルトは固定幅で選択による幅変動を防ぎ、必要な画面は w-* で上書きする。
    className?: string;
}

export default function SortSelect({ options, currentSort, currentOrder, onChange, className }: Props) {
    return (
        <div className="flex items-center gap-2">
            <span className="text-[11px] text-muted-foreground">ソート</span>
            <Select
                value={toValue(currentSort, currentOrder)}
                onValueChange={(value) => {
                    const [sort, order] = value.split(':');
                    onChange(sort, order);
                }}
            >
                <SelectTrigger className={cn('h-8 w-[190px] gap-1.5 bg-white text-xs text-foreground', className)}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem key={toValue(o.sort, o.order)} value={toValue(o.sort, o.order)} className="text-xs">
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
