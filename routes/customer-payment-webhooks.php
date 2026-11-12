<?php

use App\Http\Controllers\CustomerPaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/customer-payments/webhook/{token}', CustomerPaymentWebhookController::class)
    ->whereUuid('token')->middleware('throttle:600,1')->name('customer-payments.webhook');
