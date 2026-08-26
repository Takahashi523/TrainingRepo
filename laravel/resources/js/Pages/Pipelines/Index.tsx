import KanbanColumn from '@/Components/Pipelines/KanbanColumn';
import PipelineDrawer from '@/Components/Pipelines/PipelineDrawer';
import PipelineFilterPanel from '@/Components/Pipelines/PipelineFilterPanel';
import PipelineFilterSummary from '@/Components/Pipelines/PipelineFilterSummary';
import PipelineTabHeader from '@/Components/Pipelines/PipelineTabHeader';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { ActiveFilters, PipelineIndexPageProps } from '@/types/pipeline';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, SlidersHorizontal } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Props = PageProps<PipelineIndexPageProps>;

const KEYWORD_DEBOUNCE_MS = 300;

type QueryPayload = {
    keyword?: string;
    user_id?: number | 'all';
    rank: string[];
    status: string[];
    sort: string;
    order: string;
};

function buildQuery(filters: ActiveFilters): QueryPayload {
    return {
        keyword: filters.keyword || undefined,
        user_id: filters.user_id ?? undefined,
        rank: filters.rank,
        status: filters.status,
        sort: filters.sort,
        order: filters.order,
    };
}

export default function Index({
    columns,
    filters,
    users,
    ranks,
    statuses,
    sortOptions,
    selectedPipeline,
    statusOptions,
}: Props) {
    const [keywordInput, setKeywordInput] = useState(filters.keyword);
    // WF_10 準拠：表示条件バーは初期状態で畳んでおく（サマリーで現在の絞り込みは把握できる）
    const [filterOpen, setFilterOpen] = useState(false);

    // 戻る/進む等で filters.keyword が変わったら入力欄を再同期
    useEffect(() => {
        setKeywordInput(filters.keyword);
    }, [filters.keyword]);

    // キーワードはデバウンスして visit
    const isInitialKeywordSync = useRef(true);
    useEffect(() => {
        if (isInitialKeywordSync.current) {
            isInitialKeywordSync.current = false;
            return;
        }
        if (keywordInput === filters.keyword) return;
        const timer = setTimeout(() => {
            visit({ keyword: keywordInput });
        }, KEYWORD_DEBOUNCE_MS);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [keywordInput]);

    // 常に最新の filters を指す ref。
    // キーワードのデバウンス visit はタイマー設定時点の filters をクロージャに閉じ込めるため、
    // 「すべてクリア」直後に古いタイマーが発火すると空にしたはずの条件を古い値で再適用してしまう。
    // visit を ref 経由の最新 filters にマージすることでこの競合（stale closure）を根治する。
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    const visit = (patch: Partial<ActiveFilters>) => {
        const next: ActiveFilters = { ...filtersRef.current, ...patch };
        router.get(route('pipelines.index'), buildQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const handleClearAll = () => {
        setKeywordInput('');
        visit({ keyword: '', user_id: null, rank: [], status: [] });
    };

    // カードクリック：現在のフィルタを載せて詳細のみ部分リロード
    const openCard = (id: number) => {
        router.get(route('pipelines.show', id), buildQuery(filters), {
            preserveState: true,
            preserveScroll: true,
            only: ['selectedPipeline', 'statusOptions'],
        });
    };

    // ドロワーを閉じる：selectedPipeline を落とすため index へ戻る
    const closeDrawer = () => {
        router.get(route('pipelines.index'), buildQuery(filters), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const activeCount = columns.reduce((sum, c) => sum + c.count, 0);
    const detail = selectedPipeline ?? null;
    const drawerStatusOptions = statusOptions ?? [];

    return (
        <AuthenticatedLayout>
            <Head title="進捗管理" />

            {/*
             * ページ全体を p-6 打ち消し（-m-6）＋ 画面全高（h-screen）の relative コンテナにする。
             * - ヘッダ・タブ・フィルタは shrink-0 で常時固定（フレックスにより上部に固定）
             * - カンバンは flex-1 で残り高さを占有し内部スクロール
             * - ドロワーはこのコンテナ直下に置き、ヘッダーを含む画面トップから全高でオーバーレイする
             */}
            <div className="relative -m-6 flex h-screen flex-col overflow-hidden">
                {/* ヘッダ・タブ・フィルタ（常時固定） */}
                <div className="z-10 shrink-0 bg-white">
                    {/* ページヘッダ */}
                    <div className="border-b border-border px-10 py-4">
                        <h1 className="text-lg font-bold text-foreground">進捗管理</h1>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            人材 × 案件の提案〜参画までのステータスを管理します
                        </p>
                    </div>

                    {/* タブ */}
                    <PipelineTabHeader active="active" activeCount={activeCount} />

                    {/* 折りたたみバー */}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setFilterOpen((v) => !v)}
                        className="flex h-auto w-full items-center justify-start gap-2.5 rounded-none border-b border-border bg-muted/60 px-6 py-2 text-left hover:bg-muted [&_svg]:size-3.5"
                    >
                        {/* WF_10 準拠：ラベル左の表示条件アイコン */}
                        <SlidersHorizontal className="shrink-0 text-muted-foreground" />
                        <span className="shrink-0 text-xs font-semibold text-muted-foreground">表示条件を指定</span>
                        {/* 適用条件サマリー（折りたたみ時も含め常時表示・レビュー指摘 #10） */}
                        <PipelineFilterSummary filters={filters} users={users} ranks={ranks} />
                        <span className="ml-auto shrink-0 text-muted-foreground">
                            {filterOpen ? <ChevronUp /> : <ChevronDown />}
                        </span>
                    </Button>

                    {filterOpen && (
                        <PipelineFilterPanel
                            filters={filters}
                            users={users}
                            ranks={ranks}
                            statuses={statuses}
                            count={activeCount}
                            sortOptions={sortOptions}
                            keywordInput={keywordInput}
                            onKeywordInput={setKeywordInput}
                            onFilterChange={(patch) => visit(patch)}
                            onClearAll={handleClearAll}
                        />
                    )}
                </div>

                {/* カンバン本体（残り高さを占有・内部スクロール） */}
                <div className="flex-1 overflow-hidden bg-muted/30">
                    <div className="flex h-full overflow-x-auto">
                        {columns.map((column) => (
                            <KanbanColumn
                                key={column.key}
                                column={column}
                                statuses={statuses}
                                statusOptions={drawerStatusOptions}
                                activeId={detail?.id ?? null}
                                onOpenCard={openCard}
                            />
                        ))}
                    </div>
                </div>

                {/* ドロワー：コンテナ直下に置きヘッダーを含む画面トップから全高でオーバーレイ */}
                {detail && (
                    <PipelineDrawer
                        key={detail.id}
                        pipeline={detail}
                        statusOptions={drawerStatusOptions}
                        onClose={closeDrawer}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
