import ActiveTag from '@/Components/Common/ActiveTag';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import SortSelect from '@/Components/Common/SortSelect';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { ActiveFilters, RankOption, SortOption, StatusOption, UserOption } from '@/types/pipeline';
import { Search, X } from 'lucide-react';

interface Props {
    filters: ActiveFilters;
    users: UserOption[];
    ranks: RankOption[];
    statuses: StatusOption[];
    keywordInput: string;
    /** 現在の絞り込み結果の総件数（進行中カード合計）。WF_10 準拠でソート左に表示する */
    count: number;
    /** ソート選択肢（バックエンドの SORT_OPTIONS_ACTIVE から props で受け取る＝SSOT） */
    sortOptions: SortOption[];
    onKeywordInput: (value: string) => void;
    onFilterChange: (patch: Partial<ActiveFilters>) => void;
    onClearAll: () => void;
}

/**
 * 進行中タブのフィルタ内容（キーワード・担当営業・ランク・ステータス・ソート）。
 * 折りたたみバー自体は Index 側で制御し、本コンポーネントは中身を担う。
 */
export default function PipelineFilterPanel({
    filters,
    users,
    ranks,
    statuses,
    keywordInput,
    count,
    sortOptions,
    onKeywordInput,
    onFilterChange,
    onClearAll,
}: Props) {
    // ドロップダウンの選択肢は WF_10 に倣いスコア範囲を併記する（例: "A（80点以上）"）。
    // 適用条件チップ／サマリーは短い label（"A"）のままとし、冗長化を避ける。
    const rankOptions: MultiSelectOption[] = ranks.map((r) => ({
        value: r.value,
        label: `${r.label}（${r.range}）`,
    }));
    const statusOptions: MultiSelectOption[] = statuses.map((s) => ({ value: s.value, label: s.label }));

    const rankLabel = (v: string) => ranks.find((r) => r.value === v)?.label ?? v;
    const statusLabel = (v: string) => statuses.find((s) => s.value === v)?.label ?? v;
    const userLabel = (v: number ) => users.find((u) => u.id === v)?.name ?? `ID:${v}`;

    const hasAnyFilter =
        filters.keyword.length > 0 ||
        filters.user_id != null ||
        filters.rank.length > 0 ||
        filters.status.length > 0;

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
                        placeholder="人材名・案件名で検索"
                        className="h-8 w-[220px] bg-white pl-8 pr-2 text-xs md:text-xs"
                    />
                </div>

                <span className="mx-1 h-5 w-px bg-border" />

                {/* 担当営業（自担当＝デフォルト / 全担当 / 個別指定）。
                    プレフィックスを外し、代わりに手前のラベルでフィルタ種別を示す。
                    コンポーネント設計書 §1-3・進捗管理セクションに従い shadcn Select を使用する。 */}
                <label className="flex items-center gap-1.5">
                    <span className="shrink-0 text-[11px] font-semibold text-muted-foreground">担当営業</span>
                    {/* 氏名は最大255文字になり得るため w-[220px] 固定でトリガーの伸長を抑える（レビュー指摘 #9）。
                        長い氏名はトリガー内で line-clamp-1（shadcn 既定）により省略表示される。 */}
                    <Select
                        value={filters.user_id == null ? 'mine' : String(filters.user_id)}
                        onValueChange={(v) =>
                            onFilterChange({
                                user_id: v === 'mine' ? null : v === 'all' ? 'all' : Number(v),
                            })
                        }
                    >
                        <SelectTrigger className="h-8 w-[220px] bg-white text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        {/* 氏名は最大255文字になり得るため、項目側も max-w＋truncate で
                            ドロップダウンが横に突き抜けないよう制約する（レビュー指摘 #9） */}
                        <SelectContent className="max-w-[260px]">
                            <SelectItem value="mine" className="text-xs">
                                自担当（サブ含む）
                            </SelectItem>
                            <SelectItem value="all" className="text-xs">
                                全担当（絞り込みなし）
                            </SelectItem>
                            {users.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)} className="text-xs">
                                    {u.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </label>

                <MultiSelectDropdown
                    label="ランク"
                    options={rankOptions}
                    selected={filters.rank}
                    onChange={(next) => onFilterChange({ rank: next })}
                />

                <MultiSelectDropdown
                    label="ステータス"
                    options={statusOptions}
                    selected={filters.status}
                    onChange={(next) => onFilterChange({ status: next })}
                />
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <span className="text-[11px] text-muted-foreground">絞り込み条件：</span>

                {/*
                 * 担当スコープは常に明示する。デフォルト（自担当）は「絞り込みなし」ではなく
                 * 実際に自担当カードのみを表示しているため、解除不可の静的バッジで示す。
                 * 「全担当」／個人指定はデフォルトからの上書きなので、×で自担当に戻せるタグにする。
                 */}
                {filters.user_id == null ? (
                    <span className="inline-flex items-center rounded-full border border-border bg-muted px-2.5 py-0.5 text-[11px] text-muted-foreground">
                        自担当（サブ含む）
                    </span>
                ) : (
                    <ActiveTag
                        label={filters.user_id === 'all' ? '全担当（絞り込みなし）' : `担当：${userLabel(filters.user_id)}`}
                        onRemove={() => onFilterChange({ user_id: null })}
                    />
                )}

                {filters.keyword && (
                    <ActiveTag
                        label={`"${filters.keyword}"`}
                        onRemove={() => {
                            onKeywordInput('');
                            onFilterChange({ keyword: '' });
                        }}
                    />
                )}
                {filters.rank.map((v) => (
                    <ActiveTag
                        key={`r-${v}`}
                        label={`ランク：${rankLabel(v)}`}
                        onRemove={() => onFilterChange({ rank: filters.rank.filter((x) => x !== v) })}
                    />
                ))}
                {filters.status.map((v) => (
                    <ActiveTag
                        key={`st-${v}`}
                        label={statusLabel(v)}
                        onRemove={() => onFilterChange({ status: filters.status.filter((x) => x !== v) })}
                    />
                ))}

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

            {/*
             * ソートは WF_10 準拠でフィルタエリア下部の独立バー（上境界線・右寄せ）に配置する。
             * 絞り込み条件タグ行と同居させず、区切って表示する。
             */}
            <div className="mt-2 flex items-center border-t border-border pt-2">
                {/* WF_10 準拠：ソート左に絞り込み結果の件数を表示 */}
                <span className="text-xs text-muted-foreground">
                    <strong className="text-sm font-bold text-foreground">{count}</strong> 件
                </span>
                <div className="ml-auto">
                    <SortSelect
                        options={sortOptions}
                        currentSort={filters.sort}
                        currentOrder={filters.order}
                        onChange={(sort, order) => onFilterChange({ sort, order })}
                    />
                </div>
            </div>
        </div>
    );
}
