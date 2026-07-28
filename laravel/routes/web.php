<?php

use App\Http\Controllers\EngineerController;
use App\Http\Controllers\MatchingController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
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
});

require __DIR__.'/auth.php';
