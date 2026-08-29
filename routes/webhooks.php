<?php

use App\Http\Controllers\BigCommerceWebhookController;
use App\Http\Controllers\TamaraWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/tamara', TamaraWebhookController::class)->name('webhooks.tamara');
Route::post('/bigcommerce', BigCommerceWebhookController::class)->name('webhooks.bigcommerce');
