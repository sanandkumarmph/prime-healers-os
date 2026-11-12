<?php

namespace App\Services\CustomerPayments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\OrganizationFinancialTransaction;
use App\Services\Finance\PaymentSyncService;
use Closure;
use Illuminate\Support\Facades\DB;

/** Use the same organization lock and ledger as PHOS's manual and delivery collections. */
class PaymentLinkLedger
{
    public function __construct(private PaymentSyncService $finance, private OrganizationFinancialTransaction $transactions) {}

    public function transaction(int $organizationId, Closure $callback): mixed
    {
        return $this->transactions->run($organizationId, $callback);
    }

    public function lockInvoice(int $organizationId, int $invoiceId): Invoice
    {
        return Invoice::where('organization_id', $organizationId)->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
    }

    public function assertPayable(Invoice $invoice): void
    {
        $this->finance->assertPayable($invoice);
    }

    public function currentPaymentTotal(Invoice $invoice): float
    {
        return (float) $invoice->payments()->sum('amount');
    }

    public function createInvoicePayment(int $organizationId, Invoice $invoice, array $data, ?int $rentalId, bool $nullable): Payment
    {
        // A validation failure rolls back the ledger savepoint while retaining the gateway receipt for review.
        return DB::transaction(function () use ($organizationId, $invoice, $data, $rentalId, $nullable) {
            $payment = $this->finance->createInvoicePayment($organizationId, $invoice,
                $data + ['collection_recipient' => 'company'], $rentalId, $nullable);
            $this->finance->refreshInvoiceAfterPaymentDeletion($organizationId, $invoice);
            return $payment;
        });
    }
}
