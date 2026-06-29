import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import SortSelect, { SortOption } from '@/Components/Common/SortSelect';
import { EngineerFilters, Phase, StatusOption, WorkTypeOption } from '@/types/engineer';
import { Search, X } from 'lucide-react';

interface Props {
    filters: EngineerFilters;
    statuses: StatusOption[];
    workStyles: WorkTypeOption[];
    phases: Phase[];
    keywordInput: string;
    onKeywordInput: (value: string) => void;
    onFilterChange: (patch: Partial<EngineerFilters>) => void;
    onClearAll: () => void;
}

const SORT_OPTIONS: SortOption[] = [
    { sort: 'created_at',     order: 'desc', label: '登録日順（新しい順）' },
    { sort: 'created_at',     order: 'asc',  label: '登録日順（古い順）' },
    { sort: 'updated_at',     order: 'desc', label: '更新日順（新しい順）' },
    { sort: 'available_from', order: 'asc',  label: '提案可能タイミング順' },
];

export default function EngineerFilterPanel({
    filters,
    statuses,
    workStyles,
    phases,
    keywordInput,
    onKeywordInput,
    onFilterChange,
    onClearAll,
}: Props) {
    const statusOptions: MultiSelectOption[] = statuses.map((s) => ({ value: s.value, label: s.label }));
    const workStyleOptions: MultiSelectOption[] = workStyles.map((w) => ({ value: w.key, label: w.name }));
    const phaseOptions: MultiSelectOption[] = phases.map((p) => ({ value: p.key, label: p.name }));

    const statusLabel = (v: string) => statuses.find((s) => s.value === v)?.label ?? v;
    const workStyleLabel = (k: string) => workStyles.find((w) => w.key === k)?.name ?? k;
    const phaseLabel = (k: string) => phases.find((p) => p.key === k)?.name ?? k;

    const hasAnyFilter =
        filters.status.length > 0 ||
        filters.work_styles.length > 0 ||
        filters.phases.length > 0 ||
        filters.keyword.length > 0;

    return (
        <div className="border-b border-border bg-muted/40 px-6 py-3">
            <div className="flex flex-wrap items-center gap-2.5">
                {/* フリーワード */}
                <div className="relative">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="text"
                        value={keywordInput}
                        onChange={(e) => onKeywordInput(e.target.value)}
                        placeholder="氏名・スキルで検索"
                        className="h-8 w-[220px] rounded-md border border-input bg-white pl-8 pr-2 text-xs"
                    />
                </div>

                <span className="mx-1 h-5 w-px bg-border" />

                <MultiSelectDropdown
                    label="ステータス"
                    options={statusOptions}
                    selected={filters.status}
                    onChange={(next) => onFilterChange({ status: next })}
                />

                <MultiSelectDropdown
                    label="勤務形態"
                    options={workStyleOptions}
                    selected={filters.work_styles}
                    onChange={(next) => onFilterChange({ work_styles: next })}
                />

                <MultiSelectDropdown
                    label="工程経験"
                    options={phaseOptions}
                    selected={filters.phases}
                    onChange={(next) => onFilterChange({ phases: next })}
                />
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <span className="text-[11px] text-muted-foreground">絞り込み条件：</span>

                {filters.status.map((v) => (
                    <ActiveTag
                        key={`s-${v}`}
                        label={statusLabel(v)}
                        onRemove={() => onFilterChange({ status: filters.status.filter((x) => x !== v) })}
                    />
                ))}
                {filters.work_styles.map((v) => (
                    <ActiveTag
                        key={`w-${v}`}
                        label={workStyleLabel(v)}
                        onRemove={() => onFilterChange({ work_styles: filters.work_styles.filter((x) => x !== v) })}
                    />
                ))}
                {filters.phases.map((v) => (
                    <ActiveTag
                        key={`p-${v}`}
                        label={phaseLabel(v)}
                        onRemove={() => onFilterChange({ phases: filters.phases.filter((x) => x !== v) })}
                    />
                ))}
                {filters.keyword && (
                    <ActiveTag
                        label={`"${filters.keyword}"`}
                        onRemove={() => {
                            onKeywordInput('');
                            onFilterChange({ keyword: '' });
                        }}
                    />
                )}
                {!hasAnyFilter && (
                    <span className="text-[11px] text-muted-foreground">（適用中の条件はありません）</span>
                )}

                {hasAnyFilter && (
                    <button
                        type="button"
                        onClick={onClearAll}
                        className="ml-1 inline-flex h-7 items-center gap-1 rounded-md border border-input bg-white px-2.5 text-[11px] text-muted-foreground hover:bg-muted/50"
                    >
                        <X className="h-3 w-3" />
                        すべてクリア
                    </button>
                )}

                <div className="ml-auto">
                    <SortSelect
                        options={SORT_OPTIONS}
                        currentSort={filters.sort}
                        currentOrder={filters.order}
                        onChange={(sort, order) =>
                            onFilterChange({
                                sort: sort as EngineerFilters['sort'],
                                order: order as EngineerFilters['order'],
                            })
                        }
                    />
                </div>
            </div>
        </div>
    );
}

function ActiveTag({ label, onRemove }: { label: string; onRemove: () => void }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-foreground/60 bg-muted px-2.5 py-0.5 text-[11px]">
            {label}
            <button
                type="button"
                onClick={onRemove}
                className="text-muted-foreground hover:text-foreground"
                aria-label={`${label} を解除`}
            >
                <X className="h-3 w-3" />
            </button>
        </span>
    );
}
