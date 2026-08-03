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

        $filterOptions = fn (array $statuses, array $workStyles) => [
            'statuses' => $statuses,
            'users' => $users,
            'work_styles' => $workStyles,
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

        return $this->handleImport($request, new EngineerCsvSchema);
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

        return redirect()->route('csv.index')->with('importResult', $result);
    }
}
