import { cn } from '@/lib/utils';
import { PaginationMeta } from '@/types/engineer';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface Props {
    meta: PaginationMeta;
    onChange: (page: number) => void;
}

function ellipsisIf(condition: boolean): Array<'ellipsis'> {
    return condition ? ['ellipsis'] : [];
}

function buildPageItems(current: number, last: number): Array<number | 'ellipsis'> {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }

    const windowStart = Math.max(2, current - 1);
    const windowEnd = Math.min(last - 1, current + 1);
    const windowPages = Array.from({ length: windowEnd - windowStart + 1 }, (_, i) => windowStart + i);

    return [
        1,
        ...ellipsisIf(windowStart > 2),
        ...windowPages,
        ...ellipsisIf(windowEnd < last - 1),
        last,
    ];
}

export default function Pagination({ meta, onChange }: Props) {
    if (meta.total === 0) return null;

    const items = buildPageItems(meta.current_page, meta.last_page);
    const prev = Math.max(1, meta.current_page - 1);
    const next = Math.min(meta.last_page, meta.current_page + 1);

    return (
        <div className="flex items-center justify-center gap-1.5 py-3">
            <button
                type="button"
                onClick={() => onChange(prev)}
                disabled={meta.current_page <= 1}
                className="flex h-8 w-8 items-center justify-center rounded border border-input bg-white text-xs disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="前のページ"
            >
                <ChevronLeft className="h-3.5 w-3.5" />
            </button>
            {items.map((item, i) =>
                item === 'ellipsis' ? (
                    <span key={`e-${i}`} className="px-1 text-xs text-muted-foreground">
                        …
                    </span>
                ) : (
                    <button
                        key={item}
                        type="button"
                        onClick={() => onChange(item)}
                        className={cn(
                            'h-8 w-8 rounded border text-xs',
                            item === meta.current_page
                                ? 'border-primary bg-white font-bold text-primary'
                                : 'border-input bg-white hover:bg-muted/50',
                        )}
                    >
                        {item}
                    </button>
                ),
            )}
            <button
                type="button"
                onClick={() => onChange(next)}
                disabled={meta.current_page >= meta.last_page}
                className="flex h-8 w-8 items-center justify-center rounded border border-input bg-white text-xs disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="次のページ"
            >
                <ChevronRight className="h-3.5 w-3.5" />
            </button>
            {meta.from != null && meta.to != null && (
                <span className="ml-2 text-xs text-muted-foreground">
                    {meta.from}〜{meta.to}件 / 全{meta.total}件
                </span>
            )}
        </div>
    );
}
