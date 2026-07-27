<?php

namespace App\Http\Controllers;

use App\Http\Requests\PipelineCompletedRequest;
use App\Http\Requests\PipelineStoreRequest;
use App\Http\Requests\PipelineUpdateRequest;
use App\Http\Resources\PipelineCardResource;
use App\Http\Resources\PipelineCompletedResource;
use App\Http\Resources\PipelineDetailResource;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PipelineController extends Controller
{
    public function __construct(private PipelineService $pipelineService) {}

    /**
     * ソートの許可組み合わせ（sort×order のペア）を Single Source of Truth として定義する。
     * バリデーションはこのペアを基準に行い、同じ配列を sortOptions として props でフロントへ渡す。
     * これにより「UI の選択肢」と「バックエンドが許可する組み合わせ」が常に一致し、
     * URL 経由で仕様外の組み合わせ（例：sort=match_score&order=asc）が通らない。先頭要素がデフォルト。
     */
    private const SORT_OPTIONS_ACTIVE = [
        ['sort' => 'next_action_date', 'order' => 'asc', 'label' => '次回アクション日（近い順）'],
        ['sort' => 'match_score', 'order' => 'desc', 'label' => 'スコア（高い順）'],
        ['sort' => 'updated_at', 'order' => 'desc', 'label' => '最終更新日（新しい順）'],
    ];

    // 完了済みタブはスコアを表示しないため、非表示項目でのソートは提供しない（終了日のみ）
    private const SORT_OPTIONS_COMPLETED = [
        ['sort' => 'ended_at', 'order' => 'desc', 'label' => '終了日（新しい順）'],
        ['sort' => 'ended_at', 'order' => 'asc', 'label' => '終了日（古い順）'],
    ];

    private const COMPLETED_PER_PAGE = 50;

    /**
     * 進行中タブ（カンバン）。
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Pipelines/Index', $this->buildActiveProps($request));
    }

    /**
     * POST /pipelines：マッチング結果画面からのパイプライン生成（WF_09 の追加ボタン）。
     * 重複・上限（1案件5件）チェックと生成は PipelineService@create がトランザクションで担う。
     * 追加後は同画面に留まる想定のため back リダイレクト＋成功フラッシュを返す。
     */
    public function store(PipelineStoreRequest $request): RedirectResponse
    {
        $this->pipelineService->create($request->validated());

        // 追加後は同画面（マッチング結果）に留まる。戻り先 show でのエンジン再実行抑止フラグは
        // 成功・失敗で一貫させるため PipelineStoreRequest::prepareForValidation() がリクエスト単位で立てる
        // （#4 / tasklist 10-7：再スコアリングによる並び替わり・コスト・成功直後の空状態化を防ぐ）。
        return redirect()->back()
            ->with('success', 'パイプラインに追加しました。');
    }

    /**
     * ドロワー詳細。
     * Index ページを再描画し selectedPipeline / statusOptions を追加する（TBD #3 の実装方針）。
     * カード選択時はフロントの部分リロード（only: selectedPipeline, statusOptions）で詳細のみ取得される。
     */
    public function show(Request $request, Pipeline $pipeline): Response
    {
        $pipeline->load([
            'engineer:id,name,main_user_id',
            'engineer.mainUser:id,name',
            'project:id,name,client_name',
        ]);

        return Inertia::render('Pipelines/Index', array_merge(
            $this->buildActiveProps($request),
            [
                'selectedPipeline' => PipelineDetailResource::make($pipeline),
                'statusOptions' => $this->statusOptions(),
            ]
        ));
    }

    /**
     * 完了済みタブ（テーブル・ページネーション）。
     * keyword / ended_from / ended_to は PipelineCompletedRequest で検証する（バリデーション設計書 §6）。
     */
    public function completed(PipelineCompletedRequest $request): Response
    {
        $terminalValues = Pipeline::terminalValues();

        $keyword = trim((string) $request->input('keyword', ''));
        $userId = $request->filled('user_id') ? (int) $request->input('user_id') : null;
        $statuses = array_values(array_intersect(
            (array) $request->input('status', []), $terminalValues
        ));
        $endedFrom = $request->input('ended_from');
        $endedTo = $request->input('ended_to');

        [$sort, $order] = $this->resolveSort($request, self::SORT_OPTIONS_COMPLETED);

        $query = Pipeline::query()
            ->select(['id', 'engineer_id', 'project_id', 'status', 'ng_reason', 'ended_at'])
            ->with([
                'engineer:id,name,main_user_id',
                'engineer.mainUser:id,name',
                'project:id,name,client_name',
            ])
            ->whereIn('status', $statuses ?: $terminalValues);

        if ($userId) {
            $query->whereHas('engineer', fn (Builder $q) => $q->where('main_user_id', $userId));
        }

        if ($endedFrom) {
            $query->whereDate('ended_at', '>=', $endedFrom);
        }
        if ($endedTo) {
            $query->whereDate('ended_at', '<=', $endedTo);
        }

        $this->applyKeyword($query, $keyword);

        $query->orderBy($sort, $order)->orderBy('id', 'asc');

        $paginator = $query->paginate(self::COMPLETED_PER_PAGE)->appends($request->query());

        return Inertia::render('Pipelines/Completed', [
            'pipelines' => [
                'data' => PipelineCompletedResource::collection($paginator)->collection,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
            'filters' => [
                'keyword' => $keyword,
                'status' => $statuses,
                'user_id' => $userId,
                'ended_from' => $endedFrom,
                'ended_to' => $endedTo,
                'sort' => $sort,
                'order' => $order,
            ],
            'users' => $this->userOptions(),
            'statuses' => $this->terminalStatusOptions(),
            'sortOptions' => self::SORT_OPTIONS_COMPLETED,
        ]);
    }

    /**
     * PATCH：管理情報・ステータスの部分更新。
     * ended_at 記録・トランザクションは PipelineService が担う。
     * 終了→進行中／終了→別終了は PipelineUpdateRequest で 422 ブロック済み。
     */
    public function update(PipelineUpdateRequest $request, Pipeline $pipeline): RedirectResponse
    {
        $data = $request->safe()->only(['status', 'client_comment', 'ng_reason', 'next_action_date']);

        $this->pipelineService->update($pipeline, $data);

        // 更新後は必ず進行中カンバン（index）へ戻す。
        // redirect()->back() だと、ドロワー表示中は URL が /pipelines/{id}（＝show）のため
        // show に戻ってドロワーが再描画されてしまい、「終了に変えたのにドロワーが残る」
        // 「カードからの終了操作でドロワーが開く」不具合になる。
        // 絞り込み条件は referer のクエリから引き継ぎ、カンバンの絞り込み状態を保持する。
        return redirect()
            ->route('pipelines.index', $this->filtersFromReferer($request))
            ->with('success', 'パイプラインを更新しました。');
    }

    /**
     * referer URL のクエリ文字列（適用中フィルタ）を配列で返す。
     * ステータス更新後に index へ戻す際、カンバンの絞り込み状態を保持するために使う。
     *
     * @return array<string, mixed>
     */
    private function filtersFromReferer(Request $request): array
    {
        $query = parse_url((string) $request->headers->get('referer', ''), PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $params);

        return $params;
    }

    /**
     * DELETE：管理者のみ物理削除（PipelinePolicy@delete で認可）。
     */
    public function destroy(Request $request, Pipeline $pipeline): RedirectResponse
    {
        $this->authorize('delete', $pipeline);

        $this->pipelineService->delete($pipeline);

        // 行き先は referer から明示的に解決する。redirect()->back() だと、ドロワーから削除した
        // 場合に referer が /pipelines/{id}（show）のため、削除済み ID の show へ戻って 404 になる
        // （update と同じ原因。2026-07-11 の update 修正と同方針）。
        // 完了済みタブからの削除は completed へ、それ以外（カンバン・ドロワー）は index へ戻し、
        // 絞り込み条件（referer のクエリ）は引き継ぐ。
        $routeName = $this->refererIsCompleted($request) ? 'pipelines.completed' : 'pipelines.index';

        return redirect()
            ->route($routeName, $this->filtersFromReferer($request))
            ->with('success', 'パイプラインを削除しました。');
    }

    /**
     * referer が完了済みタブ（/pipelines/completed）かどうか。
     * destroy の戻り先タブの判定に使う。
     */
    private function refererIsCompleted(Request $request): bool
    {
        $path = parse_url((string) $request->headers->get('referer', ''), PHP_URL_PATH);

        return is_string($path) && str_ends_with($path, '/pipelines/completed');
    }

    /**
     * index / show 共通の進行中カンバン Props を構築する（DRY）。
     *
     * @return array<string, mixed>
     */
    private function buildActiveProps(Request $request): array
    {
        $inProgressValues = Pipeline::inProgressValues();
        $allowedRanks = array_column(Pipeline::RANKS, 'value');

        $keyword = trim((string) $request->input('keyword', ''));
        // 担当営業フィルタは 3 状態：'all'（全員）/ 数値（個別指定）/ 未指定（デフォルト＝自分の担当）
        $userIdRaw = $request->input('user_id');
        $isAll = $userIdRaw === 'all';
        $userId = (! $isAll && is_numeric($userIdRaw)) ? (int) $userIdRaw : null;
        $ranks = array_values(array_intersect(
            (array) $request->input('rank', []), $allowedRanks
        ));
        $statuses = array_values(array_intersect(
            (array) $request->input('status', []), $inProgressValues
        ));

        [$sort, $order] = $this->resolveSort($request, self::SORT_OPTIONS_ACTIVE);

        $query = Pipeline::query()
            ->select([
                'id', 'engineer_id', 'project_id', 'status', 'match_score',
                'match_rank', 'next_action_date', 'updated_at',
            ])
            ->with([
                'engineer:id,name,main_user_id',
                'engineer.mainUser:id,name',
                'project:id,name,client_name',
            ])
            ->whereIn('status', $inProgressValues);

        // 担当営業フィルタ（QA #70）。
        // - 'all'（全員）：絞り込まない
        // - 個別指定：その担当営業がメインの人材のカードのみ
        // - 未指定（デフォルト）：ログインユーザーがメイン/サブ担当の人材のカードのみ
        if ($isAll) {
            // 絞り込みなし（全員表示）
        } elseif ($userId) {
            $query->whereHas('engineer', fn (Builder $q) => $q->where('main_user_id', $userId));
        } else {
            $uid = $request->user()->id;
            $query->whereHas('engineer', fn (Builder $q) => $q
                ->where('main_user_id', $uid)
                ->orWhere('sub_user_id', $uid));
        }

        if ($ranks) {
            $query->whereIn('match_rank', $ranks);
        }
        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        $this->applyKeyword($query, $keyword);

        // ソート（next_action_date の null は末尾）
        if ($sort === 'next_action_date') {
            $query->orderByRaw('next_action_date IS NULL ASC')
                ->orderBy('next_action_date', $order);
        } else {
            $query->orderBy($sort, $order);
        }
        $query->orderBy('id', 'asc');

        $pipelines = $query->get();

        return [
            'columns' => $this->buildColumns($pipelines, $request),
            'filters' => [
                'keyword' => $keyword,
                // null＝自分の担当（デフォルト）/ 'all'＝全員 / int＝個別指定
                'user_id' => $isAll ? 'all' : $userId,
                'rank' => $ranks,
                'status' => $statuses,
                'sort' => $sort,
                'order' => $order,
            ],
            'users' => $this->userOptions(),
            'ranks' => Pipeline::RANKS,
            'statuses' => $this->inProgressStatusOptions(),
            'sortOptions' => self::SORT_OPTIONS_ACTIVE,
            // ドロワー未開封時は null（部分リロードで show が上書きする）
            'selectedPipeline' => null,
            'statusOptions' => $this->statusOptions(),
        ];
    }

    /**
     * 取得済みパイプラインを4カンバングループ構造へ整形する。
     * ソートは SQL 側で確定済みのためグループ内順序が保証される。
     *
     * @param  Collection<int, Pipeline>  $pipelines
     * @return array<int, array<string, mixed>>
     */
    private function buildColumns($pipelines, Request $request): array
    {
        $grouped = $pipelines->groupBy(fn (Pipeline $p) => Pipeline::STATUSES[$p->status]['group']);

        return array_map(function (array $group) use ($grouped, $request) {
            $cards = $grouped->get($group['key'], collect());

            return [
                'key' => $group['key'],
                'label' => $group['label'],
                'count' => $cards->count(),
                'cards' => PipelineCardResource::collection($cards)->toArray($request),
            ];
        }, Pipeline::KANBAN_GROUPS);
    }

    /**
     * 人材名 OR 案件名の部分一致フィルタ（EngineerController と同じ LIKE エスケープ手法）。
     */
    private function applyKeyword(Builder $query, string $keyword): void
    {
        if ($keyword === '') {
            return;
        }

        // LIKE ワイルドカードのエスケープ。バックスラッシュ（MySQL 既定のエスケープ文字）を最初に処理する。
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword).'%';
        $query->where(function (Builder $q) use ($like) {
            $q->whereHas('engineer', fn (Builder $e) => $e->where('name', 'like', $like))
                ->orWhereHas('project', fn (Builder $p) => $p->where('name', 'like', $like));
        });
    }

    /**
     * sort / order をホワイトリスト化して解決する。
     *
     * @param  array<int, string>  $allowedSorts
     * @return array{0: string, 1: string}
     */
    /**
     * ソートを sort×order のペア単位で検証する。
     * $sortOptions（許可組の配列）に一致するペアだけ採用し、無ければ先頭（デフォルト）へフォールバックする。
     * これにより仕様外の sort×order の組み合わせを弾き、UI の選択肢と完全に一致させる。
     *
     * @param  array<int, array{sort: string, order: string, label: string}>  $sortOptions
     * @return array{0: string, 1: string} [$sort, $order]
     */
    private function resolveSort(Request $request, array $sortOptions): array
    {
        $sortInput = (string) $request->input('sort', '');
        $orderInput = strtolower((string) $request->input('order', ''));

        foreach ($sortOptions as $opt) {
            if ($opt['sort'] === $sortInput && $opt['order'] === $orderInput) {
                return [$opt['sort'], $opt['order']];
            }
        }

        return [$sortOptions[0]['sort'], $sortOptions[0]['order']];
    }

    /**
     * 担当営業フィルタ選択肢（全ユーザー）。
     */
    private function userOptions()
    {
        return User::select('id', 'name')->orderBy('name')->get();
    }

    /**
     * 進行中12種のフィルタ選択肢（group 付き）。
     *
     * @return array<int, array<string, string>>
     */
    private function inProgressStatusOptions(): array
    {
        $options = [];
        foreach (Pipeline::STATUSES as $value => $meta) {
            if (! $meta['is_terminal']) {
                $options[] = ['value' => $value, 'label' => $meta['label'], 'group' => $meta['group']];
            }
        }

        return $options;
    }

    /**
     * 終了4種のフィルタ選択肢。
     *
     * @return array<int, array<string, string>>
     */
    private function terminalStatusOptions(): array
    {
        $options = [];
        foreach (Pipeline::STATUSES as $value => $meta) {
            if ($meta['is_terminal']) {
                $options[] = ['value' => $value, 'label' => $meta['label']];
            }
        }

        return $options;
    }

    /**
     * ステータス変更プルダウン用（16種・group / is_terminal 付き）。
     *
     * @return array<int, array<string, mixed>>
     */
    private function statusOptions(): array
    {
        $options = [];
        foreach (Pipeline::STATUSES as $value => $meta) {
            $options[] = [
                'value' => $value,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'is_terminal' => $meta['is_terminal'],
            ];
        }

        return $options;
    }
}
