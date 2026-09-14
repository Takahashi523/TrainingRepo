import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { PaginationMeta } from '@/types';
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

    // 現在ページのボタンは disabled にしない（フォーカス可能なまま aria-current="page" で
    // 現在地を伝えるため）ので、そのまま押せてしまう。素通しすると
    // 「同じ内容を取り直したうえで視点だけ先頭へ飛ぶ」不可解な動きになるため、ここで止める。
    //
    // 呼び出し側（一覧3画面）ではなくこの部品で止めるのは、「現在ページも押せる」という前提が
    // この部品の実装事情だから。利用画面ごとに同じガードを写すと、次にページ送りを持つ画面が
    // 写し忘れて同じ症状を再発させる（issue #107）。
    const handleChange = (page: number) => {
        if (page === meta.current_page) return;
        onChange(page);
    };

    return (
        <div className="flex items-center justify-center gap-1.5 py-3">
            <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => handleChange(prev)}
                disabled={meta.current_page <= 1}
                className="h-8 w-8 bg-white"
                aria-label="前のページ"
            >
                <ChevronLeft className="h-3.5 w-3.5" />
            </Button>
            {items.map((item, i) =>
                item === 'ellipsis' ? (
                    <span key={`e-${i}`} className="px-1 text-xs text-muted-foreground">
                        …
                    </span>
                ) : (
                    <Button
                        key={item}
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={() => handleChange(item)}
                        className={cn(
                            'h-8 w-8 bg-white text-xs',
                            item === meta.current_page
                                ? 'border-primary font-bold text-primary hover:bg-white'
                                : 'hover:bg-muted/50',
                        )}
                        aria-current={item === meta.current_page ? 'page' : undefined}
                    >
                        {item}
                    </Button>
                ),
            )}
            <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => handleChange(next)}
                disabled={meta.current_page >= meta.last_page}
                className="h-8 w-8 bg-white"
                aria-label="次のページ"
            >
                <ChevronRight className="h-3.5 w-3.5" />
            </Button>
            {meta.from != null && meta.to != null && (
                <span className="ml-2 text-xs text-muted-foreground">
                    {meta.from}〜{meta.to}件 / 全{meta.total}件
                </span>
            )}
        </div>
    );
}
