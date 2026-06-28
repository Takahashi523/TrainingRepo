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
        return Inertia::render('Engineers/Create', $this->commonFormProps());
    }

    public function show(Engineer $engineer): Response
    {
        $engineer->loadMissing(['skills', 'mainUser', 'subUser']);

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
        $skills = $request->input('skills', []);

        $engineer = DB::transaction(function () use ($request, $skills) {
            $engineer = Engineer::create($this->engineerAttributes($request));
            $this->insertSkills($engineer, $skills);
            return $engineer;
        });

        $this->refreshAiSummary($engineer);

        return redirect()->route('engineers.show', $engineer)->with('success', '人材情報を登録しました。');
    }

    public function update(EngineerRequest $request, Engineer $engineer): RedirectResponse
    {
        $previousAppealNote = $engineer->appeal_note;

        DB::transaction(function () use ($request, $engineer) {
            $engineer->update($this->engineerAttributes($request));
            $this->replaceSkills($engineer, $request->input('skills', []));
        });

        if ($request->input('appeal_note') !== $previousAppealNote) {
            $this->refreshAiSummary($engineer);
        }

        return redirect()->route('engineers.show', $engineer)->with('success', '人材情報を更新しました。');
    }

    public function destroy(Engineer $engineer): RedirectResponse
    {
        $this->authorize('delete', $engineer);

        $engineer->delete();

        return redirect('/engineers')->with('success', '人材情報を削除しました。');
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
            'phases'        => Engineer::PHASES,
            'work_styles'   => Engineer::WORK_STYLES,
            'statuses'      => self::STATUSES,
            'users'         => User::select('id', 'name')->orderBy('name')->get(),
        ];
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
        // ConvertEmptyStringsToNull ミドルウェア通過後に label が null になった
        // 「空の行」を防御的にスキップする（バリデーション通過した場合でも DB を汚染させない）
        $meaningful = array_filter(
            $skills,
            fn ($s) => !empty($s['label']) || !empty($s['detail'])
        );

        if (empty($meaningful)) {
            return;
        }

        $engineer->skills()->createMany(
            array_map(fn($s) => [
                'label'  => $s['label']  ?? null,
                'detail' => $s['detail'] ?? null,
            ], $meaningful)
        );
    }

    /**
     * 既存スキルを全削除して送信内容で再挿入する（API設計書 #3/#6 の全件洗い替え方針）
     *
     * @param array<int, array{label: string|null, detail: string|null}> $skills
     */
    private function replaceSkills(Engineer $engineer, array $skills): void
    {
        $engineer->skills()->delete();
        $this->insertSkills($engineer, $skills);
    }

    /**
     * AI 要約を生成して engineer に保存する。生成結果が null の場合は何もしない。
     */
    private function refreshAiSummary(Engineer $engineer): void
    {
        $summary = $this->aiSummary->generate($engineer);
        if ($summary !== null) {
            $engineer->update([
                'ai_summary'              => $summary,
                'ai_summary_generated_at' => now(),
            ]);
        }
    }
}
