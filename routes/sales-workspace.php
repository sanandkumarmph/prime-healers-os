<?php

use App\Http\Controllers\{QuotationController, SalesWorkspaceController};
use Illuminate\Support\Facades\Route;

Route::get('/q/{quotation}/pdf', [QuotationController::class, 'publicPdf'])->middleware([\App\Http\Middleware\VerifyQuotationLink::class, 'throttle:30,1'])->name('quotation-share.pdf');
Route::get('/q/{quotation}', [QuotationController::class, 'share'])->middleware([\App\Http\Middleware\VerifyQuotationLink::class, 'throttle:60,1'])->name('quotation-share.show');
Route::post('/q/{quotation}', [QuotationController::class, 'respond'])->middleware([\App\Http\Middleware\VerifyQuotationLink::class, 'throttle:12,1'])->name('quotation-share.respond');

Route::middleware(['auth'])->group(function () {
    Route::get('/enquiries', [SalesWorkspaceController::class, 'index'])->name('enquiries.index');
    Route::get('/enquiries/create', [SalesWorkspaceController::class, 'create'])->name('enquiries.create');
    Route::post('/enquiries', [SalesWorkspaceController::class, 'store'])->name('enquiries.store');
    Route::get('/enquiries/{enquiry}', [SalesWorkspaceController::class, 'show'])->name('enquiries.show');
    Route::get('/enquiries/{enquiry}/edit', [SalesWorkspaceController::class, 'edit'])->name('enquiries.edit');
    Route::put('/enquiries/{enquiry}', [SalesWorkspaceController::class, 'update'])->name('enquiries.update');
    Route::post('/enquiries/{enquiry}/stage', [SalesWorkspaceController::class, 'stage'])->name('enquiries.stage');
    Route::post('/enquiries/{enquiry}/notes', [SalesWorkspaceController::class, 'note'])->name('enquiries.note');
    Route::post('/enquiries/{enquiry}/follow-ups', [SalesWorkspaceController::class, 'followUp'])->name('enquiries.follow-up');
    Route::post('/enquiries/{enquiry}/follow-ups/{followUp}/complete', [SalesWorkspaceController::class, 'completeFollowUp'])->name('enquiries.follow-up.complete');
    Route::get('/enquiries/{enquiry}/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
    Route::post('/enquiries/{enquiry}/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('quotations.edit');
    Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
    Route::post('/quotations/{quotation}/actions', [QuotationController::class, 'action'])->name('quotations.action');
    Route::get('/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->name('quotations.pdf');
});
