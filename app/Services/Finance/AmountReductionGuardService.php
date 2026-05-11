<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

class AmountReductionGuardService
{
    public function assertTotalNotBelowReceivedPayments(Invoice $invoice, float $newTotal, string $contextLabel = 'This change'): void
    {
        $receivedPayments = round((float) $invoice->payments()->sum('amount'), 2);
        $newTotal = round(max($newTotal, 0), 2);

        if ($receivedPayments <= $newTotal + 0.009) {
            return;
        }

        throw ValidationException::withMessages([
            'finance' => [
                sprintf(
                    '%s would reduce the total below the received payment amount of Rs. %s. Delete the payment record or edit the payment first.',
                    $contextLabel,
                    number_format($receivedPayments, 2, '.', '')
                ),
            ],
        ]);
    }
}
