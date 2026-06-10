<?php

namespace App\Http\Controllers;

use App\Http\Requests\EngineerRequest;
use App\Http\Resources\EngineerResource;
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
            'phases'        => Engineer::PHASES,
            'work_styles'   => Engineer::WORK_STYLES,
            'statuses'      => self::STATUSES,
            'users'         => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function show(Engineer $engineer): Response
    {
        $engineer->loadMissing(['skills', 'mainUser', 'subUser']);

        return Inertia::render('Engineers/Show', [
            'engineer' => EngineerResource::make($engineer),
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

        return redirect()->route('engineers.show', $engineer)->with('success', '人材情報を登録しました。');
    }

    public function destroy(Engineer $engineer): RedirectResponse
    {
        $this->authorize('delete', $engineer);

        $engineer->delete();

        return redirect('/engineers')->with('success', '人材情報を削除しました。');
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
