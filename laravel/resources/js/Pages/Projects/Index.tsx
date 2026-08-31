import Pagination from "@/Components/Common/Pagination";
import SortSelect from "@/Components/Common/SortSelect";
import ProjectCard from "@/Components/Projects/ProjectCard";
import ProjectFilterPanel from "@/Components/Projects/ProjectFilterPanel";
import { Button } from "@/Components/ui/button";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { ProjectFilters, ProjectIndexPageProps } from "@/types/project";
import { PageProps } from "@/types";
import { useKeywordDebounce } from "@/hooks/use-keyword-debounce";
import { useScrollContainer } from "@/hooks/use-scroll-container";
import { Head, router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { useRef } from "react";

type Props = PageProps<ProjectIndexPageProps>;

type QueryPayload = {
    status: string[];
    work_style: string[];
    commercial_flow: string[];
    interview_count: number[];
    keyword?: string;
    sort: string;
    order: string;
    page: number;
    per_page: number;
};

function buildQuery(filters: ProjectFilters): QueryPayload {
    return {
        status: filters.status,
        work_style: filters.work_style,
        commercial_flow: filters.commercial_flow,
        interview_count: filters.interview_count,
        keyword: filters.keyword || undefined,
        sort: filters.sort,
        order: filters.order,
        page: filters.page,
        per_page: filters.per_page,
    };
}

export default function Index({
    projects,
    filters,
    statusOptions,
    workStyleOptions,
    commercialFlowOptions,
    interviewCountOptions,
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
    // visit を ref 経由の最新 filters にマージすることでこの競合（stale closure）を根治する（人材一覧 issue #38 と同方針）。
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    // 一覧のスクロール境界（AuthenticatedLayout の <main>）。ref をレイアウトへ渡して掴み、
    // ページ送りのときだけ scrollToTop で先頭へ戻す。
    const { scrollContainerRef, scrollToTop } = useScrollContainer();

    // onSuccess は「ページ送りのときだけ先頭へ戻す」ために渡す任意引数。
    // 省略時（フィルタ変更・デバウンス・ソート・すべてクリア）は preserveScroll がそのまま効き、
    // 条件パネルの位置を保ったまま結果だけが差し替わる。
    const visit = (patch: Partial<ProjectFilters>, onSuccess?: () => void) => {
        const next: ProjectFilters = { ...filtersRef.current, ...patch };
        router.get("/projects", buildQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess,
        });
    };

    // ページ送りはカード一覧が総入れ替わりになる操作なので、先頭へ戻す（issue #107）。
    // 人材一覧 Index.tsx と同じ配線（理由の詳細はそちらのコメントを参照）。
    const handlePageChange = (page: number) => {
        visit({ page }, scrollToTop);
    };

    const handleFilterChange = (patch: Partial<ProjectFilters>) => {
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
        applyKeyword("");
        visit({
            status: [],
            work_style: [],
            commercial_flow: [],
            interview_count: [],
            keyword: "",
            page: 1,
        });
    };

    // 地色（bg-muted/30）は <main> に載せる。カード一覧側に置くと少件数・0件のときに
    // 画面下部が <main> の白背景のまま残るため。人材一覧・案件詳細と同じ使い方（issue #82）。
    return (
        <AuthenticatedLayout mainClassName="bg-muted/30" mainRef={scrollContainerRef}>
            <Head title="案件一覧" />
            {/*
             * 人材一覧と同様に、スクロール境界は AuthenticatedLayout の <main> 1か所に一本化する
             * （自前のスクロール箱は作らない）。-m-6 は <main> 内側 div の p-6 を打ち消して
             * フルブリードにするためだけに残す。
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
                            <h1 className="text-lg font-bold text-foreground">
                                案件一覧
                            </h1>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                登録済みの案件を検索・確認します
                            </p>
                        </div>
                        <div>
                            <Button
                                onClick={() => router.get("/projects/create")}
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" />
                                新規案件登録
                            </Button>
                        </div>
                    </div>

                    <ProjectFilterPanel
                        filters={filters}
                        statuses={statusOptions}
                        workStyles={workStyleOptions}
                        commercialFlows={commercialFlowOptions}
                        interviewCounts={interviewCountOptions}
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
                            <strong className="text-foreground">
                                {projects.meta.total}
                            </strong>{" "}
                            件の案件が見つかりました
                        </span>
                        <div className="ml-auto">
                            <SortSelect
                                options={sortOptions}
                                currentSort={filters.sort}
                                currentOrder={filters.order}
                                onChange={(sort, order) =>
                                    handleFilterChange({
                                        sort: sort as ProjectFilters["sort"],
                                        order: order as ProjectFilters["order"],
                                    })
                                }
                            />
                        </div>
                    </div>

                    {projects.data.length === 0 ? (
                        <div className="rounded-md border border-dashed border-border bg-white py-12 text-center text-sm text-muted-foreground">
                            該当する案件はありません
                        </div>
                    ) : (
                        projects.data.map((p) => (
                            <ProjectCard key={p.id} project={p} />
                        ))
                    )}

                    <Pagination
                        meta={projects.meta}
                        onChange={handlePageChange}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
