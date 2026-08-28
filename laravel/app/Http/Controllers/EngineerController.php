<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesSort;
use App\Http\Requests\EngineerIndexRequest;
use App\Http\Requests\EngineerRequest;
use App\Http\Resources\EngineerListResource;
use App\Http\Resources\EngineerResource;
use App\Http\Resources\SavedSearchResource;
use App\Models\Engineer;
use App\Models\FormFieldSetting;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\EngineerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EngineerController extends Controller
{
    use ResolvesSort;

    public function __construct(private readonly EngineerService $engineerService) {}

    private const ALLOWED_WORK_STYLES = ['onsite', 'hybrid', 'remote'];

    private const PER_PAGE_DEFAULT = 20;

    private const PER_PAGE_MAX = 100;

    public function index(EngineerIndexRequest $request): Response
    {
        $allowedPhases = array_column(Engineer::PHASES, 'key');

        $statuses = array_values(array_intersect(
            (array) $request->input('status', []), array_column(Engineer::STATUSES, 'value')
        ));
        $workStyles = array_values(array_intersect(
            (array) $request->input('work_styles', []), self::ALLOWED_WORK_STYLES
        ));
        $phases = array_values(array_intersect(
            (array) $request->input('phases', []), $allowedPhases
        ));
        $keyword = trim((string) $request->input('keyword', ''));

        // ソートは sort×order のペア単位で検証する（仕様外の組み合わせはデフォルトへフォールバック）
        [$sort, $order] = $this->resolveSort($request, Engineer::SORT_OPTIONS);

        $perPage = (int) $request->input('per_page', self::PER_PAGE_DEFAULT);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $query = Engineer::query()
            ->select([
                'id', 'name', 'birth_date', 'nearest_station', 'nearest_line',
                'status', 'available_from', 'main_user_id', 'sub_user_id',
                'proc_requirements', 'proc_basic_design', 'proc_detail_design',
                'proc_development', 'proc_testing', 'proc_maintenance',
                'work_style_onsite', 'work_style_hybrid', 'work_style_remote',
                'updated_at', 'created_at',
            ])
            ->with([
                'skills:id,engineer_id,label',
                'mainUser:id,name',
                'subUser:id,name',
            ]);

        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        if ($workStyles) {
            // 勤務形態は OR（いずれか1つでも該当すればヒット）
            $query->where(function ($q) use ($workStyles) {
                foreach ($workStyles as $ws) {
                    $q->orWhere("work_style_{$ws}", true);
                }
            });
        }

        // 工程経験は AND（選択した全工程に経験あり）
        foreach ($phases as $p) {
            $query->where($p, true);
        }

        if ($keyword !== '') {
            // LIKE のメタ文字をエスケープ。バックスラッシュを最初に処理する
            // （後にすると %/_ 用に付与した \ まで二重エスケープされるため順序が重要）。
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $like = '%'.$escaped.'%';       // 氏名：部分一致
            $prefix = $escaped.'%';         // スキル：前方一致
            $query->where(function ($q) use ($like, $prefix) {
                $q->where('name', 'like', $like)
                    ->orWhereHas('skills', fn ($s) => $s->where('label', 'like', $prefix));
            });
        }

        if ($sort === 'available_from') {
            // NULL を末尾に置く（MySQL 互換: IS NULL は 0/1 を返す）
            $query->orderByRaw('available_from IS NULL ASC')
                ->orderBy('available_from', $order);
        } else {
            $query->orderBy($sort, $order);
        }
        // 同順のタイブレーク（DB設計書 §8 QA #85 確定）
        $query->orderBy('id', 'asc');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return Inertia::render('Engineers/Index', [
            'savedSearches' => SavedSearchResource::collection(
                SavedSearch::listForUser(Auth::id(), 'engineer')
            )->collection,
            'engineers' => [
                'data' => EngineerListResource::collection($paginator)->collection,
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
                'status' => $statuses,
                'work_styles' => $workStyles,
                'phases' => $phases,
                'keyword' => $keyword,
                'sort' => $sort,
                'order' => $order,
                'per_page' => $perPage,
                'page' => $paginator->currentPage(),
            ],
            'statusOptions' => Engineer::STATUSES,
            'workStyleOptions' => Engineer::WORK_STYLES,
            'phaseOptions' => Engineer::PHASES,
            'sortOptions' => Engineer::SORT_OPTIONS,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Engineers/Create', $this->commonFormProps());
    }

    public function show(Engineer $engineer): Response
    {
        // 削除確認ダイアログで「紐づくパイプライン〇件も同時に削除」を警告するため件数を集計する。
        // loadCount で1クエリに集約し N+1 を避ける（DELETE #7 の件数警告要件）。
        $engineer->loadMissing(['skills', 'mainUser', 'subUser'])
            ->loadCount('pipelines');

        return Inertia::render('Engineers/Show', [
            'engineer' => EngineerResource::make($engineer),
        ]);
    }

    public function edit(Engineer $engineer): Response
    {
        $engineer->loadMissing(['skills', 'mainUser', 'subUser']);

        return Inertia::render('Engineers/Edit', array_merge(
            $this->commonFormProps(),
            ['engineer' => EngineerResource::make($engineer)]
        ));
    }

    public function store(EngineerRequest $request): RedirectResponse
    {
        $result = $this->engineerService->store($request);

        $redirect = redirect()->route('engineers.show', $result->engineer)
            ->with('success', '人材情報を登録しました。');

        // 人材登録は成功。AI 要約生成だけ失敗した場合は、そのことを失敗トーストで別途通知する
        // （成功トーストと2枚同時表示）。空出力（未生成）では通知しない。
        return $result->aiSummaryFailed
            ? $redirect->with('error', 'AI要約の生成に失敗しました。')
            : $redirect;
    }

    public function update(EngineerRequest $request, Engineer $engineer): RedirectResponse
    {
        $result = $this->engineerService->update($request, $engineer);

        $redirect = redirect()->route('engineers.show', $result->engineer)
            ->with('success', '人材情報を更新しました。');

        return $result->aiSummaryFailed
            ? $redirect->with('error', 'AI要約の生成に失敗しました。')
            : $redirect;
    }

    public function destroy(Engineer $engineer): RedirectResponse
    {
        // 認可はコントローラに残す（案件側 ProjectController::destroy と同様）。権限不足時は 403 を
        // 素で投げず、設計書 03_人材管理 DELETE #7 のとおり前画面へ戻し flash.error を返す。
        try {
            $this->authorize('delete', $engineer);
        } catch (AuthorizationException) {
            // referer が無い場合（直接リクエストされた場合等）でも flash が失われないよう、
            // ダッシュボードへのfallbackを明示する（#78 TokenMismatchInertiaRedirectorと同方針）。
            return back(fallback: route('dashboard'))->with('error', '削除権限がありません。');
        }

        $this->engineerService->destroy($engineer);

        return redirect('/engineers')->with('success', '人材情報を削除しました。');
    }

    /**
     * AI 要約の明示的な再生成（issue #61 課題2）。
     *
     * appeal_note の変更有無に依存せず、詳細画面（WF_05）から任意のタイミングで呼べる。
     * 失敗時も本体データには影響がないため、redirect 先は常に engineers.show（詳細画面）。
     */
    public function regenerateAiSummary(Engineer $engineer): RedirectResponse
    {
        $failed = $this->engineerService->regenerateAiSummary($engineer);

        $redirect = redirect()->route('engineers.show', $engineer);

        return $failed
            ? $redirect->with('error', 'AI要約の生成に失敗しました。')
            : $redirect->with('success', 'AI要約を再生成しました。');
    }

    /**
     * create / edit 画面が共通で使うフォーム関連 Props を組み立てる
     */
    private function commonFormProps(): array
    {
        $settings = FormFieldSetting::where('form_type', 'engineer')
            ->pluck('is_required', 'field_key');

        $fieldKeys = [
            'birth_date', 'nearest_station', 'nearest_line', 'available_from',
            'skills', 'proc_experience', 'has_negotiation_exp', 'appeal_note',
            'desired_rate', 'work_styles', 'remarks',
        ];

        $fieldSettings = collect($fieldKeys)->mapWithKeys(fn ($key) => [
            $key => ['is_required' => (bool) $settings->get($key, 0)],
        ])->toArray();

        return [
            'fieldSettings' => $fieldSettings,
            'phases' => Engineer::PHASES,
            'work_styles' => Engineer::WORK_STYLES,
            'statuses' => Engineer::STATUSES,
            'users' => User::select('id', 'name')->orderBy('name')->get(),
        ];
    }
}
