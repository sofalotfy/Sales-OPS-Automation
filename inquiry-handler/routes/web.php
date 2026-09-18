<?php

use App\Http\Controllers\AdminClassificationResultsController;
use App\Http\Controllers\AdminFactorSettingsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InquiryController;
use App\Http\Middleware\VerifyUpstreamToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The inquiry handler is a pure HTTP consumer: a single test console page
| plus one JSON classification endpoint. Context is retrieved from
| work-scope-rag and classifications are decided by the weighted multi-factor
| engine. Factor-settings admin endpoints (contracts/factor-settings-admin.md)
| are gated on a valid auth-service bearer token.
|
*/

Route::get('/health', HealthController::class)->name('health');

Route::get('/', [InquiryController::class, 'show'])->name('inquiry.test-console');
Route::post('/inquiry/triage', [InquiryController::class, 'triage'])
    ->middleware(['web.research', 'scope.gate'])
    ->name('inquiry.triage');

Route::middleware(VerifyUpstreamToken::class)->group(function () {
    Route::get('/admin/factor-settings', [AdminFactorSettingsController::class, 'index'])
        ->name('admin.factor-settings.index');
    Route::put('/admin/factor-settings', [AdminFactorSettingsController::class, 'update'])
        ->name('admin.factor-settings.update');

    Route::get('/admin/classification-results', [AdminClassificationResultsController::class, 'index'])
        ->name('admin.classification-results.index');
    Route::get('/admin/classification-results/{id}', [AdminClassificationResultsController::class, 'show'])
        ->name('admin.classification-results.show');
});
