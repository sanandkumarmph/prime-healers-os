<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PaymentSyncService
{
    public function createInvoicePayment(
        int $organizationId,
        Invoice $invoice,
        array $validated,
        ?int $rentalId,
        bool $allowNullRentalId,
        ?string $exceedsMessage = null,
        ?string $nullableRentalIdMessage = null
    ): Payment {
        $invoice->syncFinancialStatus();
        $amount = (float) ($validated['amount'] ?? 0);

        if ($amount > (float) $invoice->balance_amount) {
            throw ValidationException::withMessages([
                'amount' => [$exceedsMessage ?: 'Payment amount cannot exceed the invoice balance due.'],
            ]);
        }

        $paymentData = [
            'organization_id' => $organizationId,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'payment_date' => $validated['payment_date'],
            'amount' => $amount,
            'payment_method' => Payment::normalizeMethod($validated['payment_method'] ?? null),
            'notes' => $validated['notes'] ?? null,
        ];

        if ($rentalId) {
            $paymentData['rental_id'] = $rentalId;
        } elseif (!$allowNullRentalId) {
            throw ValidationException::withMessages([
                'amount' => [$nullableRentalIdMessage ?: 'Payment could not be recorded because the payments table still requires rental_id.'],
            ]);
        }

        $payment = Payment::create($paymentData);
        $invoice->refresh()->syncFinancialStatus();

        return $payment;
    }

    public function recordPaidSaleInvoice(
        int $organizationId,
        Sale $sale,
        Invoice $invoice,
        float $amount,
        bool $paymentsRentalIdIsNullable
    ): void {
        if ($amount <= 0) {
            return;
        }

        $paymentData = [
            'organization_id' => $organizationId,
            'customer_id' => $sale->customer_id,
            'invoice_id' => $invoice->id,
            'payment_date' => $sale->sale_date ?: now()->toDateString(),
            'amount' => $amount,
            'payment_method' => 'other',
            'notes' => 'Auto-recorded from paid sale #' . $sale->id . '.',
        ];

        if ($sale->rental_id) {
            $paymentData['rental_id'] = $sale->rental_id;
            Payment::create($paymentData);

            return;
        }

        if ($paymentsRentalIdIsNullable) {
            Payment::create($paymentData);

            return;
        }

        $invoice->forceFill([
            'paid_amount' => (float) $invoice->total_amount,
            'balance_amount' => 0,
            'payment_status' => 'paid',
            'status' => 'paid',
        ])->save();
    }

    public function syncExistingSaleInvoicePayment(
        int $organizationId,
        Sale $sale,
        Invoice $invoice,
        bool $paymentsRentalIdIsNullable
    ): void {
        if ($sale->payment_status !== 'paid') {
            $this->autoPaidSalePaymentQuery($organizationId, $sale, $invoice)->delete();
            $invoice->refresh()->forceFill([
                'paid_amount' => (float) $invoice->payments()->sum('amount'),
            ])->syncFinancialStatus();

            return;
        }

        $invoice->syncFinancialStatus();

        if ((float) $invoice->balance_amount <= 0) {
            return;
        }

        $this->recordPaidSaleInvoice(
            $organizationId,
            $sale,
            $invoice,
            (float) $invoice->balance_amount,
            $paymentsRentalIdIsNullable
        );
        $invoice->refresh()->syncFinancialStatus();
    }

    public function syncSalePaymentStatusFromInvoice(Sale $sale, Invoice $invoice): void
    {
        $invoice->refresh()->syncFinancialStatus();

        $saleStatus = $this->saleStatusForInvoicePaymentStatus((string) $invoice->payment_status);

        if (($sale->payment_status ?? null) !== $saleStatus) {
            $sale->forceFill(['payment_status' => $saleStatus])->saveQuietly();
        }
    }

    public function refreshInvoiceAfterPaymentDeletion(int $organizationId, Invoice $invoice): void
    {
        $paymentsTotal = (float) $invoice->payments()->sum('amount');
        $totalAmount = (float) ($invoice->total_amount ?? 0);
        $balanceAmount = max($totalAmount - $paymentsTotal, 0);
        $paymentStatus = Invoice::determineFinancialStatus(
            $totalAmount,
            $paymentsTotal,
            $invoice->due_date,
            $invoice->status === 'cancelled' ? 'cancelled' : null
        );

        $invoice->forceFill([
            'paid_amount' => min($paymentsTotal, $totalAmount),
            'balance_amount' => $balanceAmount,
            'payment_status' => $paymentStatus,
            'status' => $paymentStatus,
        ])->save();

        $saleId = $invoice->sale_id ? (int) $invoice->sale_id : null;

        if (!$saleId) {
            $saleId = $invoice->items()
                ->where('source_type', 'sale')
                ->whereNotNull('source_id')
                ->value('source_id');
        }

        if ($saleId) {
            Sale::query()
                ->where('organization_id', $organizationId)
                ->where('id', (int) $saleId)
                ->update([
                    'payment_status' => $this->saleStatusForInvoicePaymentStatus($paymentStatus),
                ]);
        }
    }

    private function autoPaidSalePaymentQuery(int $organizationId, Sale $sale, Invoice $invoice): Builder
    {
        return Payment::query()
            ->where('organization_id', $organizationId)
            ->where('invoice_id', $invoice->id)
            ->where('notes', 'Auto-recorded from paid sale #' . $sale->id . '.');
    }

    private function saleStatusForInvoicePaymentStatus(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            'paid' => 'paid',
            'partial' => 'partial',
            'cancelled' => 'void',
            default => 'pending',
        };
    }
}
