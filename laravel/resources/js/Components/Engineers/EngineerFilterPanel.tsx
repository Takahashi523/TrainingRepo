import ActiveTag from '@/Components/Common/ActiveTag';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import SavedSearchManageDialog from '@/Components/Common/SavedSearchManageDialog';
import SavedSearchSelect from '@/Components/Common/SavedSearchSelect';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { EngineerFilters, EngineerSearchConditions, Phase, StatusOption, WorkTypeOption } from '@/types/engineer';
import { SavedSearchItem } from '@/types/savedSearch';
import { Search, Star, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    filters: EngineerFilters;
    statuses: StatusOption[];
    workStyles: WorkTypeOption[];
    phases: Phase[];
    savedSearches: SavedSearchItem<EngineerSearchConditions>[];
    keywordInput: string;
    onKeywordInput: (value: string) => void;
    onFilterChange: (patch: Partial<EngineerFilters>) => void;
    onClearAll: () => void;
}

// キーワード入力の上限（氏名 max:100 に揃える。サーバ側 EngineerIndexRequest でも検証）
const KEYWORD_MAX_LENGTH = 100;

export default function EngineerFilterPanel({
    filters,
    statuses,
    workStyles,
    phases,
    savedSearches,
    keywordInput,
    onKeywordInput,
    onFilterChange,
    onClearAll,
}: Props) {
    // 保存検索条件に送る「今の絞り込み状態」（page/per_page は含めない）
    const currentConditions: EngineerSearchConditions = {
        status: filters.status,
        work_styles: filters.work_styles,
        phases: filters.phases,
        keyword: filters.keyword,
        sort: filters.sort,
        order: filters.order,
    };

    const statusOptions: MultiSelectOption[] = statuses.map((s) => ({ value: s.value, label: s.label }));
    const workStyleOptions: MultiSelectOption[] = workStyles.map((w) => ({ value: w.key, label: w.name }));
    const phaseOptions: MultiSelectOption[] = phases.map((p) => ({ value: p.key, label: p.name }));

    const statusLabel = (v: string) => statuses.find((s) => s.value === v)?.label ?? v;
    const workStyleLabel = (k: string) => workStyles.find((w) => w.key === k)?.name ?? k;
    const phaseLabel = (k: string) => phases.find((p) => p.key === k)?.name ?? k;

    // 保存モーダルに表示する、現在の絞り込み条件のタグ（表示専用）
    const activeTagLabels = [
        ...filters.status.map(statusLabel),
        ...filters.work_styles.map(workStyleLabel),
        ...filters.phases.map(phaseLabel),
        ...(filters.keyword ? [`"${filters.keyword}"`] : []),
    ];

    const [showSaveModal, setShowSaveModal] = useState(false);

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
                    <Input
                        type="text"
                        value={keywordInput}
                        onChange={(e) => onKeywordInput(e.target.value)}
                        placeholder="氏名・スキルで検索"
                        maxLength={KEYWORD_MAX_LENGTH}
                        className="h-8 w-[220px] bg-white pl-8 pr-2 text-xs md:text-xs"
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

                {/* 保存済み条件の呼び出し・保存（WF_03の配置に合わせて右端） */}
                <div className="ml-auto flex items-center gap-2">
                    <span className="text-[11px] text-muted-foreground">保存済み条件</span>
                    <SavedSearchSelect savedSearches={savedSearches} onApply={onFilterChange} />
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-7 gap-1 bg-white text-[11px]"
                        onClick={() => setShowSaveModal(true)}
                    >
                        <Star className="h-3 w-3" />
                        条件を保存
                    </Button>
                </div>
            </div>

            <SavedSearchManageDialog
                open={showSaveModal}
                searchType="engineer"
                savedSearches={savedSearches}
                currentConditions={currentConditions}
                activeTagLabels={activeTagLabels}
                onClose={() => setShowSaveModal(false)}
            />
        </div>
    );
}
