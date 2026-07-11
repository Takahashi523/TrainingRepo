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
import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Props = PageProps<PipelineCompletedPageProps>;

const KEYWORD_DEBOUNCE_MS = 300;

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

/** 終了日（datetime）を日本語日付に整形。null は「—」。 */
function formatEndedAt(value: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('ja-JP', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

export default function Completed({ pipelines, filters, users, statuses, sortOptions, auth }: Props) {
    const isAdmin = auth.user.role === 'admin';
    const [keywordInput, setKeywordInput] = useState(filters.keyword);

    useEffect(() => {
        setKeywordInput(filters.keyword);
    }, [filters.keyword]);

    const isInitialKeywordSync = useRef(true);
    useEffect(() => {
        if (isInitialKeywordSync.current) {
            isInitialKeywordSync.current = false;
            return;
        }
        if (keywordInput === filters.keyword) return;
        const timer = setTimeout(() => {
            visit({ keyword: keywordInput }, 1);
        }, KEYWORD_DEBOUNCE_MS);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [keywordInput]);

    // 常に最新の filters を指す ref（Index.tsx と同じ stale closure 対策）。
    // キーワードのデバウンス visit が古い filters を再適用し「すべてクリア」で条件が復活する競合を防ぐ。
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    const visit = (patch: Partial<CompletedFilters>, page: number) => {
        const next: CompletedFilters = { ...filtersRef.current, ...patch };
        router.get(route('pipelines.completed'), buildQuery(next, page), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const handleClearAll = () => {
        setKeywordInput('');
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

    return (
        <AuthenticatedLayout>
            <Head title="進捗管理（完了済み）" />

            {/*
             * 進行中タブ（Index.tsx）と同様に -m-6 で <main> の p-6 を打ち消し、画面全高の flex カラムにする。
             * これがないとテーブル領域の flex-1 が効かず、下部が <main> の白背景（bg-background）のまま
             * 残ってしまう（muted 背景が最下部まで伸びない）。
             */}
            <div className="-m-6 flex h-screen flex-col overflow-hidden">
                {/* ヘッダ・タブ・フィルタ（常時固定） */}
                <div className="shrink-0 bg-white">
                    {/* ページヘッダ */}
                    <div className="border-b border-border px-10 py-4">
                        <h1 className="text-lg font-bold text-foreground">進捗管理</h1>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            終了したパイプラインの一覧・検索
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
                        onFilterChange={(patch) => visit(patch, 1)}
                        onClearAll={handleClearAll}
                    />
                </div>

                {/* テーブル本体（残り高さを占有・内部スクロール・muted 背景を最下部まで） */}
                <div className="flex-1 overflow-y-auto bg-muted/60 px-6 py-5">
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
                                            <TruncatedText as="div" text={row.project.client_name} />
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
                                            <TruncatedText as="div" text={row.engineer.main_user?.name ?? '未割当'} />
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-muted-foreground">
                                            {formatEndedAt(row.ended_at)}
                                        </TableCell>
                                        <TableCell className="px-3 py-2.5 text-[11px] text-muted-foreground">
                                            <TruncatedText as="div" text={row.ng_reason ?? '—'} />
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
