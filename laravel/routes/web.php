<?php

use App\Http\Controllers\CsvController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EngineerController;
use App\Http\Controllers\Master\FormSettingController;
use App\Http\Controllers\Master\MasterController;
use App\Http\Controllers\Master\UserController as MasterUserController;
use App\Http\Controllers\MatchingController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('engineers', EngineerController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::resource('projects', ProjectController::class);

    Route::get('engineers/{engineer}/matching', [MatchingController::class, 'show'])->name('engineers.matching');

    // パイプライン（進捗管理）。completed を {pipeline} より前に定義しルート衝突を避ける
    Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
    Route::get('pipelines/completed', [PipelineController::class, 'completed'])->name('pipelines.completed');
    Route::get('pipelines/{pipeline}', [PipelineController::class, 'show'])->name('pipelines.show');
    Route::patch('pipelines/{pipeline}', [PipelineController::class, 'update'])->name('pipelines.update');
    Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
    Route::post('pipelines', [PipelineController::class, 'store'])->name('pipelines.store');

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
