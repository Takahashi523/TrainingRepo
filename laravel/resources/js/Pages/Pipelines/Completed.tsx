import CompletedFilterPanel from '@/Components/Pipelines/CompletedFilterPanel';
import PipelineTabHeader from '@/Components/Pipelines/PipelineTabHeader';
import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import SortSelect from '@/Components/Common/SortSelect';
import TruncatedText from '@/Components/Common/TruncatedText';
import PipelineStatusBadge from '@/Components/Pipelines/PipelineStatusBadge';
import Pagination from '@/Components/Common/Pagination';
import { Button } from '@/Components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { CompletedFilters, PipelineCompletedPageProps } from '@/types/pipeline';
import { emptyText } from '@/lib/emptyValue';
import { isValidYmd } from '@/lib/utils';
import { useDebouncedEffect } from '@/hooks/use-debounced-effect';
import { KEYWORD_DEBOUNCE_MS, useKeywordDebounce } from '@/hooks/use-keyword-debounce';
import { useScrollContainer } from '@/hooks/use-scroll-container';
import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Props = PageProps<PipelineCompletedPageProps>;

/** 終了日欄も打鍵ごとに発火するため、キーワードと同じ間隔でまとめてから問い合わせる（片方だけ変わらないよう定数を共有する）。 */
const DATE_DEBOUNCE_MS = KEYWORD_DEBOUNCE_MS;

/**
 * 日付入力欄の生値を絞り込み条件へ変換する。
 * - `''`（空）→ `null`：条件なし
 * - 実在する 'YYYY-MM-DD' → その値：適用する
 * - 入力途中の不完全な値（'2' / '2026-08-0'）→ `undefined`：**まだサーバーへ送らない**
 *
 * `DateInput` はテキスト欄で1打鍵ごとに途中の値を返すため、素通しでリクエストすると
 * `PipelineCompletedRequest` の `date` ルールに毎回引っかかり、1文字目でエラーが出て
 * 手入力できなくなる（旧 `type="date"` は完成値しか返さなかったため表面化していなかった）。
 *
 * 判定は開始・終了それぞれで独立して行う（`>=` と `<=` は独立した条件のため、
 * 片方が入力途中でももう片方は絞り込みに反映する）。
 */
function toFilterDate(input: string): string | null | undefined {
    if (input === '') return null;
    return isValidYmd(input) ? input : undefined;
}

type QueryPayload = {
    keyword?: string;
    status: string[];
    user_id?: number;
    ended_from?: string;
    ended_to?: string;
    sort: string;
    order: string;
    page: number;
};

function buildQuery(filters: CompletedFilters, page: number): QueryPayload {
    return {
        keyword: filters.keyword || undefined,
        status: filters.status,
        user_id: filters.user_id ?? undefined,
        ended_from: filters.ended_from ?? undefined,
        ended_to: filters.ended_to ?? undefined,
        sort: filters.sort,
        order: filters.order,
        page,
    };
}

