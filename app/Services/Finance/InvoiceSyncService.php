<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Product;
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

        if (Rental::hasSaleItemsTable()) {
            $rental->loadMissing(['saleItems.product']);
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

            $invoiceLines = $this->buildRentalInvoiceLines($rental);
            $lineSummary = $this->summarizeInvoiceLines($invoiceLines);

            foreach ($invoiceLines as $baseLine) {
                $linePayload = $baseLine;
                unset($linePayload['line_subtotal']);
                $invoice->items()->create($linePayload);
            }

            $depositAmount = round((float) ($rental->deposit_amount ?? 0), 2);
            $transportAmount = round((float) ($rental->transport_amount ?? 0), 2);
            $otherAmount = round((float) ($rental->other_amount ?? 0), 2);

            $invoice->forceFill([
                'tax_type' => $this->resolveInvoiceTaxType($lineSummary),
                'subtotal' => $lineSummary['subtotal'],
                'taxable_amount' => $lineSummary['taxable_amount'],
                'deposit_amount' => $depositAmount,
                'shipping_charges' => $transportAmount,
                'cgst_amount' => $lineSummary['cgst_amount'],
                'sgst_amount' => $lineSummary['sgst_amount'],
                'igst_amount' => $lineSummary['igst_amount'],
                'total_tax_amount' => $lineSummary['total_tax_amount'],
                'total_amount' => round($lineSummary['line_total'] + $depositAmount + $transportAmount + $otherAmount, 2),
                'balance_amount' => round($lineSummary['line_total'] + $depositAmount + $transportAmount + $otherAmount, 2),
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
                'balance_amount' => round($lineSummary['line_total'] + $depositAmount + $transportAmount + $otherAmount, 2),
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

        if (Rental::hasSaleItemsTable()) {
            $rental->loadMissing(['saleItems.product']);
        }

        $invoice->forceFill([
            'organization_id' => $organizationId,
            'invoice_date' => $invoice->invoice_date ?: now()->toDateString(),
            'due_date' => $invoice->due_date ?: ($invoice->invoice_date ?: now()->toDateString()),
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
                })->orWhere(function ($saleQuery) {
                    $saleQuery->where('source_type', 'rental_sale');
                })->orWhere(function ($otherQuery) use ($rental) {
                    $otherQuery
                        ->where('source_type', 'manual')
                        ->where('description', 'Other charge for rental #' . $rental->id);
                });
            })
            ->delete();

        $invoiceLines = $this->buildRentalInvoiceLines($rental);
        $lineSummary = $this->summarizeInvoiceLines($invoiceLines);

        foreach ($invoiceLines as $baseLine) {
            $linePayload = $baseLine;
            unset($linePayload['line_subtotal']);
            $invoice->items()->create($linePayload);
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
                    ->whereNotIn('source_type', ['rental', 'rental_sale'])
                    ->where('description', '!=', 'Other charge for rental #' . $rental->id);
            })
            ->sum('line_total');

        $depositAmount = round((float) ($rental->deposit_amount ?? 0), 2);
        $transportAmount = round((float) ($rental->transport_amount ?? 0), 2);

        $invoice->forceFill([
            'tax_type' => $this->resolveInvoiceTaxType($lineSummary),
            'subtotal' => $lineSummary['subtotal'],
            'taxable_amount' => $lineSummary['taxable_amount'],
            'deposit_amount' => $depositAmount,
            'shipping_charges' => $transportAmount,
            'cgst_amount' => $lineSummary['cgst_amount'],
            'sgst_amount' => $lineSummary['sgst_amount'],
            'igst_amount' => $lineSummary['igst_amount'],
            'total_tax_amount' => $lineSummary['total_tax_amount'],
            'total_amount' => round($lineSummary['line_total'] + $depositAmount + $transportAmount + $otherAmount + $preservedExtraTotal, 2),
        ])->save();

        $invoice->syncFinancialStatus();
    }

    public function createSaleInvoiceFromPayload(
        int $organizationId,
        Sale $sale,
        bool $hasInvoiceSaleColumn,
        string $invoiceNumber,
        array $invoicePayload,
        array $itemPayloads,
        bool $isSalePaid,
        ?int $createdBy
    ): Invoice {
        return DB::transaction(function () use (
            $organizationId,
            $sale,
            $hasInvoiceSaleColumn,
            $invoiceNumber,
            $invoicePayload,
            $itemPayloads,
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

            foreach ($this->normalizeSaleItemPayloads($itemPayloads) as $itemPayload) {
                $invoice->items()->create($itemPayload);
            }
            $invoice->syncFinancialStatus();

            return $invoice;
        });
    }

    public function syncSaleInvoiceFromPayload(Invoice $invoice, array $invoicePayload, array $itemPayloads, int $saleId): void
    {
        $invoice->update($invoicePayload);

        $invoice->items()
            ->where('source_type', 'sale')
            ->where('source_id', $saleId)
            ->delete();

        foreach ($this->normalizeSaleItemPayloads($itemPayloads) as $itemPayload) {
            $invoice->items()->create($itemPayload);
        }

        $invoice->refresh()->syncFinancialStatus();
    }

    private function normalizeSaleItemPayloads(array $itemPayloads): array
    {
        if ($itemPayloads === []) {
            return [];
        }

        $isSinglePayload = array_key_exists('description', $itemPayloads) || array_key_exists('source_type', $itemPayloads);

        return $isSinglePayload ? [$itemPayloads] : array_values($itemPayloads);
    }

    private function buildRentalInvoiceLines(Rental $rental): array
    {
        return array_merge(
            $this->buildRentalLines($rental),
            $this->buildRentalSaleLines($rental),
        );
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
            $quantity = max((float) ($item->quantity ?? 1), 1);
            $subtotal = round($quantity * (float) ($item->unit_rental_amount ?? 0), 2);
            $lineTotal = round((float) ($item->line_total ?? $subtotal), 2);
            $rate = $quantity > 0
                ? ((float) ($item->unit_rental_amount ?? 0) ?: round(($subtotal > 0 ? $subtotal : $lineTotal) / $quantity, 2))
                : $lineTotal;
            $hasStoredTaxSnapshot = (float) ($item->gst_rate ?? 0) > 0
                || in_array($item->gst_mode, Product::GST_CALCULATION_MODES, true)
                || in_array($item->tax_type, Product::GST_TAX_TYPES, true)
                || (float) ($item->cgst_amount ?? 0) > 0
                || (float) ($item->sgst_amount ?? 0) > 0
                || (float) ($item->igst_amount ?? 0) > 0;
            $taxSnapshot = $this->lineTaxSnapshot(
                $subtotal > 0 ? $subtotal : $lineTotal,
                (float) ($item->gst_rate ?? 0),
                $item->gst_mode ?? 'exclusive',
                $item->tax_type ?? Product::GST_TAX_TYPE_CGST_SGST,
                $hasStoredTaxSnapshot ? ($item->taxable_amount ?? null) : null,
                $hasStoredTaxSnapshot ? ($item->cgst_amount ?? null) : null,
                $hasStoredTaxSnapshot ? ($item->sgst_amount ?? null) : null,
                $hasStoredTaxSnapshot ? ($item->igst_amount ?? null) : null,
                $item->line_total ?? null
            );

            $rentalLines[] = [
                'product_id' => $item->product_id ?: $rental->product_id,
                'source_type' => 'rental',
                'source_id' => $rental->id,
                'description' => $item->product->name ?? $rental->product?->name ?? 'Rental product',
                'quantity' => $quantity,
                'unit' => 'rental',
                'days' => $rentalDays,
                'rate' => $rate,
                'line_subtotal' => $taxSnapshot['subtotal'],
                'taxable_amount' => $taxSnapshot['taxable_amount'],
                'tax_percentage' => $taxSnapshot['gst_rate'],
                'tax_type' => $taxSnapshot['tax_type'],
                'cgst_rate' => $taxSnapshot['cgst_rate'],
                'sgst_rate' => $taxSnapshot['sgst_rate'],
                'igst_rate' => $taxSnapshot['igst_rate'],
                'cgst_amount' => $taxSnapshot['cgst_amount'],
                'sgst_amount' => $taxSnapshot['sgst_amount'],
                'igst_amount' => $taxSnapshot['igst_amount'],
                'line_total' => $taxSnapshot['line_total'],
            ] + $this->lineDefaults(['discount_amount' => 0]);
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
                'line_subtotal' => (float) ($rental->rental_amount ?? 0),
                'taxable_amount' => (float) ($rental->rental_amount ?? 0),
                'line_total' => (float) ($rental->rental_amount ?? 0),
            ] + $this->lineDefaults(['discount_amount' => 0]);
        }

        return $rentalLines;
    }

    private function buildRentalSaleLines(Rental $rental): array
    {
        if (!Rental::hasSaleItemsTable()) {
            return [];
        }

        $rental->loadMissing(['saleItems.product']);
        $saleLines = [];

        foreach ($rental->saleItems as $saleItem) {
            $product = $saleItem->product;
            $quantity = max((float) ($saleItem->quantity ?? 1), 1);
            $unitPrice = round((float) ($saleItem->unit_price ?? 0), 2);
            $subtotal = round($quantity * $unitPrice, 2);
            $hasStoredTaxSnapshot = (float) ($saleItem->gst_rate ?? 0) > 0
                || in_array($saleItem->gst_mode, Product::GST_CALCULATION_MODES, true)
                || in_array($saleItem->tax_type, Product::GST_TAX_TYPES, true)
                || (float) ($saleItem->cgst_amount ?? 0) > 0
                || (float) ($saleItem->sgst_amount ?? 0) > 0
                || (float) ($saleItem->igst_amount ?? 0) > 0;

            if ($hasStoredTaxSnapshot) {
                $taxSnapshot = $this->lineTaxSnapshot(
                    $subtotal,
                    (float) ($saleItem->gst_rate ?? 0),
                    $saleItem->gst_mode ?? 'exclusive',
                    $saleItem->tax_type ?? Product::GST_TAX_TYPE_CGST_SGST,
                    $saleItem->taxable_amount ?? null,
                    $saleItem->cgst_amount ?? null,
                    $saleItem->sgst_amount ?? null,
                    $saleItem->igst_amount ?? null,
                    $saleItem->line_total ?? null
                );
            } else {
                $productTaxType = in_array($product?->gst_tax_type, Product::GST_TAX_TYPES, true)
                    ? $product->gst_tax_type
                    : Product::GST_TAX_TYPE_CGST_SGST;
                $taxSnapshot = $this->lineTaxSnapshot(
                    $subtotal,
                    $productTaxType === Product::GST_TAX_TYPE_IGST
                        ? round((float) ($product?->igst_rate ?? 0), 2)
                        : round((float) ($product?->cgst_rate ?? 0), 2) + round((float) ($product?->sgst_rate ?? 0), 2),
                    in_array($product?->gst_calculation_mode, Product::GST_CALCULATION_MODES, true)
                        ? $product->gst_calculation_mode
                        : 'exclusive',
                    $productTaxType,
                    null,
                    null,
                    null,
                    null,
                    null
                );
            }

            $saleLines[] = [
                'product_id' => $saleItem->product_id,
                'source_type' => 'rental_sale',
                'source_id' => $saleItem->id,
                'description' => $product?->name ?? 'New product',
                'quantity' => $quantity,
                'unit' => 'sale',
                'days' => null,
                'rate' => $unitPrice,
                'line_subtotal' => $taxSnapshot['subtotal'],
                'taxable_amount' => $taxSnapshot['taxable_amount'],
                'tax_percentage' => $taxSnapshot['gst_rate'],
                'tax_type' => $taxSnapshot['tax_type'],
                'cgst_rate' => $taxSnapshot['cgst_rate'],
                'sgst_rate' => $taxSnapshot['sgst_rate'],
                'igst_rate' => $taxSnapshot['igst_rate'],
                'cgst_amount' => $taxSnapshot['cgst_amount'],
                'sgst_amount' => $taxSnapshot['sgst_amount'],
                'igst_amount' => $taxSnapshot['igst_amount'],
                'line_total' => $taxSnapshot['line_total'],
                'discount_amount' => 0,
            ];
        }

        return $saleLines;
    }

    private function summarizeInvoiceLines(array $lines): array
    {
        $subtotal = round((float) collect($lines)->sum(fn (array $line) => (float) ($line['line_subtotal'] ?? $line['line_total'] ?? 0)), 2);
        $taxableAmount = round((float) collect($lines)->sum(fn (array $line) => (float) ($line['taxable_amount'] ?? 0)), 2);
        $cgstAmount = round((float) collect($lines)->sum(fn (array $line) => (float) ($line['cgst_amount'] ?? 0)), 2);
        $sgstAmount = round((float) collect($lines)->sum(fn (array $line) => (float) ($line['sgst_amount'] ?? 0)), 2);
        $igstAmount = round((float) collect($lines)->sum(fn (array $line) => (float) ($line['igst_amount'] ?? 0)), 2);
        $totalTaxAmount = round($cgstAmount + $sgstAmount + $igstAmount, 2);
        $lineTotal = round((float) collect($lines)->sum(fn (array $line) => (float) ($line['line_total'] ?? 0)), 2);

        return [
            'subtotal' => $subtotal,
            'taxable_amount' => $taxableAmount,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'total_tax_amount' => $totalTaxAmount,
            'line_total' => $lineTotal,
        ];
    }

    private function resolveInvoiceTaxType(array $summary): string
    {
        if (($summary['igst_amount'] ?? 0) > 0 && ($summary['cgst_amount'] ?? 0) == 0.0 && ($summary['sgst_amount'] ?? 0) == 0.0) {
            return Product::GST_TAX_TYPE_IGST;
        }

        return Product::GST_TAX_TYPE_CGST_SGST;
    }

    private function lineTaxSnapshot(
        float $subtotal,
        float $gstRate,
        ?string $gstMode,
        ?string $taxType,
        mixed $storedTaxableAmount,
        mixed $storedCgstAmount,
        mixed $storedSgstAmount,
        mixed $storedIgstAmount,
        mixed $storedLineTotal
    ): array {
        $gstMode = $gstMode === 'inclusive' ? 'inclusive' : 'exclusive';
        $taxType = $taxType === Product::GST_TAX_TYPE_IGST
            ? Product::GST_TAX_TYPE_IGST
            : Product::GST_TAX_TYPE_CGST_SGST;
        $gstRate = round(max($gstRate, 0), 2);
        $subtotal = round(max($subtotal, 0), 2);

        if ($gstMode === 'inclusive' && $gstRate > 0) {
            $computedTaxable = round($subtotal / (1 + ($gstRate / 100)), 2);
            $computedTotalTax = round($subtotal - $computedTaxable, 2);
            $computedLineTotal = $subtotal;
        } else {
            $computedTaxable = $subtotal;
            $computedTotalTax = round(($computedTaxable * $gstRate) / 100, 2);
            $computedLineTotal = round($computedTaxable + $computedTotalTax, 2);
        }

        if ($taxType === Product::GST_TAX_TYPE_IGST) {
            $cgstRate = 0.0;
            $sgstRate = 0.0;
            $igstRate = $gstRate;
        } else {
            $cgstRate = round($gstRate / 2, 2);
            $sgstRate = round($gstRate - $cgstRate, 2);
            $igstRate = 0.0;
        }

        $taxableAmount = is_numeric($storedTaxableAmount) ? round((float) $storedTaxableAmount, 2) : $computedTaxable;
        $cgstAmount = is_numeric($storedCgstAmount) ? round((float) $storedCgstAmount, 2) : ($taxType === Product::GST_TAX_TYPE_CGST_SGST ? round($computedTotalTax / 2, 2) : 0.0);
        $sgstAmount = is_numeric($storedSgstAmount) ? round((float) $storedSgstAmount, 2) : ($taxType === Product::GST_TAX_TYPE_CGST_SGST ? round($computedTotalTax - $cgstAmount, 2) : 0.0);
        $igstAmount = is_numeric($storedIgstAmount) ? round((float) $storedIgstAmount, 2) : ($taxType === Product::GST_TAX_TYPE_IGST ? $computedTotalTax : 0.0);
        $lineTotal = is_numeric($storedLineTotal) ? round((float) $storedLineTotal, 2) : $computedLineTotal;

        return [
            'subtotal' => $subtotal,
            'gst_rate' => $gstRate,
            'gst_mode' => $gstMode,
            'tax_type' => $taxType,
            'taxable_amount' => $taxableAmount,
            'cgst_rate' => $cgstRate,
            'sgst_rate' => $sgstRate,
            'igst_rate' => $igstRate,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'line_total' => $lineTotal,
        ];
    }

    private function lineDefaults(array $overrides = []): array
    {
        return array_merge([
            'discount_amount' => 0,
            'tax_percentage' => 0,
            'tax_type' => 'cgst_sgst',
            'cgst_rate' => 0,
            'sgst_rate' => 0,
            'igst_rate' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
        ], $overrides);
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
