<?php

use App\Exceptions\ErrorPageResponder;
use App\Http\Controllers\CsvController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EngineerController;
use App\Http\Controllers\Master\FormSettingController;
use App\Http\Controllers\Master\MasterController;
use App\Http\Controllers\Master\UserController as MasterUserController;
use App\Http\Controllers\MatchingController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SavedSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('engineers', EngineerController::class);
    Route::resource('projects', ProjectController::class);

    Route::get('engineers/{engineer}/matching', [MatchingController::class, 'show'])->name('engineers.matching');
    // AI要約の明示的な再生成（issue #61：失敗後のリカバリ手段）。appeal_note の変更有無に依存しない。
    Route::post('engineers/{engineer}/ai-summary/regenerate', [EngineerController::class, 'regenerateAiSummary'])->name('engineers.ai-summary.regenerate');

    // パイプライン（進捗管理）。completed を {pipeline} より前に定義しルート衝突を避ける
    Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
    Route::get('pipelines/completed', [PipelineController::class, 'completed'])->name('pipelines.completed');
    Route::get('pipelines/{pipeline}', [PipelineController::class, 'show'])->name('pipelines.show');
    Route::patch('pipelines/{pipeline}', [PipelineController::class, 'update'])->name('pipelines.update');
    Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
    Route::post('pipelines', [PipelineController::class, 'store'])->name('pipelines.store');

    Route::post('saved-searches', [SavedSearchController::class, 'store'])->name('savedSearches.store');
    Route::delete('saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy'])->name('savedSearches.destroy');

    // CSV 入出力（WF_11 / api/08）。認可は CsvController 内の Gate（access-csv）で admin/general 双方可。
    Route::get('csv', [CsvController::class, 'index'])->name('csv.index');
    Route::post('csv/engineers/import', [CsvController::class, 'importEngineers'])->name('csv.engineers.import');
    Route::get('csv/engineers/export', [CsvController::class, 'exportEngineers'])->name('csv.engineers.export');
    Route::post('csv/projects/import', [CsvController::class, 'importProjects'])->name('csv.projects.import');
    Route::get('csv/projects/export', [CsvController::class, 'exportProjects'])->name('csv.projects.export');
});

// マスタ管理（管理者専用）。全エンドポイントに admin ミドルウェアを適用する（docs/api/09）。
Route::middleware(['auth', 'admin'])->prefix('master')->name('master.')->group(function () {
    Route::get('/', [MasterController::class, 'index'])->name('index');
    Route::post('users', [MasterUserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [MasterUserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [MasterUserController::class, 'destroy'])->name('users.destroy');
    Route::put('form-settings', [FormSettingController::class, 'update'])->name('form-settings.update');
});

require __DIR__.'/auth.php';

/*
 * 未定義 URL の受け皿（issue #70）。
 *
 * ルート未一致のまま例外ハンドラへ落とすと、web ミドルウェアグループ（StartSession /
 * HandleInertiaRequests）が実行されない。するとログイン済みでも共有 Props の auth.user が
 * null になり、エラーページが未認証向けの表示（サイドバー無し・「ログイン画面へ」の導線）に
 * なってしまう。fallback ルートはルート解決に成功するため、共有 Props が揃った状態で
 * 404 の案内ページを描画できる。
 *
 * auth ミドルウェアは付けない。未ログインでの 404 をログイン画面へリダイレクトさせず、
 * 404 のまま返すため（応答の意味を変えない）。
 */
Route::fallback(fn (Request $request) => app(ErrorPageResponder::class)->fallback($request));