/** 終了日（datetime）を日本語日付に整形。null は欠損語彙（未設定）。 */
function formatEndedAt(value: string | null): string {
    if (!value) return emptyText('endedAt');
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

export default function Completed({ pipelines, filters, users, statuses, sortOptions, auth }: Props) {
    const isAdmin = auth.user.role === 'admin';
    // フリーワード入力（入力欄 state・props 追従・デバウンス）は共通フックに集約している。
    // 「すべてクリア」と保留デバウンスの競合対策も含む（issue #38）。
    const { keywordInput, setKeywordInput, applyKeyword } = useKeywordDebounce({
        appliedKeyword: filters.keyword,
        onDebounced: (keyword) => visit({ keyword }, 1),
    });

    // 終了日範囲もキーワードと同じ「ローカル state で受けてからデバウンス」方式にする。
    // 加えて、日付は入力途中の値が意味を持たないため toFilterDate で完成値・空のみに絞る。
    const [endedFromInput, setEndedFromInput] = useState(filters.ended_from ?? '');
    const [endedToInput, setEndedToInput] = useState(filters.ended_to ?? '');

    // タグの ✕ や「すべてクリア」でサーバー側の条件が変わったら入力欄も追従させる。
    useEffect(() => {
        setEndedFromInput(filters.ended_from ?? '');
    }, [filters.ended_from]);
    useEffect(() => {
        setEndedToInput(filters.ended_to ?? '');
    }, [filters.ended_to]);

    // 日付もキーワードと同じ「抑止できるデバウンス」に載せる。
    // 「すべてクリア」の即時 visit を保留タイマーが追い越すと、古い filters を基点に
    // マージされて条件が復活するため（issue #38）。
    const { suppressNextRun: suppressDateDebounce } = useDebouncedEffect(
        () => {
            const from = toFilterDate(endedFromInput);
            const to = toFilterDate(endedToInput);
            // 両方とも入力途中なら送るものが無い（422 も出さない）。
            if (from === undefined && to === undefined) return;
            // 判定は発火時点の filtersRef で行う（条件タグの ✕ や「すべてクリア」が先に
            // visit を済ませている場合の重複リクエスト防止）。
            const applied = filtersRef.current;
            // 打ち終わっていて、かつ適用済みと違う欄だけを patch に載せる。
            // 開始・終了は「>=」「<=」の独立した条件なので、片方が入力途中でも
            // もう片方は絞り込みに反映する（片方だけ適用される状態は順に打てば元々起きる）。
            // 入力途中の欄は patch から外す＝その欄の適用済み条件をそのまま維持する。
            const patch: Partial<CompletedFilters> = {};
            if (from !== undefined && from !== (applied.ended_from ?? null)) {
                patch.ended_from = from;
            }
            if (to !== undefined && to !== (applied.ended_to ?? null)) {
                patch.ended_to = to;
            }
            if (Object.keys(patch).length === 0) return;
            visit(patch, 1);
        },
        [endedFromInput, endedToInput],
        DATE_DEBOUNCE_MS,
    );

    // 常に最新の filters を指す ref（Index.tsx と同じ stale closure 対策）。
    // キーワードのデバウンス visit が古い filters を再適用し「すべてクリア」で条件が復活する競合を防ぐ。
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    // テーブルのスクロール境界（AuthenticatedLayout の <main>）。ref をレイアウトへ渡して掴む。
    // 結果セットが総入れ替わる visit の成功時に先頭へ戻す（issue #107）。
    // 人材一覧 Index.tsx と同じ配線（ページ送りだけを対象にしない理由もそちらのコメントを参照）。
    const { scrollContainerRef, scrollToTop } = useScrollContainer();

    const visit = (patch: Partial<CompletedFilters>, page: number) => {
        const next: CompletedFilters = { ...filtersRef.current, ...patch };
        router.get(route('pipelines.completed'), buildQuery(next, page), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: scrollToTop,
        });
    };

    // 終了日欄の版 applyKeyword。入力欄の同期と保留デバウンスの無効化をここで一括して行い、
    // 「これから deps が取る値」の予測と実際の setState を必ず同じ式から作る
    // （予測だけを呼び出し側に書かせると、片方を直し忘れたとき抑止フラグが残って
    // 次の1打鍵ぶんのデバウンスが無言で消える）。送信しない点も applyKeyword と同じ。
    const applyEndedRange = (from: string, to: string) => {
        suppressDateDebounce([from, to]);
        setEndedFromInput(from);
        setEndedToInput(to);
    };

    // 条件タグの ✕ など、keyword / 終了日を明示指定する patch は入力欄の値を合わせつつ
    // 保留デバウンスを無効化する。無効化しないと、タグの ✕ が入力欄も空にするため
    // （CompletedFilterPanel）、✕ の visit の直後に保留タイマーが発火して同じ条件を二重に送る。
    // キーワード部分は人材一覧・案件一覧の handleFilterChange と同じ形（issue #38）。
    const handleFilterChange = (patch: Partial<CompletedFilters>) => {
        if (patch.keyword !== undefined) {
            applyKeyword(patch.keyword);
        }
        // 終了日タグの ✕ もキーワードタグと同型（入力欄クリア＋ patch）なので同じ抑止を通す。
        // patch に無い側は現在の入力途中の値を維持する（開始・終了は独立した条件のため）。
        if (patch.ended_from !== undefined || patch.ended_to !== undefined) {
            applyEndedRange(
                patch.ended_from !== undefined ? (patch.ended_from ?? '') : endedFromInput,
                patch.ended_to !== undefined ? (patch.ended_to ?? '') : endedToInput,
            );
        }
        visit(patch, 1);
    };

    const handleClearAll = () => {
        // 保留中のデバウンス（キーワード・日付）を無効化し、クリアを下の visit 1回に集約する。
        applyKeyword('');
        // 入力途中の値が残っていると props 追従では消えないため、明示的にクリアする。
        applyEndedRange('', '');
        visit({ keyword: '', status: [], user_id: null, ended_from: null, ended_to: null }, 1);
    };

    // 削除確認は共通 ConfirmDialog で行う（人材詳細の削除と統一）。対象行を保持して確認する。
    const [deleteTarget, setDeleteTarget] = useState<{ id: number; name: string } | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(route('pipelines.destroy', deleteTarget.id), {
            preserveScroll: true,
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
            onSuccess: () => setDeleteTarget(null),
        });
    };

    // テーブルの列幅（%）を固定する（table-fixed）。合計 100% になるよう admin 有無で出し分ける。
    // 列：人材名 / 顧客名 / 案件名 / ステータス / 担当営業 / 終了日 / NG理由 (/ 操作)
    const columnWidths = isAdmin
        ? [12, 15, 17, 11, 12, 9, 16, 8]
        : [13, 16, 19, 12, 13, 9, 18];

    // 地色（bg-muted/30）は <main> に載せる。テーブル領域側に置くと少件数・0件のときに
    // 画面下部が <main> の白背景（bg-background）のまま残るため（issue #82）。
    return (
        <AuthenticatedLayout mainClassName="bg-muted/30" mainRef={scrollContainerRef}>
            <Head title="進捗管理" />

            {/*
             * スクロール境界は AuthenticatedLayout の <main> 1か所に一本化する（自前のスクロール箱は作らない）。
             * -m-6 は <main> 内側 div の p-6 を打ち消してフルブリードにするためだけに残す。
             *
             * ヘッダ・タブ・フィルタを「固定領域（スクロール箱の外）」に置くと、スクロールバー幅（約15px）を
             * テーブル領域だけが内側で負担し、左右端が構造的に一致しなくなる（issue #82）。
             * 同じスクロール箱の中に入れて sticky で留めることで、4者がバー幅を等しく負担し4辺が揃う。
             *
             * ※ 進行中タブ（Index.tsx）はカンバンの列内スクロールが flex-1 の確定高さに依存するため
             *   この形にできない。あちらは固定＋スクロールの構造を維持している（理由は Index.tsx の
             *   同位置のコメントを参照）。
             */}
            <div className="-m-6">
                {/* ヘッダ・タブ・フィルタ（常時固定）。
                    bg-white は必須。フィルタパネルの外枠は bg-muted/40＝半透明で、単独では
                    背後を通過するテーブルが透ける。 */}
                <div className="sticky top-0 z-10 bg-white">
                    {/* ページヘッダ */}
                    <div className="border-b border-border px-10 py-4">
                        <h1 className="text-lg font-bold text-foreground">進捗管理</h1>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            終了したパイプラインを検索・確認します
                        </p>
                    </div>

                    {/* タブ */}
                    <PipelineTabHeader active="completed" completedCount={pipelines.meta.total} />

                    {/* フィルタ（常時表示） */}
                    <CompletedFilterPanel
                        filters={filters}
                        users={users}
                        statuses={statuses}
                        keywordInput={keywordInput}
                        onKeywordInput={setKeywordInput}
                        endedFromInput={endedFromInput}
                        onEndedFromInput={setEndedFromInput}
                        endedToInput={endedToInput}
                        onEndedToInput={setEndedToInput}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                    />
                </div>

                {/* テーブル本体。左右ガターは固定領域（px-10）と揃える。 */}
                <div className="px-10 py-5">
                {/* テーブル上部：件数（左）＋ソート（右）を同一行に配置（WF_10 準拠） */}
                <div className="mb-3 flex items-center">
                    <span className="text-xs text-muted-foreground">
                        <strong className="text-foreground">{pipelines.meta.total}</strong> 件
                    </span>
                    <div className="ml-auto">
                        <SortSelect
                            options={sortOptions}
                            currentSort={filters.sort}
                            currentOrder={filters.order}
                            onChange={(sort, order) => visit({ sort, order }, 1)}
                        />
                    </div>
                </div>

                {pipelines.data.length === 0 ? (
                    <div className="rounded-md border border-dashed border-border bg-white py-12 text-center text-sm text-muted-foreground">
                        該当するパイプラインはありません
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-md border border-border bg-white">
                        <Table className="table-fixed border-collapse text-xs">
                            <colgroup>
                                {columnWidths.map((w, i) => (
                                    <col key={i} style={{ width: `${w}%` }} />
                                ))}
                            </colgroup>
                            <TableHeader>
                                <TableRow className="bg-muted hover:bg-muted">
                                    <Th>人材名</Th>
                                    <Th>顧客名</Th>
                                    <Th>案件名</Th>
                                    <Th>ステータス</Th>
                                    <Th>担当営業</Th>
                                    <Th>終了日</Th>
                                    <Th>NG理由</Th>
                                    {/* 操作列はアイコンのみで自明なため見出しは空欄。読み上げ用にラベルだけ残す */}
                                    {isAdmin && <Th><span className="sr-only">操作</span></Th>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pipelines.data.map((row) => (
                                    <TableRow key={row.id} className="hover:bg-muted/30">
                                        <TableCell className="px-3 py-2.5 font-bold text-foreground">
                                            <TruncatedText as="div" text={row.engineer.name} />
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-muted-foreground">
                                            {/* 顧客名は nullable かつ空文字もあり得る。テーブルの固定カラムでは空セルが曖昧なため、
                                                null・空文字の両方（|| で falsy を一括判定）を NG理由と平仄を合わせ欠損語彙で表す */}
                                            <TruncatedText as="div" text={row.project.client_name || emptyText('clientName')} />
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-muted-foreground">
                                            <TruncatedText as="div" text={row.project.name} />
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5">
                                            {/* 完了済みは終了状態のため、バッジを muted-foreground の控えめ表示にする（ドロワーの foreground とは別扱い） */}
                                            <PipelineStatusBadge
                                                label={row.status_label}
                                                className="border-muted-foreground text-muted-foreground"
                                            />
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-muted-foreground">
                                            <TruncatedText as="div" text={row.engineer.main_user?.name ?? emptyText('mainUser')} />
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-muted-foreground">
                                            {formatEndedAt(row.ended_at)}
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-[11px] text-muted-foreground">
                                            {/* NG理由は nullable な自由記述。空文字も漏らさないよう || で falsy を一括判定し欠損語彙に揃える */}
                                            <TruncatedText as="div" text={row.ng_reason || emptyText('ngReason')} />
                                        </TableCell>
                                        {isAdmin && (
                                            <TableCell className="px-3 py-2.5">
                                                {/* テーブル行アクションはコンパクトなアイコンのみ（ホバー/aria-label で削除と分かる） */}
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        setDeleteTarget({ id: row.id, name: row.engineer.name })
                                                    }
                                                    className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive [&_svg]:size-3.5"
                                                    aria-label="削除"
                                                    title="削除"
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                    {/* 現在ページの再クリックは Pagination 側で握り潰される（人材一覧と同じ）。
                        条件は現状維持なので patch は空、ページだけを差し替える。 */}
                    <Pagination
                        meta={pipelines.meta}
                        onChange={(page) => visit({}, page)}
                    />
                </div>
            </div>

            {/* 削除確認（共通 ConfirmDialog。人材詳細の削除と統一） */}
            <ConfirmDialog
                open={deleteTarget !== null}
                title="このパイプラインを削除しますか？"
                description={
                    deleteTarget && (
                        <>
                            <strong>{deleteTarget.name}</strong> のパイプラインを削除します。この操作は取り消せません。
                        </>
                    )
                }
                confirmLabel="削除する"
                processingLabel="削除中..."
                processing={isDeleting}
                onConfirm={confirmDelete}
                onCancel={() => setDeleteTarget(null)}
            />
        </AuthenticatedLayout>
    );
}

// shadcn TableHead の既定（h-12・px-4・font-medium）をコンパクト表示に上書きするラッパ
function Th({ children }: { children: React.ReactNode }) {
    return (
        <TableHead className="h-auto px-3 py-2.5 text-left text-[11px] font-bold text-muted-foreground whitespace-nowrap">
            {children}
        </TableHead>
    );
}
