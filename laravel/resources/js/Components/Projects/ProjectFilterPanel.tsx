import ActiveTag from '@/Components/Common/ActiveTag';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import SavedSearchManageDialog from '@/Components/Common/SavedSearchManageDialog';
import SavedSearchSelect from '@/Components/Common/SavedSearchSelect';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    CommercialFlowOption,
    InterviewCountOption,
    ProjectFilters,
    ProjectSearchConditions,
    StatusOption,
    WorkStyleOption,
} from '@/types/project';
import { SavedSearchItem } from '@/types/savedSearch';
import { Search, Star, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    filters: ProjectFilters;
    statuses: StatusOption[];
    workStyles: WorkStyleOption[];
    commercialFlows: CommercialFlowOption[];
    interviewCounts: InterviewCountOption[];
    savedSearches: SavedSearchItem<ProjectSearchConditions>[];
    keywordInput: string;
    onKeywordInput: (value: string) => void;
    onFilterChange: (patch: Partial<ProjectFilters>) => void;
    onClearAll: () => void;
}

// キーワード入力の上限。案件名 max:255・バックエンドのkeywordバリデーション max:255 と一致させる
// （人材一覧も keyword上限＝氏名上限 の原則で揃えており、それに倣う。PRレビュー #53 指摘）
const KEYWORD_MAX_LENGTH = 255;

export default function ProjectFilterPanel({
    filters,
    statuses,
    workStyles,
    commercialFlows,
    interviewCounts,
    savedSearches,
    keywordInput,
    onKeywordInput,
    onFilterChange,
    onClearAll,
}: Props) {
    // 検索条件保存に送る「今の絞り込み状態」（page/per_page は含めない）
    const currentConditions: ProjectSearchConditions = {
        status: filters.status,
        work_style: filters.work_style,
        commercial_flow: filters.commercial_flow,
        interview_count: filters.interview_count,
        keyword: filters.keyword,
        sort: filters.sort,
        order: filters.order,
    };

    const statusOptions: MultiSelectOption[] = statuses.map((s) => ({ value: s.value, label: s.label }));
    const workStyleOptions: MultiSelectOption[] = workStyles.map((w) => ({ value: w.value, label: w.label }));
    const commercialFlowOptions: MultiSelectOption[] = commercialFlows.map((c) => ({
        value: c.value,
        label: c.label,
    }));
    const interviewCountOptions: MultiSelectOption[] = interviewCounts.map((i) => ({
        value: String(i.value),
        label: i.label,
    }));

    const statusLabel = (v: string) => statuses.find((s) => s.value === v)?.label ?? v;
    const workStyleLabel = (v: string) => workStyles.find((w) => w.value === v)?.label ?? v;
    const commercialFlowLabel = (v: string) => commercialFlows.find((c) => c.value === v)?.label ?? v;
    const interviewCountLabel = (v: string) =>
        interviewCounts.find((i) => String(i.value) === v)?.label ?? v;

    // 保存モーダルに表示する、現在の絞り込み条件のタグ（表示専用）
    const activeTagLabels = [
        ...filters.status.map(statusLabel),
        ...filters.work_style.map(workStyleLabel),
        ...filters.commercial_flow.map(commercialFlowLabel),
        ...filters.interview_count.map((v) => interviewCountLabel(String(v))),
        ...(filters.keyword ? [`"${filters.keyword}"`] : []),
    ];

    const [showSaveModal, setShowSaveModal] = useState(false);

    const hasAnyFilter =
        filters.status.length > 0 ||
        filters.work_style.length > 0 ||
        filters.commercial_flow.length > 0 ||
        filters.interview_count.length > 0 ||
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
                        placeholder="案件名・スキルで検索"
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
                    selected={filters.work_style}
                    onChange={(next) => onFilterChange({ work_style: next })}
                />

                <MultiSelectDropdown
                    label="商流"
                    options={commercialFlowOptions}
                    selected={filters.commercial_flow}
                    onChange={(next) => onFilterChange({ commercial_flow: next })}
                />

                <MultiSelectDropdown
                    label="面談回数"
                    options={interviewCountOptions}
                    selected={filters.interview_count.map(String)}
                    onChange={(next) => onFilterChange({ interview_count: next.map(Number) })}
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
                {filters.work_style.map((v) => (
                    <ActiveTag
                        key={`w-${v}`}
                        label={workStyleLabel(v)}
                        onRemove={() =>
                            onFilterChange({ work_style: filters.work_style.filter((x) => x !== v) })
                        }
                    />
                ))}
                {filters.commercial_flow.map((v) => (
                    <ActiveTag
                        key={`c-${v}`}
                        label={commercialFlowLabel(v)}
                        onRemove={() =>
                            onFilterChange({
                                commercial_flow: filters.commercial_flow.filter((x) => x !== v),
                            })
                        }
                    />
                ))}
                {filters.interview_count.map((v) => (
                    <ActiveTag
                        key={`i-${v}`}
                        label={interviewCountLabel(String(v))}
                        onRemove={() =>
                            onFilterChange({
                                interview_count: filters.interview_count.filter((x) => x !== v),
                            })
                        }
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

                {/* 保存済み条件の呼び出し・保存（WF_06の配置に合わせて右端） */}
                <div className="ml-auto flex items-center gap-2">
                    <span className="text-[11px] text-muted-foreground">保存済み条件</span>
                    <SavedSearchSelect
                        savedSearches={savedSearches}
                        onApply={onFilterChange}
                    />
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
                searchType="project"
                savedSearches={savedSearches}
                currentConditions={currentConditions}
                activeTagLabels={activeTagLabels}
                onClose={() => setShowSaveModal(false)}
            />
        </div>
    );
}
