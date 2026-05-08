<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\RentalRenewal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RenewalFinanceService
{
    public function createRenewalInvoice(
        int $organizationId,
        Rental $rental,
        RentalRenewal $renewal,
        Organization $organization,
        string $invoiceNumber,
        ?int $createdBy
    ): Invoice {
        $rental->loadMissing(['customer', 'product']);

        return DB::transaction(function () use (
            $organizationId,
            $rental,
            $renewal,
            $organization,
            $invoiceNumber,
            $createdBy
        ) {
            $invoice = $renewal->invoice_id
                ? Invoice::query()->where('organization_id', $organizationId)->findOrFail($renewal->invoice_id)
                : Invoice::create([
                    'organization_id' => $organizationId,
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->toDateString(),
                    'customer_id' => $rental->customer_id,
                    'rental_id' => $rental->id,
                    'bill_to_name' => $rental->customer_name,
                    'bill_to_phone' => $rental->phone,
                    'bill_to_email' => $rental->customer?->email,
                    'bill_to_address' => $rental->customer?->address,
                    'bill_to_city' => $rental->customer?->city,
                    'bill_to_state' => $rental->customer?->state,
                    'bill_to_pincode' => $rental->customer?->pincode,
                    'ship_to_name' => $rental->customer_name,
                    'ship_to_phone' => $rental->phone,
                    'ship_to_address' => $rental->customer?->address,
                    'ship_to_city' => $rental->customer?->city,
                    'ship_to_state' => $rental->customer?->state,
                    'ship_to_pincode' => $rental->customer?->pincode,
                    'place_of_supply_state' => $rental->customer?->state,
                    'tax_type' => 'cgst_sgst',
                    'status' => 'unpaid',
                    'payment_status' => 'unpaid',
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'deposit_amount' => 0,
                    'shipping_charges' => 0,
                    'taxable_amount' => 0,
                    'cgst_amount' => 0,
                    'sgst_amount' => 0,
                    'igst_amount' => 0,
                    'total_tax_amount' => 0,
                    'total_amount' => 0,
                    'paid_amount' => 0,
                    'balance_amount' => 0,
                    'notes' => 'Auto-generated for rental renewal #' . $renewal->id,
                    'terms_conditions' => $organization->default_terms,
                    'created_by' => $createdBy,
                ]);

            $renewal->forceFill([
                'invoice_id' => $invoice->id,
            ])->save();

            $this->syncRenewalInvoicePaymentState($organizationId, $rental, $renewal->refresh());

            return $invoice->refresh();
        });
    }

    public function syncRenewalInvoiceStructure(Invoice $invoice, Rental $rental, RentalRenewal $renewal): void
    {
        $invoice->forceFill([
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'bill_to_name' => $rental->customer_name,
            'bill_to_phone' => $rental->phone,
            'bill_to_email' => $rental->customer?->email,
            'bill_to_address' => $rental->customer?->address,
            'bill_to_city' => $rental->customer?->city,
            'bill_to_state' => $rental->customer?->state,
            'bill_to_pincode' => $rental->customer?->pincode,
            'ship_to_name' => $rental->customer_name,
            'ship_to_phone' => $rental->phone,
            'ship_to_address' => $rental->customer?->address,
            'ship_to_city' => $rental->customer?->city,
            'ship_to_state' => $rental->customer?->state,
            'ship_to_pincode' => $rental->customer?->pincode,
            'place_of_supply_state' => $rental->customer?->state,
            'notes' => 'Auto-generated for rental renewal #' . $renewal->id,
        ])->save();

        $invoice->items()->delete();

        $renewalDepositAmount = round((float) ($renewal->deposit_amount_added ?? 0), 2);
        $renewalTransportAmount = round((float) ($renewal->transport_amount_added ?? 0), 2);
        $renewalOtherAmount = round((float) ($renewal->other_amount_added ?? 0), 2);

        $lines = [];

        if ((float) $renewal->rental_amount_added > 0) {
            $lines[] = [
                'product_id' => $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'description' => $rental->product?->name ?? 'Rental product',
                'quantity' => 1,
                'unit' => 'renewal',
                'days' => $renewal->renewal_days,
                'rate' => (float) $renewal->rental_amount_added,
                'taxable_amount' => (float) $renewal->rental_amount_added,
                'line_total' => (float) $renewal->rental_amount_added,
            ];
        }

        if ($lines === []) {
            $lines[] = [
                'product_id' => $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'description' => $rental->product?->name ?? 'Rental product',
                'quantity' => 1,
                'unit' => 'renewal',
                'days' => $renewal->renewal_days,
                'rate' => 0,
                'taxable_amount' => 0,
                'line_total' => 0,
            ];
        }

        $subtotal = 0.0;

        foreach ($lines as $line) {
            $invoice->items()->create(array_merge([
                'discount_amount' => 0,
                'tax_percentage' => 0,
                'tax_type' => 'cgst_sgst',
                'cgst_rate' => 0,
                'sgst_rate' => 0,
                'igst_rate' => 0,
                'cgst_amount' => 0,
                'sgst_amount' => 0,
                'igst_amount' => 0,
            ], $line));

            $subtotal += (float) $line['line_total'];
        }

        if ($renewalOtherAmount > 0) {
            $invoice->items()->create([
                'product_id' => null,
                'source_type' => 'manual',
                'source_id' => null,
                'description' => 'Renewal other charge for rental #' . $rental->id,
                'quantity' => 1,
                'unit' => 'charge',
                'days' => null,
                'rate' => $renewalOtherAmount,
                'discount_amount' => 0,
                'taxable_amount' => $renewalOtherAmount,
                'tax_percentage' => 0,
                'tax_type' => 'cgst_sgst',
                'cgst_rate' => 0,
                'sgst_rate' => 0,
                'igst_rate' => 0,
                'cgst_amount' => 0,
                'sgst_amount' => 0,
                'igst_amount' => 0,
                'line_total' => $renewalOtherAmount,
            ]);
        }

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'taxable_amount' => $subtotal,
            'deposit_amount' => $renewalDepositAmount,
            'shipping_charges' => $renewalTransportAmount,
            'total_amount' => round($subtotal + $renewalDepositAmount + $renewalTransportAmount + $renewalOtherAmount, 2),
            'balance_amount' => round($subtotal + $renewalDepositAmount + $renewalTransportAmount + $renewalOtherAmount, 2),
        ])->save();
    }

    public function syncRenewalManagedPayment(
        int $organizationId,
        Rental $rental,
        RentalRenewal $renewal,
        float $paymentAmount,
        ?string $paymentMethod,
        Carbon $paymentDate,
        ?string $notes
    ): ?Payment {
        $payment = $renewal->payment_id
            ? Payment::query()->where('organization_id', $organizationId)->find($renewal->payment_id)
            : null;

        $invoice = $renewal->invoice_id
            ? Invoice::query()->where('organization_id', $organizationId)->find($renewal->invoice_id)
            : null;

        if ($paymentAmount > 0) {
            $payload = [
                'organization_id' => $organizationId,
                'customer_id' => $rental->customer_id,
                'rental_id' => $rental->id,
                'invoice_id' => $invoice?->id,
                'payment_date' => $paymentDate,
                'amount' => $paymentAmount,
                'payment_method' => $paymentMethod,
                'notes' => $notes ?: 'Captured during rental renewal.',
            ];

            if ($payment) {
                $payment->forceFill($payload)->save();
            } else {
                $payment = Payment::create($payload);
            }
        } elseif ($payment) {
            $payment->delete();
            $payment = null;
        }

        return $payment;
    }

    public function syncRenewalInvoicePaymentState(int $organizationId, Rental $rental, RentalRenewal $renewal): void
    {
        if (!$renewal->invoice_id) {
            return;
        }

        $invoice = Invoice::query()
            ->where('organization_id', $organizationId)
            ->find($renewal->invoice_id);

        if (!$invoice) {
            return;
        }

        $this->syncRenewalInvoiceStructure($invoice, $rental, $renewal);

        if ($renewal->payment_id) {
            $payment = Payment::query()->where('organization_id', $organizationId)->find($renewal->payment_id);
            if ($payment && (int) ($payment->invoice_id ?? 0) !== (int) $invoice->id) {
                $payment->forceFill([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'rental_id' => $rental->id,
                ])->save();
            }
        }

        $invoice->refresh()->syncFinancialStatus();
    }
}
