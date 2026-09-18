<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The dashboard is a pure HTTP consumer: sign-in against auth-service and
| document management against work-scope-rag, both via server-side sessions.
| The inquiry-classification tab reads/writes the inquiry handler's admin
| weights + classification-log APIs using the same session token.
|
*/

Route::get('/health', [DashboardController::class, 'health'])->name('health');

Route::middleware('guest.upstream')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login.show');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth.upstream')->group(function () {
    Route::get('/', [DashboardController::class, 'home'])->name('dashboard');

    Route::get('/documents', [DashboardController::class, 'index'])->name('documents.index');
    Route::get('/documents/create', [DashboardController::class, 'create'])->name('documents.create');
    Route::get('/documents/{id}', [DashboardController::class, 'edit'])->name('documents.edit');
    Route::get('/documents/{id}/view', [DashboardController::class, 'show'])->name('documents.show');
    Route::get('/documents/{id}/download', [DashboardController::class, 'download'])->name('documents.download');

    Route::get('/classification', [ClassificationController::class, 'index'])->name('classification.index');
    Route::put('/classification/weights', [ClassificationController::class, 'update'])->name('classification.update');
    Route::get('/classification/{id}', [ClassificationController::class, 'show'])->name('classification.show');
});