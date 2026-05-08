<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Sale;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class InvoiceSyncService
{
    public function createRentalInvoice(
        int $organizationId,
        Rental $rental,
        Organization $organization,
        bool $hasInvoiceRentalColumn,
        bool $hasRentalItemsTable,
        string $invoiceNumber,
        ?int $createdBy
    ): Invoice {
        $rental->loadMissing(['customer', 'product', 'renewals']);

        if ($hasRentalItemsTable) {
            $rental->loadMissing(['rentalItems.product']);
        }

        return DB::transaction(function () use (
            $organizationId,
            $rental,
            $organization,
            $hasInvoiceRentalColumn,
            $invoiceNumber,
            $createdBy
        ) {
            $invoice = Invoice::create([
                'organization_id' => $organizationId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'customer_id' => $rental->customer_id,
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
                'notes' => 'Auto-generated for rental #' . $rental->id,
                'terms_conditions' => $organization->default_terms,
                'created_by' => $createdBy,
            ]);

            if ($hasInvoiceRentalColumn) {
                $invoice->forceFill(['rental_id' => $rental->id])->save();
            }

            $subtotal = 0.0;
            foreach ($this->buildRentalLines($rental) as $baseLine) {
                $invoice->items()->create(array_merge($this->lineDefaults(), $baseLine));
                $subtotal += (float) $baseLine['line_total'];
            }

            $depositAmount = round((float) ($rental->deposit_amount ?? 0), 2);
            $transportAmount = round((float) ($rental->transport_amount ?? 0), 2);
            $otherAmount = round((float) ($rental->other_amount ?? 0), 2);

            $invoice->forceFill([
                'subtotal' => $subtotal,
                'taxable_amount' => $subtotal,
                'deposit_amount' => $depositAmount,
                'shipping_charges' => $transportAmount,
                'total_amount' => round($subtotal + $depositAmount + $transportAmount + $otherAmount, 2),
                'balance_amount' => $subtotal,
            ])->save();

            if ($otherAmount > 0) {
                $invoice->items()->create([
                    'product_id' => null,
                    'source_type' => 'manual',
                    'source_id' => null,
                    'description' => 'Other charge for rental #' . $rental->id,
                    'quantity' => 1,
                    'unit' => 'charge',
                    'days' => null,
                    'rate' => $otherAmount,
                    'discount_amount' => 0,
                    'taxable_amount' => $otherAmount,
                    'tax_percentage' => 0,
                    'tax_type' => 'cgst_sgst',
                    'cgst_rate' => 0,
                    'sgst_rate' => 0,
                    'igst_rate' => 0,
                    'cgst_amount' => 0,
                    'sgst_amount' => 0,
                    'igst_amount' => 0,
                    'line_total' => $otherAmount,
                ]);
            }

            $invoice->forceFill([
                'balance_amount' => round($subtotal + $depositAmount + $transportAmount + $otherAmount, 2),
            ])->save();

            $invoice->syncFinancialStatus();

            return $invoice;
        });
    }

    public function syncRentalInvoiceFromRental(
        int $organizationId,
        Rental $rental,
        Invoice $invoice,
        Organization $organization,
        bool $hasInvoiceRentalColumn,
        bool $hasRentalItemsTable
    ): void {
        $rental->loadMissing(['customer', 'product', 'renewals']);

        if ($hasRentalItemsTable) {
            $rental->loadMissing(['rentalItems.product']);
        }

        $invoice->forceFill([
            'organization_id' => $organizationId,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $rental->customer_id,
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
            'terms_conditions' => $organization->default_terms,
        ]);

        if ($hasInvoiceRentalColumn) {
            $invoice->forceFill(['rental_id' => $rental->id]);
        }

        $invoice->save();

        $invoice->items()
            ->where(function ($query) use ($rental) {
                $query->where(function ($rentalQuery) use ($rental) {
                    $rentalQuery
                        ->where('source_type', 'rental')
                        ->where('source_id', $rental->id);
                })->orWhere(function ($otherQuery) use ($rental) {
                    $otherQuery
                        ->where('source_type', 'manual')
                        ->where('description', 'Other charge for rental #' . $rental->id);
                });
            })
            ->delete();

        $subtotal = 0.0;
        foreach ($this->buildRentalLines($rental) as $baseLine) {
            $invoice->items()->create(array_merge($this->lineDefaults(), $baseLine));
            $subtotal += (float) $baseLine['line_total'];
        }

        $otherAmount = round((float) ($rental->other_amount ?? 0), 2);
        if ($otherAmount > 0) {
            $invoice->items()->create([
                'product_id' => null,
                'source_type' => 'manual',
                'source_id' => null,
                'description' => 'Other charge for rental #' . $rental->id,
                'quantity' => 1,
                'unit' => 'charge',
                'days' => null,
                'rate' => $otherAmount,
                'discount_amount' => 0,
                'taxable_amount' => $otherAmount,
                'tax_percentage' => 0,
                'tax_type' => 'cgst_sgst',
                'cgst_rate' => 0,
                'sgst_rate' => 0,
                'igst_rate' => 0,
                'cgst_amount' => 0,
                'sgst_amount' => 0,
                'igst_amount' => 0,
                'line_total' => $otherAmount,
            ]);
        }

        $preservedExtraTotal = (float) $invoice->items()
            ->where(function ($query) use ($rental) {
                $query
                    ->where('source_type', '!=', 'rental')
                    ->where('description', '!=', 'Other charge for rental #' . $rental->id);
            })
            ->sum('line_total');

        $depositAmount = round((float) ($rental->deposit_amount ?? 0), 2);
        $transportAmount = round((float) ($rental->transport_amount ?? 0), 2);

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'taxable_amount' => $subtotal,
            'deposit_amount' => $depositAmount,
            'shipping_charges' => $transportAmount,
            'total_amount' => round($subtotal + $depositAmount + $transportAmount + $otherAmount + $preservedExtraTotal, 2),
        ])->save();

        $invoice->syncFinancialStatus();
    }

    public function createSaleInvoiceFromPayload(
        int $organizationId,
        Sale $sale,
        bool $hasInvoiceSaleColumn,
        string $invoiceNumber,
        array $invoicePayload,
        array $itemPayload,
        bool $isSalePaid,
        ?int $createdBy
    ): Invoice {
        return DB::transaction(function () use (
            $organizationId,
            $sale,
            $hasInvoiceSaleColumn,
            $invoiceNumber,
            $invoicePayload,
            $itemPayload,
            $isSalePaid,
            $createdBy
        ) {
            $paidAmount = $isSalePaid ? (float) ($invoicePayload['total_amount'] ?? 0) : 0.0;
            $balanceAmount = $isSalePaid ? 0.0 : (float) ($invoicePayload['total_amount'] ?? 0);
            $financialStatus = $isSalePaid ? 'paid' : 'unpaid';

            $invoice = Invoice::create(array_merge(
                $invoicePayload,
                [
                    'organization_id' => $organizationId,
                    'invoice_number' => $invoiceNumber,
                    'status' => $financialStatus,
                    'payment_status' => $financialStatus,
                    'paid_amount' => $paidAmount,
                    'balance_amount' => $balanceAmount,
                    'created_by' => $createdBy,
                ]
            ));

            if ($hasInvoiceSaleColumn) {
                $invoice->forceFill(['sale_id' => $sale->id])->save();
            }

            $invoice->items()->create($itemPayload);
            $invoice->syncFinancialStatus();

            return $invoice;
        });
    }

    public function syncSaleInvoiceFromPayload(Invoice $invoice, array $invoicePayload, array $itemPayload, int $saleId): void
    {
        $invoice->update($invoicePayload);

        $saleItem = $invoice->items()
            ->where('source_type', 'sale')
            ->where('source_id', $saleId)
            ->first();

        if ($saleItem) {
            $saleItem->update($itemPayload);
        } else {
            $invoice->items()->create($itemPayload);
        }

        $invoice->refresh()->syncFinancialStatus();
    }

    private function buildRentalLines(Rental $rental): array
    {
        $rentalStartDate = $this->normalizeCarbonDate($rental->start_date);
        $rentalEndDate = $this->normalizeCarbonDate($rental->end_date);
        $rentalDays = null;

        if ($rentalStartDate && $rentalEndDate) {
            $rentalDays = max($rentalStartDate->startOfDay()->diffInDays($rentalEndDate->startOfDay()), 1);
        }

        $rentalLines = [];
        foreach ($rental->displayRentalItems() as $item) {
            $lineTotal = (float) ($item->line_total ?? 0);
            $quantity = max((float) ($item->quantity ?? 1), 1);
            $rate = $quantity > 0
                ? ((float) ($item->unit_rental_amount ?? 0) ?: round($lineTotal / $quantity, 2))
                : $lineTotal;

            $rentalLines[] = [
                'product_id' => $item->product_id ?: $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'description' => $item->product->name ?? $rental->product?->name ?? 'Rental product',
                'quantity' => $quantity,
                'unit' => 'rental',
                'days' => $rentalDays,
                'rate' => $rate,
                'taxable_amount' => $lineTotal,
                'line_total' => $lineTotal,
            ];
        }

        if ($rentalLines === []) {
            $rentalLines[] = [
                'product_id' => $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'description' => $rental->product?->name ?? 'Rental product',
                'quantity' => 1,
                'unit' => 'rental',
                'days' => $rentalDays,
                'rate' => (float) ($rental->rental_amount ?? 0),
                'taxable_amount' => (float) ($rental->rental_amount ?? 0),
                'line_total' => (float) ($rental->rental_amount ?? 0),
            ];
        }

        return $rentalLines;
    }

    private function lineDefaults(): array
    {
        return [
            'discount_amount' => 0,
            'tax_percentage' => 0,
            'tax_type' => 'cgst_sgst',
            'cgst_rate' => 0,
            'sgst_rate' => 0,
            'igst_rate' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
        ];
    }

    private function normalizeCarbonDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable $exception) {
                return null;
            }
        }

        return null;
    }
}
