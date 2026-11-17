<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\Webhooks\PixelforgeWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Pixelforge cannot send a CSRF token; requests are authenticated by their HMAC signature instead.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->name('generations.retry');

    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('billing/purchase', [BillingController::class, 'purchase'])->name('billing.purchase');

    Route::get('admin/report', ReportController::class)->name('admin.report');
});
