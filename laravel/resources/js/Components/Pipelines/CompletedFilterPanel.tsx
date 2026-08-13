import ActiveTag from '@/Components/Common/ActiveTag';
import DateInput from '@/Components/Common/DateInput';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { CompletedFilters, StatusOption, UserOption } from '@/types/pipeline';
import { usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';

interface Props {
    filters: CompletedFilters;
    users: UserOption[];
    statuses: StatusOption[];
    keywordInput: string;
    onKeywordInput: (value: string) => void;
    onFilterChange: (patch: Partial<CompletedFilters>) => void;
    onClearAll: () => void;
}


/**
 * 完了済みタブのフィルタ（常時表示）。
 * キーワード・終了ステータス・担当営業・終了日範囲・ソートを扱う。
 */
export default function CompletedFilterPanel({
    filters,
    users,
    statuses,
    keywordInput,
    onKeywordInput,
    onFilterChange,
    onClearAll,
}: Props) {
    const statusOptions: MultiSelectOption[] = statuses.map((s) => ({ value: s.value, label: s.label }));

    // 終了日範囲のバリデーションエラー（例：終了 < 開始）。エラー時はサーバーが直前の
    // 条件のまま差し戻すため、メッセージを出さないと「選んだのに反映されない」Silent Rejection になる
    const { errors } = usePage().props;
    const dateRangeError = errors.ended_from ?? errors.ended_to;

    const statusLabel = (v: string) => statuses.find((s) => s.value === v)?.label ?? v;
    const userLabel = (id: number) => users.find((u) => u.id === id)?.name ?? `ID:${id}`;

    const hasAnyFilter =
        filters.keyword.length > 0 ||
        filters.status.length > 0 ||
        filters.user_id != null ||
        !!filters.ended_from ||
        !!filters.ended_to;

    return (
        <div className="border-b border-border bg-muted/40 px-6 py-3">
            <div className="flex flex-wrap items-center gap-2.5">
                {/* フリーワード */}
                <div className="relative">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    {/* バックエンドの max:100（バリデーション設計書 §6）と揃えてフロントでも制限する */}
                    <Input
                        type="text"
                        value={keywordInput}
                        onChange={(e) => onKeywordInput(e.target.value)}
                        placeholder="人材名・案件名で検索"
                        maxLength={100}
                        className="h-8 w-[220px] bg-white pl-8 pr-2 text-xs md:text-xs"
                    />
                </div>

                <span className="mx-1 h-5 w-px bg-border" />

                {/* 担当営業（単一・全員含む）。Radix Select は空文字 value 非対応のため
                    「全員」を 'all' というセンチネル値で表現する。
                    並び順・ラベルは進行中タブに合わせる（外にラベルを出すため option は「全員」に簡素化）。 */}
                <label className="flex items-center gap-1.5">
                    <span className="shrink-0 text-[11px] font-semibold text-muted-foreground">担当営業</span>
                    <Select
                        value={filters.user_id == null ? 'all' : String(filters.user_id)}
                        onValueChange={(v) =>
                            onFilterChange({ user_id: v === 'all' ? null : Number(v) })
                        }
                    >
                        <SelectTrigger className="h-8 w-[200px] bg-white text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        {/* 氏名は最大255文字になり得るため max-w＋truncate で突き抜けを防止（レビュー指摘 #9） */}
                        <SelectContent className="max-w-[260px]">
                            <SelectItem value="all" className="text-xs">
                                全担当（絞り込みなし）
                            </SelectItem>
                            {users.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)} className="text-xs">
                                    <span className="block max-w-[210px] truncate">{u.name}</span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </label>

                <MultiSelectDropdown
                    label="ステータス"
                    options={statusOptions}
                    selected={filters.status}
                    onChange={(next) => onFilterChange({ status: next })}
                />

                {/* 終了日範囲 */}
                <div className="flex items-center gap-1.5">
                    <span className="shrink-0 text-[11px] font-semibold text-muted-foreground">終了日</span>
                    <DateInput
                        value={filters.ended_from ?? ''}
                        onChange={(v) =>
                            onFilterChange({ ended_from: v === '' ? null : v })
                        }
                        className="h-8 w-auto bg-white px-2 text-xs md:text-xs"
                        aria-label="終了日（開始）"
                    />
                    <span className="text-[11px] text-muted-foreground">〜</span>
                    <DateInput
                        value={filters.ended_to ?? ''}
                        onChange={(v) =>
                            onFilterChange({ ended_to: v === '' ? null : v })
                        }
                        className="h-8 w-auto bg-white px-2 text-xs md:text-xs"
                        aria-label="終了日（終了）"
                    />
                </div>
            </div>

            {dateRangeError && (
                <p className="mt-1 text-[11px] text-destructive">{dateRangeError}</p>
            )}

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <span className="text-[11px] text-muted-foreground">絞り込み条件：</span>

                {filters.keyword && (
                    <ActiveTag
                        label={`"${filters.keyword}"`}
                        onRemove={() => {
                            onKeywordInput('');
                            onFilterChange({ keyword: '' });
                        }}
                    />
                )}
                {filters.status.map((v) => (
                    <ActiveTag
                        key={`st-${v}`}
                        label={statusLabel(v)}
                        onRemove={() => onFilterChange({ status: filters.status.filter((x) => x !== v) })}
                    />
                ))}
                {filters.user_id != null && (
                    <ActiveTag
                        label={`担当：${userLabel(filters.user_id)}`}
                        onRemove={() => onFilterChange({ user_id: null })}
                    />
                )}
                {filters.ended_from && (
                    <ActiveTag
                        label={`終了日 >= ${filters.ended_from}`}
                        onRemove={() => onFilterChange({ ended_from: null })}
                    />
                )}
                {filters.ended_to && (
                    <ActiveTag
                        label={`終了日 <= ${filters.ended_to}`}
                        onRemove={() => onFilterChange({ ended_to: null })}
                    />
                )}
                {!hasAnyFilter && (
                    <span className="text-[11px] text-muted-foreground">（適用中の条件はありません）</span>
                )}

                {hasAnyFilter && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onClearAll}
                        className="ml-1 h-7 gap-1 px-2.5 text-[11px] text-muted-foreground [&_svg]:size-3"
                    >
                        <X />
                        すべてクリア
                    </Button>
                )}
            </div>
        </div>
    );
}
