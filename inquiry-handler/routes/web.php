<?php

use App\Http\Controllers\AdminClassificationResultsController;
use App\Http\Controllers\AdminFactorSettingsController;
use App\Http\Controllers\AdminIndustrySectorsController;
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
Route::get('/inquiry', [InquiryController::class, 'show']);

// The single inquiry surface: POST /inquiry/triage authenticates with the
// shared X-CRM-Key, opens a run row and hands it to the Redis queue
// (202 + inquiry_id); GET /inquiry/{id} returns the finished result. Both used
// to be duplicated behind a separate CRM ingest controller — one route pair,
// one controller, no throttle.
Route::middleware('crm.key')->group(function () {
    Route::post('/inquiry/triage', [InquiryController::class, 'triage'])
        ->name('inquiry.triage');
    Route::get('/inquiry/{id}', [InquiryController::class, 'poll'])
        ->whereNumber('id')
        ->name('inquiry.poll');
});

Route::middleware(VerifyUpstreamToken::class)->group(function () {
    Route::get('/admin/factor-settings', [AdminFactorSettingsController::class, 'index'])
        ->name('admin.factor-settings.index');
    Route::put('/admin/factor-settings', [AdminFactorSettingsController::class, 'update'])
        ->name('admin.factor-settings.update');

    Route::get('/admin/classification-results', [AdminClassificationResultsController::class, 'index'])
        ->name('admin.classification-results.index');
    Route::get('/admin/classification-results/{id}', [AdminClassificationResultsController::class, 'show'])
        ->name('admin.classification-results.show');

    Route::get('/admin/sectors', [AdminIndustrySectorsController::class, 'index'])
        ->name('admin.sectors.index');
    Route::post('/admin/sectors', [AdminIndustrySectorsController::class, 'store'])
        ->name('admin.sectors.store');
    Route::put('/admin/sectors/{id}', [AdminIndustrySectorsController::class, 'update'])
        ->whereNumber('id')
        ->name('admin.sectors.update');
    Route::delete('/admin/sectors/{id}', [AdminIndustrySectorsController::class, 'destroy'])
        ->whereNumber('id')
        ->name('admin.sectors.destroy');
});
