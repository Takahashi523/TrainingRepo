import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
import EngineerFilterPanel from '@/Components/Engineers/EngineerFilterPanel';
import EngineerCard from '@/Components/Engineers/EngineerCard';
import Pagination from '@/Components/Common/Pagination';
import SortSelect from '@/Components/Common/SortSelect';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerFilters, EngineerListPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { useKeywordDebounce } from '@/hooks/use-keyword-debounce';
import { useScrollContainer } from '@/hooks/use-scroll-container';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';

type Props = PageProps<EngineerListPageProps>;

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
    savedSearches,
}: Props) {
    // フリーワード入力（入力欄 state・props 追従・デバウンス）は共通フックに集約している。
    // 「すべてクリア」「保存済み条件の適用」と保留デバウンスの競合対策も含む（issue #38）。
    const { keywordInput, setKeywordInput, applyKeyword } = useKeywordDebounce({
        appliedKeyword: filters.keyword,
        onDebounced: (keyword) => visit({ keyword, page: 1 }),
    });

    // 常に最新の filters を指す ref。
    // キーワードのデバウンス visit はタイマー設定時点の filters をクロージャに閉じ込めるため、
    // 「すべてクリア」直後に古いタイマーが発火すると空にしたはずの条件を古い値で再適用してしまう。
    // visit を ref 経由の最新 filters にマージすることでこの競合（stale closure）を根治する。
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    // 一覧のスクロール境界（AuthenticatedLayout の <main>）。ref をレイアウトへ渡して掴む。
    const { scrollContainerRef, scrollToTop } = useScrollContainer();

    // visit は「結果セットが総入れ替わる操作」専用の経路（ページ送り・絞り込み・デバウンス・
    // ソート・すべてクリア）なので、成功時は必ず一覧の先頭へ戻す（issue #107）。
    // ページ送りだけを対象にしない理由：検索条件パネルは sticky top-0 で常に画面上に留まるため、
    // 一覧の途中までスクロールした状態でも条件を変更できる。そのとき位置を保つと「別の結果セットの
    // 途中から表示される」＝ページ送りで直したのと同じ症状になる。保たれた位置は、消えた結果
    // セットの中の位置であって意味を持たない。
    //
    // preserveScroll: true は外さない。スクロール境界が <main> 側にあり、Inertia のリセットは
    // window と scroll-region 属性の要素しか対象にしないため、付けても外しても <main> は動かない
    // （＝位置を決めているのは下の scrollToTop だけ）。
    // 成功時のみ戻す（onFinish だと失敗・キャンセルでも画面が飛ぶ）。
    const visit = (patch: Partial<EngineerFilters>) => {
        const next: EngineerFilters = { ...filtersRef.current, ...patch };
        router.get('/engineers', buildQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: scrollToTop,
        });
    };

    const handleFilterChange = (patch: Partial<EngineerFilters>) => {
        // keyword を明示指定する patch（保存済み条件の適用・キーワード条件タグの ✕ など）は、入力欄の値を合わせつつ
        // 保留中のデバウンスを無効化する。無効化しないと、打鍵から 300ms 以内に条件を呼び出したとき
        // 保留タイマーが適用を追い越して発火し、「保存条件＋打鍵途中の語」になる。
        if (patch.keyword !== undefined) {
            applyKeyword(patch.keyword);
        }
        // フィルタ変更時はページを1に戻す
        visit({ ...patch, page: 1 });
    };

    const handleClearAll = () => {
        // 保留中のデバウンスを無効化し、クリアを下の visit 1回に集約する。
        applyKeyword('');
        visit({
            status:      [],
            work_styles: [],
            phases:      [],
            keyword:     '',
            page:        1,
        });
    };

    // マッチング実行は遷移先描画前にサーバーで Python AI を同期呼び出しするため数秒待つ。
    // 一覧はカードが複数あるため、オーバーレイ・キャンセルトークンはページ単位で1つだけ持ち（9-5）、
    // 押下されたカードからこのハンドラを起動する。人材詳細（Show.tsx 5-13）と同じ配線パターン。
    const [isMatching, setIsMatching] = useState(false);
    // マッチングは読み取り専用（DB保存なし）のため途中キャンセルは安全。visit の cancel トークンを保持する。
    const matchingCancel = useRef<(() => void) | null>(null);

    const handleMatch = (engineerId: number) => {
        // ルートは人材詳細と統一（`/engineers/{id}/matching`＝engineers.matching）。
        // 旧 EngineerCard の `/matching/{id}` は未定義ルートで 404 になっていたため廃止（9-6）。
        router.get(
            `/engineers/${engineerId}/matching`,
            {},
            {
                onStart: () => setIsMatching(true),
                onCancelToken: (token) => {
                    matchingCancel.current = token.cancel;
                },
                // 通信断（サーバーに到達できない失敗）は onError では拾えないため、レイアウトの
                // useConnectionErrorToast()（exception 購読）で通知する（#84）。到達済みのエンジン通信失敗はサーバーが
                // flash.error で通知する。
                // onFinish は成功・失敗・キャンセルすべてで発火するためオーバーレイは必ず解除される。
                onFinish: () => {
                    setIsMatching(false);
                    matchingCancel.current = null;
                },
            },
        );
    };

    // 地色（bg-muted/30）は <main> に載せる。カード一覧側に置くと少件数・0件のときに
    // 画面下部が <main> の白背景のまま残るため。人材詳細・案件詳細と同じ使い方（issue #82）。
    return (
        <AuthenticatedLayout mainClassName="bg-muted/30" mainRef={scrollContainerRef}>
            <Head title="人材一覧" />
            {/* マッチング実行の遷移中（Python AI 同期計算）に全画面で計算中を表示する。
                共通部品の既定は汎用文言のため、ここではマッチング用途の具体文言を渡す。
                マッチングは読み取り専用でキャンセルが安全なため onCancel を渡す（visit を中断）。 */}
            <AiLoadingOverlay
                show={isMatching}
                message="AIがマッチングを計算しています…"
                onCancel={() => matchingCancel.current?.()}
            />
            {/*
             * スクロール境界は AuthenticatedLayout の <main> 1か所に一本化する（自前のスクロール箱は作らない）。
             * -m-6 は <main> 内側 div の p-6 を打ち消してフルブリードにするためだけに残す。
             *
             * ヘッダ・フィルタ行を「固定領域（スクロール箱の外）」に置くと、スクロールバー幅（約15px）を
             * カード一覧だけが内側で負担し、左右端が構造的に一致しなくなる（issue #82）。
             * 同じスクロール箱の中に入れて sticky で留めることで、3者がバー幅を等しく負担し4辺が揃う。
             */}
            <div className="-m-6">
                {/* 固定領域：ページヘッダ＋検索条件フィルタパネル。
                    bg-white は必須。フィルタパネルの外枠は bg-muted/40＝半透明で、単独では
                    背後を通過するカードが透ける（受け入れ条件「カードが背後に透けない」）。 */}
                <div className="sticky top-0 z-10 bg-white">
                    <div className="flex items-center justify-between border-b border-border px-10 py-4">
                        <div>
                            <h1 className="text-lg font-bold text-foreground">人材一覧</h1>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                登録済みの人材を検索・確認します
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
                        savedSearches={savedSearches}
                        sortOptions={sortOptions}
                        keywordInput={keywordInput}
                        onKeywordInput={setKeywordInput}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                    />
                </div>

                {/* 本文：件数＋ソート / カード一覧 / ページネーション。
                    左右ガターは固定領域（px-10）と揃える。 */}
                <div className="px-10 py-4">
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
                        engineers.data.map((e) => (
                            <EngineerCard key={e.id} engineer={e} onMatch={() => handleMatch(e.id)} />
                        ))
                    )}

                    {/* 現在ページの再クリックは Pagination 側で握り潰される（同じ内容の取り直し・
                        視点だけのジャンプを防ぐ）。ここは素直にページを差し替えるだけでよい。 */}
                    <Pagination
                        meta={engineers.meta}
                        onChange={(page) => visit({ page })}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
