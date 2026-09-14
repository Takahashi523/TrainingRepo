<?php

namespace App\Http\Controllers;

use App\Exceptions\StaleUpdateException;
use App\Http\Controllers\Concerns\ResolvesSort;
use App\Http\Requests\ProjectIndexRequest;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectListResource;
use App\Http\Resources\SavedSearchResource;
use App\Models\FormFieldSetting;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use ResolvesSort;

    public function __construct(
        private readonly ProjectService $projectService
    ) {}

    private const PER_PAGE_DEFAULT = 20;

    private const PER_PAGE_MAX = 100;

    /**
     * Display a listing of the resource.
     */
    public function index(ProjectIndexRequest $request): Response
    {
        $statuses = array_values(array_intersect(
            (array) $request->input('status', []), array_column(Project::STATUSES, 'value')
        ));

        $workStyles = array_values(array_intersect(
            (array) $request->input('work_style', []), array_column(Project::WORK_STYLES, 'value')
        ));

        $commercialFlows = array_values(array_intersect(
            (array) $request->input('commercial_flow', []), array_column(Project::COMMERCIAL_FLOWS, 'value')
        ));

        $interviewCounts = array_map('intval', array_values(array_intersect(
            (array) $request->input('interview_count', []), array_column(Project::INTERVIEW_COUNTS, 'value')
        )));

        $keyword = trim((string) $request->input('keyword', ''));

        [$sort, $order] = $this->resolveSort($request, Project::SORT_OPTIONS);

        $perPage = (int) $request->input('per_page', self::PER_PAGE_DEFAULT);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $query = Project::query()
            ->select([
                'id', 'name', 'client_name', 'status',
                'commercial_flow', 'headcount', 'start_date',
                'rate_min', 'rate_max', 'rate_note',
                'work_style', 'interview_count',
                'main_user_id', 'sub_user_id',
                'updated_at', 'created_at'
            ])
            ->with([
                'projectSkills:id,project_id,label,skill_type',
                'mainUser:id,name',
                'subUser:id,name',
            ]);
        
        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        if ($workStyles) {
            $query->whereIn('work_style', $workStyles);
        }

        if ($commercialFlows) {
            $query->whereIn('commercial_flow', $commercialFlows);
        }

        if ($interviewCounts) {
            // interview_count=3（「3回以上」の意）が選択された場合、4回・5回…の案件も
            // 対象にする必要がある。そのため「3を除いた値の完全一致」と「3以上」を
            // ORで結合する。closureで囲むことで、他の絞り込み条件（status等）との
            // AND結合時にOR条件が漏れ出さないようにしている。
            if (in_array(3, $interviewCounts, true)) {
                $query->where(function ($q) use ($interviewCounts) {
                    $q->whereIn('interview_count', array_diff($interviewCounts, [3]));
                    $q->orWhere('interview_count', '>=', 3);
                });
            } else {
                // 3が選択されていない場合は、選択値との完全一致でよい
                $query->whereIn('interview_count', $interviewCounts);
            }
        }

        if ($keyword !== '') {
            // LIKE のメタ文字をエスケープ。バックスラッシュを最初に処理する
            // （後にすると %/_ 用に付与した \ まで二重エスケープされるため順序が重要）。
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $like = '%'.$escaped.'%';       // 案件名：部分一致
            $prefix = $escaped.'%';         // スキル：前方一致
            $query->where(function ($q) use ($like, $prefix) {
                $q->where('name', 'like', $like)
                    ->orWhereHas('projectSkills', fn ($s) => $s->where('label', 'like', $prefix));
            });
        }

        if ($sort === 'start_date') {
            $query->orderByRaw('start_date IS NULL ASC')
                ->orderBy('start_date', $order);
        } elseif ($sort === 'rate_max') {
            $query->orderByRaw('rate_max IS NULL ASC')
                ->orderBy('rate_max', $order)
                ->orderBy('rate_min', $order)
                ->orderByRaw('rate_note IS NULL ASC');
        } else {
            $query->orderBy($sort, $order);
        }

        $query->orderBy('id', 'asc');

        $paginator = $query->paginate($perPage)->appends($request->query());
 
        return Inertia::render('Projects/Index', [
            'savedSearches' => SavedSearchResource::collection(
                SavedSearch::listForUser(Auth::id(), 'project')
            )->collection,
            'projects' => [
                'data' => ProjectListResource::collection($paginator)->collection,
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
                'work_style' => $workStyles,
                'commercial_flow' => $commercialFlows,
                'interview_count' => $interviewCounts,
                'keyword' => $keyword,
                'sort' => $sort,
                'order' => $order,
                'per_page' => $perPage,
                'page' => $paginator->currentPage(),
            ],
            'statusOptions' => Project::STATUSES,
            'workStyleOptions' => Project::WORK_STYLES,
            'commercialFlowOptions' => Project::COMMERCIAL_FLOWS,
            'interviewCountOptions' => Project::INTERVIEW_COUNTS,
            'sortOptions' => Project::SORT_OPTIONS,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('Projects/Create', $this->commonFormProps());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = $this->projectService->store($request);

        return redirect()->route('projects.show', $project)->with('success', '案件情報を登録しました。');
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project): Response
    {
        $project->loadMissing(['projectSkills', 'mainUser', 'subUser']);

        return Inertia::render('Projects/Show', [
            'project' => ProjectResource::make($project),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project): Response
    {
        $project->loadMissing(['projectSkills', 'mainUser', 'subUser']);

        return Inertia::render('Projects/Edit', array_merge(
            $this->commonFormProps(),
            ['project' => ProjectResource::make($project)]
        ));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        try {
            $project = $this->projectService->update($request, $project);
        } catch (StaleUpdateException) {
            return back()->with('error', '他のユーザーがこの案件情報を更新しました。最新のデータを表示しました。');
        }

        return redirect()->route('projects.show', $project)->with('success', '案件情報を更新しました。');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project): RedirectResponse
    {
        // 認可はコントローラに残す（人材側 EngineerController::destroy と同様）。権限不足時は 403 を
        // 素で投げず、設計書 04_案件管理 DELETE #7 のとおり前画面へ戻し flash.error を返す。
        try {
            $this->authorize('delete', $project);
        } catch (AuthorizationException) {
            // referer が無い場合（直接リクエストされた場合等）でも flash が失われないよう、
            // ダッシュボードへのfallbackを明示する（#78 TokenMismatchInertiaRedirectorと同方針）。
            return back(fallback: route('dashboard'))->with('error', '削除権限がありません。');
        }

        $this->projectService->destroy($project);

        return redirect('/projects')->with('success', '案件情報を削除しました。');
    }

    private function commonFormProps(): array
    {
        $settings = FormFieldSetting::where('form_type', 'project')
            ->pluck('is_required', 'field_key');

        $fieldKeys = [
            'client_name',
            'required_skills',
            'preferred_skills',
            'rate',
            'start_date',
            'work_style',
            'work_location',
            'commercial_flow',
            'interview_count',
            'headcount',
            'work_env',
            'billing_range',
            'proc_experience',
            'negotiation_required',
            'description',
            'remarks',
        ];

        $fieldSettings = collect($fieldKeys)->mapWithKeys(fn($key) => [
            $key => ['is_required' => (bool) $settings->get($key, 0)],
        ])->toArray();

        return [
            'fieldSettings'    => $fieldSettings,
            'phases'           => Project::PHASES,
            'work_styles'      => Project::WORK_STYLES,
            'commercial_flows' => Project::COMMERCIAL_FLOWS,
            'statuses'         => Project::STATUSES,
            'users'            => User::select('id', 'name')->orderBy('name')->get(),
            'authUserId'       => auth()->id(),
        ];
    }
}
