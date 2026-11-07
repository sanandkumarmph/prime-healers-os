<?php

namespace App\Services\CustomerPayments;

use App\Models\CustomerPaymentLink;
use App\Models\Invoice;

class PaymentStatusSnapshot
{
    public function version(Invoice $invoice): string
    {
        $links = CustomerPaymentLink::where('organization_id', $invoice->organization_id)
            ->where('invoice_id', $invoice->id)->with('receipts')->orderBy('id')->get();

        // Ignore checked_at/updated_at: a gateway check alone is not a visible change.
        return hash('sha256', json_encode([
            $invoice->only(['id', 'total_amount', 'paid_amount', 'balance_amount', 'payment_status', 'status']),
            $links->map(fn ($link) => [$link->id, $link->status,
                $link->receipts->sortBy('id')->map(fn ($receipt) => [$receipt->id, $receipt->status, $receipt->payment_id])->values()->all(),
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
