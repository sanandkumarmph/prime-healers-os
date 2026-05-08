<?php

namespace App\Services\Imports;

use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleImportExecutor
{
    public function createImportedSale(array $salePayload, array $attributes, array $callbacks): array
    {
        return DB::transaction(function () use ($salePayload, $attributes, $callbacks) {
            $sale = Sale::create($salePayload);
            $callbacks['apply_sale_stock']($sale);
            $invoice = $this->shouldCreateImportedSaleInvoice($attributes)
                ? $callbacks['create_sale_invoice']($sale)
                : null;

            if ($invoice) {
                $this->applyImportedSalePaymentState($sale, $invoice, $attributes, $callbacks);
            }

            $delivery = $this->applyImportedSaleDeliveryState(
                $sale,
                $attributes,
                false,
                $callbacks['organization_id'],
                $callbacks['has_delivery_sale_column'],
                $callbacks['has_delivery_assignment_type_column']
            );

            return [$sale->refresh(), $invoice?->refresh(), $delivery?->refresh()];
        });
    }

    public function updateImportedSale(Sale $sale, array $salePayload, array $attributes, array $callbacks): array
    {
        return DB::transaction(function () use ($sale, $salePayload, $attributes, $callbacks) {
            $fieldWasProvided = $callbacks['import_field_was_provided'];

            $sale->forceFill([
                'customer_id' => $salePayload['customer_id'],
                'product_id' => $salePayload['product_id'],
                'quantity' => $salePayload['quantity'],
                'unit_price' => $salePayload['unit_price'],
                'sale_date' => $salePayload['sale_date'],
                'sale_amount' => $salePayload['sale_amount'],
                'discount_amount' => $fieldWasProvided($attributes, 'discount_amount')
                    ? $salePayload['discount_amount']
                    : $sale->discount_amount,
                'shipping_charges' => $fieldWasProvided($attributes, 'shipping_charges')
                    ? $salePayload['shipping_charges']
                    : $sale->shipping_charges,
                'tax_percentage' => $fieldWasProvided($attributes, 'tax_percentage')
                    ? $salePayload['tax_percentage']
                    : $sale->tax_percentage,
                'tax_calculation_mode' => $fieldWasProvided($attributes, 'tax_calculation_mode')
                    ? $salePayload['tax_calculation_mode']
                    : $sale->tax_calculation_mode,
                'notes' => $fieldWasProvided($attributes, 'notes')
                    ? $salePayload['notes']
                    : $sale->notes,
            ]);

            if ($callbacks['has_warehouse_column'] && !empty($salePayload['warehouse_id'])) {
                $sale->forceFill(['warehouse_id' => $salePayload['warehouse_id']]);
            }

            $sale->save();

            $invoice = $callbacks['sale_invoice']($sale);
            if ($invoice || $this->shouldCreateImportedSaleInvoice($attributes)) {
                if (!$invoice) {
                    $invoice = $callbacks['create_sale_invoice']($sale);
                } else {
                    $callbacks['sync_sale_invoice_from_sale']($sale, $invoice);
                }

                $this->applyImportedSalePaymentState($sale, $invoice, $attributes, $callbacks);
            }

            $delivery = $this->applyImportedSaleDeliveryState(
                $sale,
                $attributes,
                true,
                $callbacks['organization_id'],
                $callbacks['has_delivery_sale_column'],
                $callbacks['has_delivery_assignment_type_column']
            );

            return [$sale->refresh(), $invoice?->refresh(), $delivery?->refresh()];
        });
    }

    public function shouldCreateImportedSaleInvoice(array $attributes): bool
    {
        $invoiceStatus = strtolower(trim((string) ($attributes['invoice_status'] ?? '')));
        $paymentStatus = strtolower(trim((string) ($attributes['payment_status'] ?? 'pending')));

        if (in_array($paymentStatus, ['paid', 'partial'], true)) {
            return true;
        }

        return match ($invoiceStatus) {
            'not_generated' => false,
            'generated', 'paid', 'unpaid', 'pending', 'partial', '' => true,
            default => true,
        };
    }

    public function applyImportedSalePaymentState(Sale $sale, Invoice $invoice, array $attributes, array $callbacks): void
    {
        $fieldWasProvided = $callbacks['import_field_was_provided'];
        $statusProvided = $fieldWasProvided($attributes, 'payment_status');
        $paidAmountProvided = $fieldWasProvided($attributes, 'paid_amount');

        if (!$statusProvided && !$paidAmountProvided && !$fieldWasProvided($attributes, 'payment_date')) {
            $invoice->refresh()->syncFinancialStatus();
            $callbacks['sync_sale_payment_status_from_invoice']($sale->refresh(), $invoice->refresh());
            return;
        }

        $paymentStatus = strtolower(trim((string) ($attributes['payment_status'] ?? 'pending')));
        $requestedPaidAmount = match ($paymentStatus) {
            'paid' => round((float) ($invoice->total_amount ?? 0), 2),
            'partial' => round((float) ($attributes['paid_amount'] ?? 0), 2),
            default => 0.0,
        };

        $managedPayment = $callbacks['managed_imported_payment_query']($invoice, $sale->id)->first();
        $historicalPaymentsTotal = (float) $invoice->payments()
            ->when($managedPayment, fn ($query) => $query->whereKeyNot($managedPayment->id))
            ->sum('amount');

        if ($requestedPaidAmount + 0.01 < $historicalPaymentsTotal) {
            throw ValidationException::withMessages([
                'payment_status' => ['Existing recorded payments exceed the imported payment state for sale #'.$sale->id.'. Review this row manually.'],
            ]);
        }

        $managedAmount = round(max($requestedPaidAmount - $historicalPaymentsTotal, 0), 2);

        if ($managedAmount > 0) {
            $paymentDate = $attributes['payment_date'] ?? ($attributes['sale_date'] ?? now()->toDateString());

            if ($managedPayment) {
                $managedPayment->forceFill([
                    'payment_date' => $paymentDate,
                    'amount' => $managedAmount,
                    'payment_method' => Payment::normalizeMethod($attributes['payment_method'] ?? null),
                    'notes' => $callbacks['imported_payment_note']($sale->id),
                ])->save();
            } else {
                $callbacks['create_invoice_payment']($sale, $invoice, [
                    'payment_date' => $paymentDate,
                    'amount' => $managedAmount,
                    'payment_method' => $attributes['payment_method'] ?? 'other',
                    'notes' => $callbacks['imported_payment_note']($sale->id),
                ]);
            }
        } elseif ($managedPayment) {
            $managedPayment->delete();
        }

        $callbacks['sync_sale_payment_status_from_invoice']($sale->refresh(), $invoice->refresh());
    }

    public function applyImportedSaleDeliveryState(
        Sale $sale,
        array $attributes,
        bool $isUpdate,
        int $organizationId,
        bool $hasDeliverySaleColumn,
        bool $hasDeliveryAssignmentTypeColumn
    ): ?Delivery {
        $rawStatus = trim((string) ($attributes['delivery_status'] ?? ''));

        if ($isUpdate && $rawStatus === '') {
            return $sale->deliveryRecord()->first();
        }

        $deliveryStatus = strtolower($rawStatus);

        if ($deliveryStatus === '' || $deliveryStatus === 'not_assigned' || !$hasDeliverySaleColumn) {
            return null;
        }

        $scheduledAt = !empty($attributes['delivery_date'])
            ? Carbon::parse((string) $attributes['delivery_date'])
            : ($sale->sale_date ? Carbon::parse((string) $sale->sale_date) : now());

        $status = $deliveryStatus === 'completed' ? 'completed' : 'pending';

        $payload = [
            'organization_id' => $organizationId,
            'sale_id' => $sale->id,
            'type' => 'delivery',
            'scheduled_at' => $scheduledAt,
            'status' => $status,
            'notes' => $deliveryStatus === 'completed'
                ? 'Imported completed delivery for sale #'.$sale->id.'.'
                : 'Imported delivery assignment placeholder for sale #'.$sale->id.'.',
            'completed_at' => $deliveryStatus === 'completed' ? $scheduledAt : null,
        ];

        if ($hasDeliveryAssignmentTypeColumn) {
            $payload['assignment_type'] = 'delivery_team';
        }

        $existing = $sale->deliveryRecord()->first();

        if ($existing) {
            $existing->forceFill($payload)->save();
            return $existing->refresh();
        }

        return Delivery::create($payload);
    }
}
