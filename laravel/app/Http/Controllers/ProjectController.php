<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\FormFieldSetting;
use App\Models\User;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService
    ) {}

    /**
     * Display a listing of the resource.
     * 
     * @todo 削除後の遷移確認用の暫定実装。案件名のみ表示。
     *       検索・絞り込み・ページネーションは別途実装する。
     */
    public function index()
    {
        $projects = Project::select('id', 'name')
            ->orderBy('id', 'desc')
            ->get();
 
        return Inertia::render('Projects/Index', [
            'projects' => $projects,
        ]);
    }
    
    /**
     * Show the form for creating a new resource.
     */
    public function create()
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

        return Inertia::render('Projects/Create', [
            'fieldSettings'    => $fieldSettings,
            'phases'           => Project::PHASES,
            'work_styles'      => Project::WORK_STYLES,
            'commercial_flows' => Project::COMMERCIAL_FLOWS,
            'statuses'         => Project::STATUSES,
            'users'            => User::select('id', 'name')->orderBy('name')->get(),
            'authUserId'       => auth()->id(),
        ]);
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
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $this->projectService->destroy($project);

        return redirect('/projects')->with('success', '案件情報を削除しました。');
    }
}
