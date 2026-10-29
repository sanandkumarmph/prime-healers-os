<?php

use App\Http\Controllers\RentalAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/collection-review', [\App\Http\Controllers\DeliveryCollectionController::class, 'index'])->name('delivery-collections.index');
    Route::get('/collections/{collection}/proof', [\App\Http\Controllers\DeliveryCollectionController::class, 'proof'])->whereNumber('collection')->name('delivery-collections.proof');
    Route::get('/deliveries/{delivery}/collections', [\App\Http\Controllers\DeliveryCollectionController::class, 'show'])->whereNumber('delivery')->name('delivery-collections.show');
    Route::post('/deliveries/{delivery}/collections', [\App\Http\Controllers\DeliveryCollectionController::class, 'store'])->whereNumber('delivery')->middleware('throttle:30,1')->name('delivery-collections.store');
    Route::post('/collections/{collection}/review', [\App\Http\Controllers\DeliveryCollectionController::class, 'review'])->whereNumber('collection')->middleware('throttle:30,1')->name('delivery-collections.review');
    Route::post('/collections/{collection}/reverse', [\App\Http\Controllers\DeliveryCollectionController::class, 'reverse'])->whereNumber('collection')->middleware('throttle:30,1')->name('delivery-collections.reverse');
    Route::get('/cash-handovers', [\App\Http\Controllers\DeliveryCollectionController::class, 'cash'])->name('cash-handovers.index');
    Route::post('/cash-handovers/{collection}', [\App\Http\Controllers\DeliveryCollectionController::class, 'submitCash'])->whereNumber('collection')->middleware('throttle:30,1')->name('cash-handovers.store');
    Route::post('/cash-handovers/{handover}/confirm', [\App\Http\Controllers\DeliveryCollectionController::class, 'receiveCash'])->whereNumber('handover')->middleware('throttle:30,1')->name('cash-handovers.confirm');
    Route::post('/cash-handovers/{handover}/correct', [\App\Http\Controllers\DeliveryCollectionController::class, 'correctCash'])->whereNumber('handover')->middleware('throttle:30,1')->name('cash-handovers.correct');
    Route::get('/invoices/{id}/download', [\App\Http\Controllers\InvoiceController::class, 'download'])->middleware('permission:invoices.print')->name('invoices.download');
    Route::get('/workflow-guides', [\App\Http\Controllers\WorkflowGuideController::class, 'index'])->name('workflow-guides.index');
    Route::get('/business-overview', [\App\Http\Controllers\RentalController::class, 'compactDashboard'])->middleware('verified')->name('dashboard.compact');
    Route::get('/rental-availability', [RentalAvailabilityController::class, 'index'])->name('availability.index');
    Route::post('/rental-availability/check', [RentalAvailabilityController::class, 'check'])->middleware('throttle:60,1')->name('availability.check');
});
