<?php

use App\Http\Controllers\CustomerPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/settings/customer-payments', [CustomerPaymentController::class, 'settings'])->name('customer-payments.settings');
    Route::post('/settings/customer-payments', [CustomerPaymentController::class, 'saveSettings'])->middleware('throttle:5,1')->name('customer-payments.settings.save');
    Route::get('/invoices/{id}/payment-link', [CustomerPaymentController::class, 'show'])->whereNumber('id')->name('customer-payments.invoice');
    Route::get('/invoices/{id}/payment-status', [CustomerPaymentController::class, 'status'])->whereNumber('id')->middleware('throttle:120,1')->name('customer-payments.status');
    Route::post('/invoices/{id}/payment-link', [CustomerPaymentController::class, 'create'])->whereNumber('id')->middleware('throttle:10,1')->name('customer-payments.create');
    Route::post('/invoices/{id}/payment-link/{linkId}', [CustomerPaymentController::class, 'action'])->whereNumber(['id','linkId'])->middleware('throttle:30,1')->name('customer-payments.action');
});
