<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\FormFieldSetting;
use App\Models\User;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService
    ) {}

    private const PHASES = [
        ['key' => 'proc_requirements',  'name' => '要件定義'],
        ['key' => 'proc_basic_design',  'name' => '基本設計'],
        ['key' => 'proc_detail_design', 'name' => '詳細設計'],
        ['key' => 'proc_development',   'name' => '開発'],
        ['key' => 'proc_testing',       'name' => 'テスト'],
        ['key' => 'proc_maintenance',   'name' => '保守・運用'],
    ];

    private const WORK_STYLES = [
        ['key' => 'onsite', 'name' => '常駐'],
        ['key' => 'hybrid', 'name' => '一部リモート可'],
        ['key' => 'remote', 'name' => 'フルリモート'],
    ];

    private const COMMERCIAL_FLOWS = [
        ['value' => 'prime',     'label' => 'プライム'],
        ['value' => 'secondary', 'label' => '2次'],
        ['value' => 'tertiary',  'label' => '3次'],
        ['value' => 'other',     'label' => 'その他'],
    ];

    private const STATUSES = [
        ['value' => 'open',    'label' => '募集中'],
        ['value' => 'closed',  'label' => '終了'],
        ['value' => 'pending', 'label' => 'ペンディング'],
    ];

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
            'phases'           => self::PHASES,
            'work_styles'      => self::WORK_STYLES,
            'commercial_flows' => self::COMMERCIAL_FLOWS,
            'statuses'         => self::STATUSES,
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
    public function show(string $id)
    {
        //
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
    public function destroy(string $id)
    {
        //
    }
}
