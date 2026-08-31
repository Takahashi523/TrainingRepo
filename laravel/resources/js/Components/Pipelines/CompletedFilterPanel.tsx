import ActiveTag from '@/Components/Common/ActiveTag';
import DateInput from '@/Components/Common/DateInput';
import MultiSelectDropdown, { MultiSelectOption } from '@/Components/Common/MultiSelectDropdown';
import TruncatedSelectTrigger, {
    SELECT_CONTENT_MATCH_TRIGGER_CLASS,
} from '@/Components/Common/TruncatedSelectTrigger';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem } from '@/Components/ui/select';
import { isValidYmd } from '@/lib/utils';
import { CompletedFilters, StatusOption, UserOption } from '@/types/pipeline';
import { usePage } from '@inertiajs/react';
import { Search, X } from 'lucide-react';

/**
 * 8桁そろっているのに実在しない日付（2026-13-09 / 2026-02-30 等）なら true。
 *
 * 完成値だけをサーバーへ送るゲートを入れた結果、実在しない日付は「送られず何も起きない」
 * ＝ Silent Rejection になり得る。そこでこのケースだけ画面で指摘する。
 * 入力途中（桁が足りない状態）は対象外＝打鍵のたびに叱らない。
 */
function isCompleteButInvalidDate(value: string): boolean {
    return /^\d{4}-\d{2}-\d{2}$/.test(value) && !isValidYmd(value);
}

