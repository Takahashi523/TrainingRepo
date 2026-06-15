<?php

use App\Http\Controllers\EngineerController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('engineers', EngineerController::class)->only(['create', 'store', 'show', 'edit', 'update', 'destroy']);
});

require __DIR__.'/auth.php';
