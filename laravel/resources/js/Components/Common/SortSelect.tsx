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
}

export default function SortSelect({ options, currentSort, currentOrder, onChange }: Props) {
    return (
        <div className="flex items-center gap-2">
            <span className="text-[11px] text-muted-foreground">ソート</span>
            <select
                value={toValue(currentSort, currentOrder)}
                onChange={(e) => {
                    const [sort, order] = e.target.value.split(':');
                    onChange(sort, order);
                }}
                className="h-8 rounded-md border border-input bg-white pl-2.5 pr-8 text-xs"
            >
                {options.map((o) => (
                    <option key={toValue(o.sort, o.order)} value={toValue(o.sort, o.order)}>
                        {o.label}
                    </option>
                ))}
            </select>
        </div>
    );
}
