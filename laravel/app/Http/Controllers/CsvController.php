<?php

namespace App\Http\Controllers;

use App\Http\Requests\Csv\CsvImportRequest;
use App\Http\Requests\Csv\EngineerCsvExportRequest;
use App\Http\Requests\Csv\ProjectCsvExportRequest;
use App\Models\Engineer;
use App\Models\Project;
use App\Models\User;
use App\Services\Csv\CsvExportService;
use App\Services\Csv\CsvImportService;
use App\Services\EngineerService;
use App\Support\Csv\CsvSchema;
use App\Support\Csv\EngineerCsvSchema;
use App\Support\Csv\ProjectCsvSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV 入出力（WF_11 / api/08）。薄いコントローラ：認可 → Service 呼び出し → レスポンス整形のみ。
 *
 * 認可は CsvPolicy@access（Gate ability 'access-csv'・admin/general 双方可・O-3）に集約する。
 * 各アクション先頭で Gate::authorize('access-csv') を呼ぶ（コントローラにロール判定を書かない）。
 */
class CsvController extends Controller
{
    public function __construct(
        private readonly CsvImportService $importService,
        private readonly CsvExportService $exportService,
        private readonly EngineerService $engineerService,
    ) {}

    /**
     * CSV 入出力画面。エクスポート絞り込みの選択肢と担当者ID凡例（users 全件）を渡す。
     */
    public function index(): Response
    {
        Gate::authorize('access-csv');

        // users は「凡例・絞り込み・インポート検証(exists)」の3者で一致させる（全件・SELECT id,name）。
        // 担当者ID一覧は「ID から名前を引く」用途のため ID 昇順で並べる。
        $users = User::query()->select('id', 'name')->orderBy('id')->get();

        // 稼働形態の選択肢はフロント（ExportFilter / CsvFilterOptions 型）が {key, name} 契約で読む。
        // モデル定数のキー名差（Engineer=key/name／Project=value/label）を境界のここで吸収して正規化する。
        // これをしないと案件側で w.key / w.name が undefined になり、ラベルが消えて絞り込みも壊れる。
        // 定数自体は他画面（案件フォーム・バリデーション）が value/label 前提で参照するため変更しない。
        $normalizeWorkStyles = fn (array $styles) => array_map(
            fn (array $s) => [
                'key' => $s['key'] ?? $s['value'],
                'name' => $s['name'] ?? $s['label'],
            ],
            $styles,
        );

        $filterOptions = fn (array $statuses, array $workStyles) => [
            'statuses' => $statuses,
            'users' => $users,
            'work_styles' => $normalizeWorkStyles($workStyles),
        ];

        return Inertia::render('Csv/Index', [
            'engineer_filter_options' => $filterOptions(Engineer::STATUSES, Engineer::WORK_STYLES),
            'project_filter_options' => $filterOptions(Project::STATUSES, Project::WORK_STYLES),
            // アップロード上限（バイト）。フロントのサイズ事前ガードで使う。上限値は CsvImportRequest に集約（SSOT）。
            'csv_max_upload_bytes' => CsvImportRequest::MAX_FILE_SIZE_KB * 1024,
        ]);
    }

    public function importEngineers(CsvImportRequest $request): RedirectResponse
    {
        Gate::authorize('access-csv');

        // 生成トリガーの経過時間予算（issue #61 課題4）の起点。import() 自体（検証・バッチ書き込み）に
        // 使った時間も含めて計測するため、import() 呼び出し前に取得する。
        $importStartedAt = microtime(true);

        // 新規行の特定を created_at（秒精度）だけに頼ると、無関係な既存行がたまたま同じ秒に作成されて
        // いた場合に誤って巻き込まれてしまう（同一秒の衝突。テストで実際に再現した）。オートインクリメント
        // の id はインポートより前に存在した行を超えないため、この時点の最大 id と組み合わせて絞り込む。
        $maxIdBeforeImport = Engineer::max('id') ?? 0;

        $result = $this->importService->import($request->file('file'), new EngineerCsvSchema);

        // 生成トリガーの全経路適用（issue #61 課題4）。対象は今回のインポートで書き込まれた行のみ
        // （updated_ids / written_at / maxIdBeforeImport で限定。テーブル全体はスイープしない）。
        // importResult（フロント契約）には影響させず、超過分があれば別途 flash.aiSummarySkipped で通知する。
        $trigger = $this->engineerService->triggerAiSummaryForCsvImport(
            $result['updated_ids'],
            $result['written_at'],
            $importStartedAt,
            $maxIdBeforeImport,
        );

        $redirect = redirect()->route('csv.index')->with('importResult', $this->importResultPayload($result));

        if ($trigger['skipped'] > 0) {
            // 手動確認で発覚：flash.error は AuthenticatedLayout の全体トーストと、このセクション自身が
            // 出す成功トースト（CsvImportSection::onSuccess）が同一レスポンスで同時に発火し、トースト実装が
            // 1件しか同時表示しない（use-toast.ts の TOAST_LIMIT=1）ため、この警告だけが黙って
            // 上書き消去されてしまっていた。トーストに頼らず、成功バナーと同じ「常設バナー」として
            // 確実に表示されるよう、専用の flash キーに構造化データを載せる（文言の組み立てはフロント側）。
            $redirect->with('aiSummarySkipped', [
                'triggered' => $trigger['triggered'],
                'skipped' => $trigger['skipped'],
            ]);
        }

        return $redirect;
    }

    public function importProjects(CsvImportRequest $request): RedirectResponse
    {
        Gate::authorize('access-csv');

        return $this->handleImport($request, new ProjectCsvSchema);
    }

    public function exportEngineers(EngineerCsvExportRequest $request): StreamedResponse
    {
        Gate::authorize('access-csv');

        return $this->exportService->stream(new EngineerCsvSchema, $request->validated());
    }

    public function exportProjects(ProjectCsvExportRequest $request): StreamedResponse
    {
        Gate::authorize('access-csv');

        return $this->exportService->stream(new ProjectCsvSchema, $request->validated());
    }

    /**
     * インポート共通処理。
     * - 成功：/csv へ redirect back し flash importResult に成功サマリを載せる（onSuccess でトースト）。
     * - 失敗：CsvImportService が ValidationException（errors.importErrors・422）を投げる（flash では返さない）。
     */
    private function handleImport(CsvImportRequest $request, CsvSchema $schema): RedirectResponse
    {
        $result = $this->importService->import($request->file('file'), $schema);

        return redirect()->route('csv.index')->with('importResult', $this->importResultPayload($result));
    }

    /**
     * フロント契約（resources/js/types/csv.ts の ImportResult）は { resource, summary } のみ。
     * import() の戻り値には updated_ids / written_at など後続処理向けの補助情報も含まれるため、
     * flash に載せる前にここで絞り込む。
     *
     * @param  array{resource: string, summary: array{total_rows: int, created: int, updated: int}, updated_ids: array<int, int>, written_at: \Illuminate\Support\Carbon}  $result
     * @return array{resource: string, summary: array{total_rows: int, created: int, updated: int}}
     */
    private function importResultPayload(array $result): array
    {
        return [
            'resource' => $result['resource'],
            'summary' => $result['summary'],
        ];
    }
}
