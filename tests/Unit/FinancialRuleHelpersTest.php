<?php

use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;

it('normalizes payment methods to supported values', function () {
    expect(Payment::normalizeMethod('bank transfer'))->toBe('bank_transfer')
        ->and(Payment::normalizeMethod('BANK'))->toBe('bank_transfer')
        ->and(Payment::normalizeMethod('upi'))->toBe('upi')
        ->and(Payment::normalizeMethod('check'))->toBe('cheque')
        ->and(Payment::normalizeMethod('something-custom'))->toBe('other')
        ->and(Payment::normalizeMethod(''))->toBeNull();
});

it('derives invoice financial status from totals, payments, and due dates', function () {
    $yesterday = Carbon::now()->subDay();
    $tomorrow = Carbon::now()->addDay();

    expect(Invoice::determineFinancialStatus(0, 0, $tomorrow, 'unpaid'))->toBe('draft')
        ->and(Invoice::determineFinancialStatus(1000, 1000, $tomorrow, 'unpaid'))->toBe('paid')
        ->and(Invoice::determineFinancialStatus(1000, 250, $tomorrow, 'unpaid'))->toBe('partial')
        ->and(Invoice::determineFinancialStatus(1000, 0, $yesterday, 'unpaid'))->toBe('overdue')
        ->and(Invoice::determineFinancialStatus(1000, 0, $tomorrow, 'unpaid'))->toBe('unpaid')
        ->and(Invoice::determineFinancialStatus(1000, 0, $tomorrow, 'cancelled'))->toBe('cancelled');
});
