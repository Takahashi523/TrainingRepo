<?php

namespace App\Http\Controllers;

use App\Http\Requests\EngineerIndexRequest;
use App\Http\Requests\EngineerRequest;
use App\Http\Resources\EngineerListResource;
use App\Http\Resources\EngineerResource;
use App\Models\Engineer;
use App\Models\FormFieldSetting;
use App\Models\User;
use App\Services\AiSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /**
     * 許可されたソートの組み合わせ（sort×order のペア＋表示ラベル）。
     * DB設計書 §8 の4パターンを単一の情報源（SSOT）として定義し、
     * バリデーションはこのペアを基準に行い、同じ配列を sortOptions として props でフロントへ渡す。
     * 先頭がデフォルト（created_at DESC）。
     */
    private const SORT_OPTIONS = [
        ['sort' => 'created_at',     'order' => 'desc', 'label' => '登録日順（新しい順）'],
        ['sort' => 'created_at',     'order' => 'asc',  'label' => '登録日順（古い順）'],
        ['sort' => 'updated_at',     'order' => 'desc', 'label' => '更新日順（新しい順）'],
        ['sort' => 'available_from', 'order' => 'asc',  'label' => '提案可能タイミング順'],
    ];

    private const ALLOWED_STATUSES = ['proposable', 'interviewing', 'not_proposable'];

    private const ALLOWED_WORK_STYLES = ['onsite', 'hybrid', 'remote'];

    private const PER_PAGE_DEFAULT = 20;

    private const PER_PAGE_MAX = 100;

    public function index(EngineerIndexRequest $request): Response
    {
        $allowedPhases = array_column(Engineer::PHASES, 'key');

        $statuses = array_values(array_intersect(
            (array) $request->input('status', []), self::ALLOWED_STATUSES
        ));
        $workStyles = array_values(array_intersect(
            (array) $request->input('work_styles', []), self::ALLOWED_WORK_STYLES
        ));
        $phases = array_values(array_intersect(
            (array) $request->input('phases', []), $allowedPhases
        ));
        $keyword = trim((string) $request->input('keyword', ''));

        // ソートは sort×order のペア単位で検証する（仕様外の組み合わせはデフォルトへフォールバック）
        [$sort, $order] = $this->resolveSort($request);

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
            'statusOptions' => self::STATUSES,
            'workStyleOptions' => Engineer::WORK_STYLES,
            'phaseOptions' => Engineer::PHASES,
            'sortOptions' => self::SORT_OPTIONS,
        ]);
    }

    /**
     * ソートを sort×order のペア単位で検証する。
     * SORT_OPTIONS（許可組の配列）に一致するペアだけ採用し、無ければ先頭（デフォルト）へフォールバックする。
     * これにより仕様外の sort×order の組み合わせを弾き、UI の選択肢と完全に一致させる（SSOT）。
     *
     * @return array{0: string, 1: string} [$sort, $order]
     */
    private function resolveSort(Request $request): array
    {
        $sortInput = (string) $request->input('sort', '');
        $orderInput = strtolower((string) $request->input('order', ''));

        foreach (self::SORT_OPTIONS as $opt) {
            if ($opt['sort'] === $sortInput && $opt['order'] === $orderInput) {
                return [$opt['sort'], $opt['order']];
            }
        }

        return [self::SORT_OPTIONS[0]['sort'], self::SORT_OPTIONS[0]['order']];
    }

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
            'phases' => Engineer::PHASES,
            'work_styles' => Engineer::WORK_STYLES,
            'statuses' => self::STATUSES,
            'users' => User::select('id', 'name')->orderBy('name')->get(),
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
     * @param  array<int, array{label: string|null, detail: string|null}>  $skills
     */
    private function insertSkills(Engineer $engineer, array $skills): void
    {
        // ConvertEmptyStringsToNull ミドルウェア通過後に label が null になった
        // 「空の行」を防御的にスキップする（バリデーション通過した場合でも DB を汚染させない）
        $meaningful = array_filter(
            $skills,
            fn ($s) => ! empty($s['label']) || ! empty($s['detail'])
        );

        if (empty($meaningful)) {
            return;
        }

        $engineer->skills()->createMany(
            array_map(fn ($s) => [
                'label' => $s['label'] ?? null,
                'detail' => $s['detail'] ?? null,
            ], $meaningful)
        );
    }

    /**
     * 既存スキルを全削除して送信内容で再挿入する（API設計書 #3/#6 の全件洗い替え方針）
     *
     * @param  array<int, array{label: string|null, detail: string|null}>  $skills
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
                'ai_summary' => $summary,
                'ai_summary_generated_at' => now(),
            ]);
        }
    }
}
