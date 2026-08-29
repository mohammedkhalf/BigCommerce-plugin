<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\TamaraSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('store.context')->group(function (): void {
    Route::post('/settings', [TamaraSettingsController::class, 'save'])->name('settings.save');
    Route::post('/settings/test', [TamaraSettingsController::class, 'test'])->name('settings.test');
    Route::post('/settings/enable', [TamaraSettingsController::class, 'enable'])->name('settings.enable');
    Route::delete('/settings', [TamaraSettingsController::class, 'disconnect'])->name('settings.disconnect');
});

Route::post('/checkout/start', [CheckoutController::class, 'start'])->name('checkout.start');
Route::options('/checkout/start', [CheckoutController::class, 'options']);
