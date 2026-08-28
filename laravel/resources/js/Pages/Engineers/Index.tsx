import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
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
    savedSearches,
}: Props) {
    // フリーワード入力はデバウンスのために state を分離。サーバ側 filters.keyword と切り離す。
    const [keywordInput, setKeywordInput] = useState(filters.keyword);

    // Props の filters.keyword が変わったら state を再同期（戻る/進むや外部からの遷移対応）
    useEffect(() => {
        setKeywordInput(filters.keyword);
    }, [filters.keyword]);

    // 保存済み条件の適用のように、keyword を含む visit を別経路で送った直後は、
    // 入力欄の同期（setKeywordInput）で走る本 effect が二重に visit を出さないよう1回だけ抑止する。
    const skipNextKeywordDebounce = useRef(false);

    // デバウンス：keywordInput が変わったら 300ms 待って visit
    const isInitialKeywordSync = useRef(true);
    useEffect(() => {
        if (isInitialKeywordSync.current) {
            isInitialKeywordSync.current = false;
            return;
        }
        if (skipNextKeywordDebounce.current) {
            skipNextKeywordDebounce.current = false;
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
        // keyword を明示指定する patch（＝保存済み条件の適用）は、入力欄の値も保存条件に合わせる。
        // 同期しないと、打鍵から 300ms 以内に条件を呼び出したとき保留中のデバウンスタイマーが
        // 生き残り（keywordInput が変わらない＝effect の cleanup が走らない）、適用直後に
        // 打鍵途中の語が後乗りして「保存条件＋入力中の語」になる。保存条件の keyword が
        // サーバ側 filters.keyword と同じ（どちらも空など）ときは応答速度に関係なく再現する。
        // setKeywordInput で effect が再実行されると cleanup がタイマーを消すため、
        // 新しいタイマーだけをフラグで抑止して visit の重複を防ぐ。
        // （「すべてクリア」を含む同種のレース全体の横断対応は issue #38）
        if (patch.keyword !== undefined && patch.keyword !== keywordInput) {
            skipNextKeywordDebounce.current = true;
            setKeywordInput(patch.keyword);
        }
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

    return (
        <AuthenticatedLayout>
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
                        engineers.data.map((e) => (
                            <EngineerCard key={e.id} engineer={e} onMatch={() => handleMatch(e.id)} />
                        ))
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
