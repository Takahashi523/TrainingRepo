<?php

namespace App\Http\Controllers;

use App\Http\Requests\EngineerRequest;
use App\Http\Resources\UserResource;
use App\Models\Engineer;
use App\Models\FormFieldSetting;
use App\Models\User;
use App\Services\AiSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EngineerController extends Controller
{
    public function __construct(private AiSummaryService $aiSummary) {}

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

    private const STATUSES = [
        ['value' => 'proposable',     'label' => '提案可'],
        ['value' => 'interviewing',   'label' => '面談中'],
        ['value' => 'not_proposable', 'label' => '提案不可'],
    ];

    public function create(): Response
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

        return Inertia::render('Engineers/Create', [
            'fieldSettings' => $fieldSettings,
            'phases'        => self::PHASES,
            'work_styles'   => self::WORK_STYLES,
            'statuses'      => self::STATUSES,
            'users'         => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(EngineerRequest $request): RedirectResponse
    {
        $skills = $request->input('skills', []);

        $engineer = DB::transaction(function () use ($request, $skills) {
            $engineer = Engineer::create($this->engineerAttributes($request));
            $this->insertSkills($engineer, $skills);
            return $engineer;
        });

        $summary = $this->aiSummary->generate($engineer);
        if ($summary !== null) {
            $engineer->update([
                'ai_summary'              => $summary,
                'ai_summary_generated_at' => now(),
            ]);
        }

        return redirect('/engineers')->with('success', '人材情報を登録しました。');
    }

    /**
     * リクエストから Engineer の保存用属性配列を組み立てる
     * work_styles[] → work_style_* 3カラムへ変換
     */
    private function engineerAttributes(EngineerRequest $request): array
    {
        $workStyles = $request->input('work_styles', []);

        return array_merge(
            $request->safe()->except(['skills', 'work_styles']),
            [
                'work_style_onsite' => in_array('onsite', $workStyles),
                'work_style_hybrid' => in_array('hybrid', $workStyles),
                'work_style_remote' => in_array('remote', $workStyles),
            ]
        );
    }

    /**
     * @param array<int, array{label: string|null, detail: string|null}> $skills
     */
    private function insertSkills(Engineer $engineer, array $skills): void
    {
        if (empty($skills)) {
            return;
        }

        $engineer->skills()->createMany(
            array_map(fn($s) => [
                'label'  => $s['label'] ?? null,
                'detail' => $s['detail'] ?? null,
            ], $skills)
        );
    }
}
