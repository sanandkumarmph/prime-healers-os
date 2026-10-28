<?php

namespace App\Services\Finance;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Rental;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentCorrectionService
{
    public function version(Payment $payment): string
    {
        return hash('sha256', json_encode($payment->only([
            'id', 'organization_id', 'invoice_id', 'rental_id', 'customer_id',
            'amount', 'payment_method', 'payment_date', 'notes', 'reversal_of_payment_id',
        ]), JSON_THROW_ON_ERROR));
    }

    public function reverse(Payment $snapshot, string $reason, string $version): Payment
    {
        Gate::authorize('delete', $snapshot);
        $org = (int) auth()->user()->organization_id;
        abort_unless((int) $snapshot->organization_id === $org, 404);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Enter a reason between 5 and 1,000 characters.']);
        }
        $sync = app(PaymentSyncService::class);

        return app(OrganizationFinancialTransaction::class)->run($org, function () use ($snapshot, $reason, $version, $org, $sync) {
            $invoice = $snapshot->invoice_id ? Invoice::where('organization_id', $org)->whereKey($snapshot->invoice_id)->lockForUpdate()->firstOrFail() : null;
            if ($snapshot->rental_id) {
                Rental::where('organization_id', $org)->whereKey($snapshot->rental_id)->lockForUpdate()->firstOrFail();
            }
            $payment = Payment::where('organization_id', $org)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($this->version($snapshot), $this->version($payment)) || ! hash_equals($this->version($payment), $version)) {
                throw ValidationException::withMessages(['payment' => 'This payment changed. Reload the correction form.']);
            }
            Gate::authorize('delete', $payment);
            if ((! $invoice && ! $payment->rental_id) || (float) $payment->amount <= 0 || $payment->reversal_of_payment_id) {
                throw ValidationException::withMessages(['payment' => 'Only an original positive payment linked to an invoice or rental can be reversed.']);
            }
            if (Schema::hasTable('delivery_collections') && DB::table('delivery_collections')->where('organization_id', $org)->where('payment_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'Use Correct / Reverse on the delivery collection so its verification and handover history stay consistent.']);
            }
            if (DB::table('rental_renewals')->where('organization_id', $org)->where('payment_id', $payment->id)->exists()
                || $payment->notes === 'Opening paid amount from invoice form.'
                || str_starts_with((string) $payment->notes, 'Auto-recorded from paid sale #')
                || str_starts_with((string) $payment->notes, 'Imported payment sync for sale #')
                || str_starts_with((string) $payment->notes, 'Imported payment sync for rental #')) {
                throw ValidationException::withMessages(['payment' => 'This payment is managed by its invoice opening balance, sale, renewal, or import. Correct it through that workflow; standalone reversal is unavailable.']);
            }
            if (Payment::where('organization_id', $org)->where('reversal_of_payment_id', $payment->id)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['payment' => 'This payment has already been reversed. No new entry was created.']);
            }
            $reversal = Payment::create([
                'organization_id' => $org, 'invoice_id' => $payment->invoice_id,
                'rental_id' => $payment->rental_id, 'customer_id' => $payment->customer_id,
                'payment_date' => today(), 'amount' => -(float) $payment->amount,
                'payment_method' => $payment->payment_method, 'reversal_of_payment_id' => $payment->id,
                'notes' => 'Entry correction of Payment #'.$payment->id.'. No customer refund recorded. Reason: '.$reason,
            ]);
            if ($invoice) {
                $sync->refreshInvoiceAfterPaymentDeletion($org, $invoice);
            }
            // Audit failure must roll back the reversal and recalculated balances.
            ActivityLog::create([
                'organization_id' => $org, 'user_id' => auth()->id(),
                'action' => 'payment.reversed', 'subject_type' => Payment::class,
                'subject_id' => $payment->id, 'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id, 'rental_id' => $payment->rental_id,
                'customer_id' => $payment->customer_id,
                'description' => 'Payment entry reversed; no customer refund recorded.',
                'properties' => ['reason' => $reason, 'original' => $payment->getAttributes(), 'reversal_payment_id' => $reversal->id],
            ]);

            return $reversal;
        });
    }
}

