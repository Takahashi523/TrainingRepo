import EngineerFilterPanel from '@/Components/Engineers/EngineerFilterPanel';
import EngineerCard from '@/Components/Engineers/EngineerCard';
import Pagination from '@/Components/Common/Pagination';
import SortSelect from '@/Components/Common/SortSelect';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerFilters, EngineerListPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Props = PageProps<EngineerListPageProps>;

const KEYWORD_DEBOUNCE_MS = 300;

type QueryPayload = {
    status: string[];
    work_styles: string[];
    phases: string[];
    keyword?: string;
    sort: string;
    order: string;
    page: number;
    per_page: number;
};

function buildQuery(filters: EngineerFilters): QueryPayload {
    return {
        status:      filters.status,
        work_styles: filters.work_styles,
        phases:      filters.phases,
        keyword:     filters.keyword || undefined,
        sort:        filters.sort,
        order:       filters.order,
        page:        filters.page,
        per_page:    filters.per_page,
    };
}

export default function Index({
    engineers,
    filters,
    statusOptions,
    workStyleOptions,
    phaseOptions,
    sortOptions,
}: Props) {
    // フリーワード入力はデバウンスのために state を分離。サーバ側 filters.keyword と切り離す。
    const [keywordInput, setKeywordInput] = useState(filters.keyword);

    // Props の filters.keyword が変わったら state を再同期（戻る/進むや外部からの遷移対応）
    useEffect(() => {
        setKeywordInput(filters.keyword);
    }, [filters.keyword]);

    // デバウンス：keywordInput が変わったら 300ms 待って visit
    const isInitialKeywordSync = useRef(true);
    useEffect(() => {
        if (isInitialKeywordSync.current) {
            isInitialKeywordSync.current = false;
            return;
        }
        if (keywordInput === filters.keyword) return;
        const timer = setTimeout(() => {
            visit({ keyword: keywordInput, page: 1 });
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

    const visit = (patch: Partial<EngineerFilters>) => {
        const next: EngineerFilters = { ...filtersRef.current, ...patch };
        router.get('/engineers', buildQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const handleFilterChange = (patch: Partial<EngineerFilters>) => {
        // フィルタ変更時はページを1に戻す
        visit({ ...patch, page: 1 });
    };

    const handleClearAll = () => {
        setKeywordInput('');
        visit({
            status:      [],
            work_styles: [],
            phases:      [],
            keyword:     '',
            page:        1,
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="人材一覧" />
            {/*
             * 進捗管理・完了済みタブと同様に -m-6 で <main> の p-6 を打ち消し、画面全高の flex カラムにする。
             * ヘッダ＋フィルタパネルを shrink-0 で固定し、カード一覧領域だけを flex-1 でスクロールさせる。
             */}
            <div className="-m-6 flex h-screen flex-col overflow-hidden">
                {/* 固定領域：ページヘッダ＋検索条件フィルタパネル */}
                <div className="shrink-0 bg-white">
                    <div className="flex items-center justify-between border-b border-border px-10 py-4">
                        <div>
                            <h1 className="text-lg font-bold text-foreground">人材一覧</h1>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                登録人材の検索・絞り込みと詳細確認
                            </p>
                        </div>
                        <div>
                            <Button onClick={() => router.get('/engineers/create')}>
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                新規人材登録
                            </Button>
                        </div>
                    </div>

                    <EngineerFilterPanel
                        filters={filters}
                        statuses={statusOptions}
                        workStyles={workStyleOptions}
                        phases={phaseOptions}
                        keywordInput={keywordInput}
                        onKeywordInput={setKeywordInput}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                    />
                </div>

                {/* スクロール領域：件数＋ソート / カード一覧 / ページネーション */}
                <div className="flex-1 overflow-y-auto bg-muted/30 px-6 py-4">
                    <div className="mb-3 flex items-center text-xs text-muted-foreground">
                        <span>
                            <strong className="text-foreground">{engineers.meta.total}</strong> 件の人材が見つかりました
                        </span>
                        <div className="ml-auto">
                            <SortSelect
                                options={sortOptions}
                                currentSort={filters.sort}
                                currentOrder={filters.order}
                                onChange={(sort, order) =>
                                    handleFilterChange({
                                        sort: sort as EngineerFilters['sort'],
                                        order: order as EngineerFilters['order'],
                                    })
                                }
                            />
                        </div>
                    </div>

                    {engineers.data.length === 0 ? (
                        <div className="rounded-md border border-dashed border-border bg-white py-12 text-center text-sm text-muted-foreground">
                            該当する人材はいません
                        </div>
                    ) : (
                        engineers.data.map((e) => <EngineerCard key={e.id} engineer={e} />)
                    )}

                    <Pagination
                        meta={engineers.meta}
                        onChange={(page) => visit({ page })}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
