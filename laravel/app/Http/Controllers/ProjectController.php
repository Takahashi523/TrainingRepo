<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectRequest;
use App\Models\FormFieldSetting;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /**
     * フォーム選択肢は Project モデルの ENUM ラベル SSOT から導出する（ラベルの重複定義を避ける）。
     * work_styles は {key,name}、commercial_flows / statuses は {value,label} のフォーム選択肢形式。
     *
     * @return list<array{key: string, name: string}>
     */
    private static function workStyleOptions(): array
    {
        return array_map(
            fn (string $key, string $name) => ['key' => $key, 'name' => $name],
            array_keys(Project::WORK_STYLE_LABELS),
            array_values(Project::WORK_STYLE_LABELS),
        );
    }

    /**
     * value => label のマップを [{value, label}] の選択肢配列へ変換する。
     *
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function toValueLabelOptions(array $labels): array
    {
        return array_map(
            fn (string $value, string $label) => ['value' => $value, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );
    }

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

        $fieldSettings = collect($fieldKeys)->mapWithKeys(fn ($key) => [
            $key => ['is_required' => (bool) $settings->get($key, 0)],
        ])->toArray();

        return Inertia::render('Projects/Create', [
            'fieldSettings' => $fieldSettings,
            'phases' => self::PHASES,
            'work_styles' => self::workStyleOptions(),
            'commercial_flows' => self::toValueLabelOptions(Project::COMMERCIAL_FLOW_LABELS),
            'statuses' => self::toValueLabelOptions(Project::STATUS_LABELS),
            'users' => User::select('id', 'name')->orderBy('name')->get(),
            'authUserId' => auth()->id(),
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