interface Props {
    filters: CompletedFilters;
    users: UserOption[];
    statuses: StatusOption[];
    keywordInput: string;
    onKeywordInput: (value: string) => void;
    /**
     * 終了日はキーワードと同じく「打鍵をローカル state で受け、親がデバウンスしてから絞り込む」。
     * `DateInput` は入力途中の不完全な値も返すため、`filters` を直接 value に束縛すると
     * 1文字目でサーバー検証に弾かれて手入力できなくなる（親側 `toFilterDate` でゲートする）。
     */
    endedFromInput: string;
    onEndedFromInput: (value: string) => void;
    endedToInput: string;
    onEndedToInput: (value: string) => void;
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
    endedFromInput,
    onEndedFromInput,
    endedToInput,
    onEndedToInput,
    onFilterChange,
    onClearAll,
}: Props) {
    const statusOptions: MultiSelectOption[] = statuses.map((s) => ({ value: s.value, label: s.label }));

    // 終了日範囲のバリデーションエラー（終了 < 開始）。エラー時はサーバーが直前の
    // 条件のまま差し戻すため、メッセージを出さないと「選んだのに反映されない」Silent Rejection になる。
    // 不正な日付そのもの（実在しない日・入力途中）は親側で送信前にゲートするため、ここへは届かない。
    const { errors } = usePage().props;
    const dateRangeError = errors.ended_from ?? errors.ended_to;

    // 実在しない日付は送信ゲートで止まりサーバーエラーにもならないため、ここで理由を示す。
    // 文面はサーバー側（lang/ja/validation.php の date）と同じ言い回しに揃える。どちらの欄が
    // 対象かはクリアボタンのラベルが名指しするので、ここでは項目名を繰り返さない。
    const invalidDateHint =
        isCompleteButInvalidDate(endedFromInput) || isCompleteButInvalidDate(endedToInput)
            ? '正しい日付を入力してください'
            : null;
    const dateMessage = dateRangeError ?? invalidDateHint;

    // 指摘対象＝「入力してあるが絞り込みに反映されていない」欄。実在しない日付（送信ゲートで
    // 止まる）も範囲逆転（サーバーが 422 で差し戻す）も、この一つの条件で表せる。
    const isFromUnapplied = endedFromInput !== (filters.ended_from ?? '');
    const isToUnapplied = endedToInput !== (filters.ended_to ?? '');

    // 反映されていない入力は条件タグの ✕ が出ないため、放っておくと「すべてクリア」以外に
    // 消す手段が無く、他の条件（ステータス等）まで巻き添えにしてしまう。
    // そこで該当する欄だけを空にする導線をメッセージの横に置く。
    // メッセージが出ている時だけ表示するので、入力途中（指摘していない状態）では現れない。
    // ラベルは消える欄を名指しする。「この日付」ではどちらの欄が対象か推測に委ねることになり、
    // 2欄が対象のときは単数形が実際の挙動と食い違うため。表記は入力欄の aria-label に揃える
    // （この画面に「開始日」という項目は無く、あくまで「終了日」の開始側・終了側のため）。
    const clearDateLabel = isFromUnapplied
        ? isToUnapplied
            ? '終了日（両方）をクリア'
            : '終了日（開始）をクリア'
        : '終了日（終了）をクリア';

    const clearUnappliedDates = () => {
        if (isFromUnapplied) onEndedFromInput('');
        if (isToUnapplied) onEndedToInput('');
        // サーバー由来のエラーは次のリクエストまで props に残る。空にしても送信対象が
        // 無ければ再取得が起きず赤字が残るため、ここで明示的に取り直す。
        if (dateRangeError) onFilterChange({});
    };

    const statusLabel = (v: string) => statuses.find((s) => s.value === v)?.label ?? v;
    const userLabel = (id: number) => users.find((u) => u.id === id)?.name ?? `ID:${id}`;

    // トリガーに出す選択中ラベル。TruncatedSelectTrigger は SelectValue に children を渡す方式のため、
    // 「いま何が選ばれているか」の解決は呼び出し側が持つ（Radix による ItemText 複製を止めるのが目的）。
    const selectedUserLabel =
        filters.user_id == null ? '全担当（絞り込みなし）' : userLabel(filters.user_id);

    // 実際にサーバーへ適用されている条件があるか。タグ・「適用中の条件はありません」・
    // 「すべてクリア」の表示判定に使う。
    // 反映されていない入力は「すべてクリア」ではなくメッセージ横の「この日付をクリア」で
    // 消す（適用中の条件が無い場面で両方出すと、同じ結果になるボタンが2つ並ぶため）。
    const hasAppliedFilter =
        filters.keyword.length > 0 ||
        filters.status.length > 0 ||
        filters.user_id != null ||
        !!filters.ended_from ||
        !!filters.ended_to;

    // 左右パディングは一覧系共通のガター（px-10）に合わせる（issue #82）。
    return (
        <div className="border-b border-border bg-muted/40 px-10 py-3">
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
                        {/* 選択中の氏名の省略は TruncatedSelectTrigger に任せる（shadcn 既定の
                            line-clamp-1 では省略された氏名を確認する手段が無いため。#40） */}
                        <TruncatedSelectTrigger
                            className="h-8 w-[200px] bg-white text-xs"
                            label={selectedUserLabel}
                        />
                        {/* 氏名は最大255文字になり得るため max-w で突き抜けを防止する（レビュー指摘 #9）。
                            項目側は省略せず折り返して全文を見せる。開いている間だけの一時表示で、
                            幅が広がっても他の操作要素を巻き込まないため（保存済み条件メニューと同じ判断。#40）。
                            幅の下限はトリガーに合わせる（max-w だけだとトリガーより狭くなり不揃いになる）。 */}
                        <SelectContent
                            className={`max-w-[260px] ${SELECT_CONTENT_MATCH_TRIGGER_CLASS}`}
                        >
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
                    label="ステータス"
                    options={statusOptions}
                    selected={filters.status}
                    onChange={(next) => onFilterChange({ status: next })}
                />

                {/* 終了日範囲 */}
                <div className="flex items-center gap-1.5">
                    <span className="shrink-0 text-[11px] font-semibold text-muted-foreground">終了日</span>
                    <DateInput
                        value={endedFromInput}
                        onChange={onEndedFromInput}
                        className="h-8 w-auto bg-white px-2 text-xs md:text-xs"
                        aria-label="終了日（開始）"
                    />
                    <span className="text-[11px] text-muted-foreground">〜</span>
                    <DateInput
                        value={endedToInput}
                        onChange={onEndedToInput}
                        className="h-8 w-auto bg-white px-2 text-xs md:text-xs"
                        aria-label="終了日（終了）"
                    />
                </div>
            </div>

            {dateMessage && (
                <p className="mt-1 flex items-center gap-2 text-[11px] text-destructive">
                    {dateMessage}
                    {/* ✕ 単独にはしない（エラー文の横だと「警告を閉じる」と誤読され、
                        入力を捨てたことに気づけないため）。意味はラベルで担保する。
                        消すのは指摘対象の日付欄だけなので、「入力をクリア」のように
                        他の絞り込みまで消すと読める文言は避け、対象の欄を名指しする。 */}
                    {(isFromUnapplied || isToUnapplied) && (
                        <button
                            type="button"
                            onClick={clearUnappliedDates}
                            className="group inline-flex items-center gap-1 rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-3"
                        >
                            <X />
                            <span className="underline underline-offset-2 group-hover:no-underline">
                                {clearDateLabel}
                            </span>
                        </button>
                    )}
                </p>
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
                        onRemove={() => {
                            onEndedFromInput('');
                            onFilterChange({ ended_from: null });
                        }}
                    />
                )}
                {filters.ended_to && (
                    <ActiveTag
                        label={`終了日 <= ${filters.ended_to}`}
                        onRemove={() => {
                            onEndedToInput('');
                            onFilterChange({ ended_to: null });
                        }}
                    />
                )}
                {!hasAppliedFilter && (
                    <span className="text-[11px] text-muted-foreground">（適用中の条件はありません）</span>
                )}

                {hasAppliedFilter && (
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
