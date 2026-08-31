import ActiveTag from '@/Components/Common/ActiveTag';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import SavedSearchManageDialog from '@/Components/Common/SavedSearchManageDialog';
import SavedSearchMenu from '@/Components/Common/SavedSearchMenu';
import SavedSearchSaveDialog from '@/Components/Common/SavedSearchSaveDialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { EngineerFilters, EngineerSearchConditions, Phase, StatusOption, WorkTypeOption } from '@/types/engineer';
import { SavedSearchItem } from '@/types/savedSearch';
import { SortOption } from '@/types';
import { FunnelPlus, List, Search, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    filters: EngineerFilters;
    statuses: StatusOption[];
    workStyles: WorkTypeOption[];
    phases: Phase[];
    savedSearches: SavedSearchItem<EngineerSearchConditions>[];
    sortOptions: SortOption[];
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
    sortOptions,
    keywordInput,
    onKeywordInput,
    onFilterChange,
    onClearAll,
}: Props) {
    // 検索条件保存に送る「今の絞り込み状態」（page/per_page は含めない）
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
    // 既定値には型注釈を付ける。onFilterChange の引数は Partial<EngineerFilters> のため、
    // 注釈が無いとキーが1つ欠けてもコンパイルエラーにならず、次元追加時に部分適用が静かに再発する。
    // なお「フィルタ次元の一覧」は EngineerSearchConditions（型）／SavedSearchService::sanitizeConditions()
    // ／ここの既定値 の3か所に分散しているため、次元を増やすときは3か所とも揃える
    // （型注釈で守れるのは TS 側の2か所のみ）。
    const applySavedConditions = (conditions: Partial<EngineerSearchConditions>) => {
        const defaults: EngineerSearchConditions = {
            status: [],
            work_styles: [],
            phases: [],
            keyword: '',
            sort: sortOptions[0].sort as EngineerFilters['sort'],
            order: sortOptions[0].order as EngineerFilters['order'],
        };
        onFilterChange({ ...defaults, ...conditions });
    };

    const [showSaveModal, setShowSaveModal] = useState(false);
    const [showManageModal, setShowManageModal] = useState(false);

    const hasAnyFilter =
        filters.status.length > 0 ||
        filters.work_styles.length > 0 ||
        filters.phases.length > 0 ||
        filters.keyword.length > 0;

    // 左右パディングは画面共通のガター（px-10）。ページヘッダ・カード一覧と同じ左右端に揃える。
    // 3者は同じスクロール箱の中にあり、スクロールバー幅を等しく負担する（issue #82）。
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
                        {/* 漏斗＋（FunnelPlus）。呼び出し側トリガーの Filter（実体は Funnel）と対象を共有し、
                            「＋＝この絞り込みを保存済みに追加する」を表す。
                            星（お気に入り）は使わない。星は ON/OFF を持つトグルの記号だが、この操作は
                            ダイアログで名前を付けて新規レコードを作る生成操作で、点灯状態を持たない。
                            人材・案件のカード一覧の直上にあるため「この人材をお気に入り」とも誤読されうる。 */}
                        <FunnelPlus />
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

                {/* 保存済み条件の呼び出し・管理（WF_03の配置に合わせて右端）。
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
                searchType="engineer"
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
