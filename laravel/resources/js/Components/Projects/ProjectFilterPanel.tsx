import ActiveTag from '@/Components/Common/ActiveTag';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import SavedSearchManageDialog from '@/Components/Common/SavedSearchManageDialog';
import SavedSearchMenu from '@/Components/Common/SavedSearchMenu';
import SavedSearchSaveDialog from '@/Components/Common/SavedSearchSaveDialog';
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
import { SortOption } from '@/types';
import { List, Search, Star, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    filters: ProjectFilters;
    statuses: StatusOption[];
    workStyles: WorkStyleOption[];
    commercialFlows: CommercialFlowOption[];
    interviewCounts: InterviewCountOption[];
    savedSearches: SavedSearchItem<ProjectSearchConditions>[];
    sortOptions: SortOption[];
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
    sortOptions,
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

    // 現在のソートが既定（sortOptions の先頭）以外のときだけラベルを渡す。
    // 既定のままなら保存モーダルの「並び順」セクション自体を出さず、自動生成名にも含めない。
    const currentSortOption = sortOptions.find(
        (o) => o.sort === filters.sort && o.order === filters.order
    );
    const sortLabel =
        currentSortOption && currentSortOption !== sortOptions[0]
            ? currentSortOption.label
            : undefined;

    // 保存済み条件の適用。
    // 保存時は SavedSearchService::sanitizeConditions() が全キーを埋めた配列を組み立てるため、
    // 現行の保存経路を通ったレコードにキー欠落はない。ただし保存後にフィルタ次元を増やすと、
    // それ以前に保存された古いレコードには新しいキーが無い。patch をそのままマージすると
    // 欠けた次元に現在の絞り込みが残り、「保存したものと違う条件が適用された」状態（部分適用）に
    // なるため、適用は merge ではなく replace として扱い、先に全次元を既定値へ戻す。
    //
    // 既定値には型注釈を付ける。onFilterChange の引数は Partial<ProjectFilters> のため、
    // 注釈が無いとキーが1つ欠けてもコンパイルエラーにならず、次元追加時に部分適用が静かに再発する。
    // なお「フィルタ次元の一覧」は ProjectSearchConditions（型）／SavedSearchService::sanitizeConditions()
    // ／ここの既定値 の3か所に分散しているため、次元を増やすときは3か所とも揃える
    // （型注釈で守れるのは TS 側の2か所のみ）。
    const applySavedConditions = (conditions: Partial<ProjectSearchConditions>) => {
        const defaults: ProjectSearchConditions = {
            status: [],
            work_style: [],
            commercial_flow: [],
            interview_count: [],
            keyword: '',
            sort: sortOptions[0].sort as ProjectFilters['sort'],
            order: sortOptions[0].order as ProjectFilters['order'],
        };
        onFilterChange({ ...defaults, ...conditions });
    };

    const [showSaveModal, setShowSaveModal] = useState(false);
    const [showManageModal, setShowManageModal] = useState(false);

    const hasAnyFilter =
        filters.status.length > 0 ||
        filters.work_style.length > 0 ||
        filters.commercial_flow.length > 0 ||
        filters.interview_count.length > 0 ||
        filters.keyword.length > 0;

    // 左右パディングはページヘッダ（px-10）に合わせる。カード一覧はスクロール領域の px-6 に
    // スクロールバー幅が加わって実質同じ位置になるため、px-6 のままだとこの行だけ左右にはみ出して見える。
    return (
        <div className="border-b border-border bg-muted/40 px-10 py-3">
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

                {/* 「条件を保存」「すべてクリア」はどちらも左の絞り込みタグ（＝現在の条件）に作用するため、
                    作用対象の隣にまとめる。どちらも hasAnyFilter 依存なので同時に出没し、
                    常時表示の右クラスタ（保存済み条件の呼び出し・管理）が横にズレない。
                    並び順は建設的な「保存」を先、破壊的な「すべてクリア」を後にする。 */}
                {hasAnyFilter && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="ml-1 h-7 gap-1 bg-white text-[11px] text-muted-foreground hover:bg-muted/50 hover:text-foreground [&_svg]:size-3.5"
                        onClick={() => setShowSaveModal(true)}
                    >
                        <Star />
                        条件を保存
                    </Button>
                )}

                {hasAnyFilter && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-7 gap-1 bg-white text-[11px] text-muted-foreground hover:bg-muted/50 hover:text-foreground [&_svg]:size-3.5"
                        onClick={onClearAll}
                    >
                        <X />
                        すべてクリア
                    </Button>
                )}

                {/* 保存済み条件の呼び出し・管理（WF_06の配置に合わせて右端）。
                    「保存済み条件」の見出しラベルは置かない。呼び出し・管理のどちらもボタン文言だけで
                    対象と操作が分かるため、ラベルは情報を足さずクラスタの幅だけを占める。 */}
                <div className="ml-auto flex items-center gap-2">
                    <SavedSearchMenu
                        savedSearches={savedSearches}
                        onApply={applySavedConditions}
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8 gap-1 bg-white text-[11px] [&_svg]:size-3.5"
                        onClick={() => setShowManageModal(true)}
                    >
                        <List />
                        条件管理
                    </Button>
                </div>
            </div>

            <SavedSearchSaveDialog
                open={showSaveModal}
                searchType="project"
                currentConditions={currentConditions}
                activeTagLabels={activeTagLabels}
                sortLabel={sortLabel}
                onClose={() => setShowSaveModal(false)}
            />
            <SavedSearchManageDialog
                open={showManageModal}
                savedSearches={savedSearches}
                onClose={() => setShowManageModal(false)}
            />
        </div>
    );
}
