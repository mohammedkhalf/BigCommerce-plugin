<?php

use App\Http\Controllers\AdminPageController;
use App\Http\Controllers\BigCommerceOAuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MarketplacePreviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/marketplace-preview/{screen}', [MarketplacePreviewController::class, 'show'])
    ->whereIn('screen', ['onboarding', 'dashboard', 'payments', 'help'])
    ->name('marketplace.preview');

Route::get('/marketplace-preview-assets/{asset}', function (string $asset) {
    abort_unless(app()->environment('local'), 404);
    abort_unless(in_array($asset, ['icon-200', 'logo-primary', 'logo-alternate'], true), 404);

    return response()->file(base_path("docs/marketplace/{$asset}.svg"), [
        'Content-Type' => 'image/svg+xml',
    ]);
})->name('marketplace.preview-assets');

Route::controller(BigCommerceOAuthController::class)->group(function (): void {
    Route::get('/auth', 'auth')->name('bigcommerce.auth');
    Route::get('/load', 'load')->name('bigcommerce.load');
    Route::get('/uninstall', 'uninstall')->name('bigcommerce.uninstall');
    Route::get('/remove_user', 'removeUser')->name('bigcommerce.remove-user');
});

Route::get('/checkout/{result}', [CheckoutController::class, 'complete'])
    ->whereIn('result', ['success', 'failure', 'cancel'])
    ->name('checkout.complete');

Route::middleware('store.context')->controller(AdminPageController::class)->group(function (): void {
    Route::get('/dashboard', 'dashboard')->name('admin.dashboard');
    Route::get('/payments', 'payments')->name('admin.payments');
    Route::get('/payments/{payment}', 'payment')->name('admin.payments.show');
    Route::get('/settings', 'settings')->name('admin.settings');
    Route::get('/help', 'help')->name('admin.help');
});
