<?php

namespace App\Services\Imports;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalImportExecutor
{
    public function createImportedRental(
        array $attributes,
        Customer $customer,
        Product $product,
        int $quantity,
        ?int $dispatchWarehouseId,
        string $notes,
        array $rentalItems,
        mixed $selectedAssets,
        array $callbacks
    ): array {
        return DB::transaction(function () use (
            $attributes,
            $customer,
            $product,
            $quantity,
            $dispatchWarehouseId,
            $notes,
            $rentalItems,
            $selectedAssets,
            $callbacks
        ) {
            $rental = Rental::create([
                'organization_id' => $callbacks['organization_id'],
                'customer_id' => $customer->id,
                'created_by_user_id' => $attributes['created_by_user_id'] ?? $callbacks['actor_user_id'],
                'customer_name' => $customer->name,
                'phone' => $callbacks['normalize_phone']($customer->phone),
                'product_id' => $product->id,
                'delivery_staff_id' => null,
                'pickup_staff_id' => null,
                'quantity' => $quantity,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
                'status' => 'active',
                'rental_amount' => (float) ($attributes['rental_amount'] ?? 0),
                'deposit_amount' => (float) ($attributes['deposit_amount'] ?? 0),
                'transport_amount' => (float) ($attributes['transport_amount'] ?? 0),
                'other_amount' => (float) ($attributes['other_amount'] ?? 0),
                'return_notes' => $notes !== '' ? $notes : null,
            ]);

            if ($callbacks['has_dispatch_warehouse_column']) {
                $rental->forceFill([
                    'dispatch_warehouse_id' => $dispatchWarehouseId,
                ])->save();
            }

            $callbacks['consume_rental_item_stock']($rentalItems);
            $callbacks['persist_rental_items']($rental, $rentalItems);

            $delivery = $this->applyImportedRentalDeliveryState(
                $rental,
                $attributes,
                false,
                $callbacks['organization_id'],
                $callbacks['has_delivery_assignment_type_column']
            );

            if ($delivery?->status === 'completed') {
                $callbacks['mark_imported_rental_delivered']($rental);
            }

            $callbacks['sync_rental_assets']($rental, $selectedAssets);

            $pickup = $this->applyImportedRentalPickupState(
                $rental,
                $attributes,
                false,
                $callbacks['organization_id'],
                $callbacks['has_delivery_assignment_type_column']
            );

            if ($pickup?->status === 'completed') {
                $rental->load('pickupRecord');
                $callbacks['reconcile_completed_pickup_progress']($rental);
            } elseif (($attributes['status'] ?? 'active') === 'returned') {
                $rental->forceFill([
                    'status' => 'returned',
                    'returned_at' => $attributes['returned_at'] ?? now()->toDateTimeString(),
                ])->save();
            }

            $invoice = $this->shouldCreateImportedRentalInvoice($attributes)
                ? $callbacks['create_rental_invoice']($rental)
                : null;

            if ($invoice) {
                $this->applyImportedRentalPaymentState($rental, $invoice, $attributes, $callbacks);
            }

            return [$rental->refresh(), $invoice?->refresh(), $delivery?->refresh(), $pickup?->refresh()];
        });
    }

    public function updateImportedRental(
        Rental $rental,
        array $attributes,
        Customer $customer,
        Product $product,
        ?int $dispatchWarehouseId,
        string $notes,
        array $rentalItems,
        mixed $selectedAssets,
        array $callbacks
    ): array {
        return DB::transaction(function () use (
            $rental,
            $attributes,
            $customer,
            $product,
            $dispatchWarehouseId,
            $notes,
            $rentalItems,
            $selectedAssets,
            $callbacks
        ) {
            $fieldWasProvided = $callbacks['import_field_was_provided'];

            $rental->forceFill([
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'phone' => $callbacks['normalize_phone']($customer->phone),
                'product_id' => $product->id,
                'quantity' => max((int) ($attributes['quantity'] ?? $rental->quantity), 1),
                'start_date' => $attributes['start_date'] ?? $rental->start_date,
                'end_date' => $attributes['end_date'] ?? $rental->end_date,
                'rental_amount' => $fieldWasProvided($attributes, 'rental_amount')
                    ? (float) ($attributes['rental_amount'] ?? 0)
                    : (float) ($rental->rental_amount ?? 0),
                'deposit_amount' => $fieldWasProvided($attributes, 'deposit_amount')
                    ? (float) ($attributes['deposit_amount'] ?? 0)
                    : (float) ($rental->deposit_amount ?? 0),
                'transport_amount' => $fieldWasProvided($attributes, 'transport_amount')
                    ? (float) ($attributes['transport_amount'] ?? 0)
                    : (float) ($rental->transport_amount ?? 0),
                'other_amount' => $fieldWasProvided($attributes, 'other_amount')
                    ? (float) ($attributes['other_amount'] ?? 0)
                    : (float) ($rental->other_amount ?? 0),
                'return_notes' => $fieldWasProvided($attributes, 'notes')
                    ? ($notes !== '' ? $notes : null)
                    : $rental->return_notes,
            ]);

            if ($callbacks['has_dispatch_warehouse_column'] && $dispatchWarehouseId) {
                $rental->forceFill(['dispatch_warehouse_id' => $dispatchWarehouseId]);
            }

            $rental->save();

            $callbacks['persist_rental_items']($rental, $rentalItems);

            if (!empty($attributes['asset_ids'])) {
                $callbacks['sync_rental_assets']($rental, $selectedAssets);
            }

            $delivery = $this->applyImportedRentalDeliveryState(
                $rental,
                $attributes,
                true,
                $callbacks['organization_id'],
                $callbacks['has_delivery_assignment_type_column']
            );
            if ($delivery?->status === 'completed') {
                $callbacks['mark_imported_rental_delivered']($rental);
            }

            $pickup = $this->applyImportedRentalPickupState(
                $rental,
                $attributes,
                true,
                $callbacks['organization_id'],
                $callbacks['has_delivery_assignment_type_column']
            );
            if ($pickup?->status === 'completed') {
                $rental->load('pickupRecord');
                $callbacks['reconcile_completed_pickup_progress']($rental);
            } elseif (($attributes['status'] ?? null) === 'returned') {
                $rental->forceFill([
                    'status' => 'returned',
                    'returned_at' => $attributes['returned_at'] ?? now()->toDateTimeString(),
                ])->save();
            }

            $invoice = $callbacks['rental_invoice']($rental);
            if ($invoice || $this->shouldCreateImportedRentalInvoice($attributes)) {
                $invoice = $invoice ?: $callbacks['create_rental_invoice']($rental);
                $callbacks['sync_rental_invoice_from_rental']($rental, $invoice);
                $this->applyImportedRentalPaymentState($rental, $invoice, $attributes, $callbacks);
            }

            return [$rental->refresh(), $invoice?->refresh(), $delivery?->refresh(), $pickup?->refresh()];
        });
    }

    public function shouldCreateImportedRentalInvoice(array $attributes): bool
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

    public function applyImportedRentalPaymentState(Rental $rental, Invoice $invoice, array $attributes, array $callbacks): void
    {
        $fieldWasProvided = $callbacks['import_field_was_provided'];
        $statusProvided = $fieldWasProvided($attributes, 'payment_status');
        $paidAmountProvided = $fieldWasProvided($attributes, 'paid_amount');

        if (!$statusProvided && !$paidAmountProvided && !$fieldWasProvided($attributes, 'payment_date')) {
            $invoice->refresh()->syncFinancialStatus();
            return;
        }

        $paymentStatus = strtolower(trim((string) ($attributes['payment_status'] ?? 'pending')));
        $requestedPaidAmount = match ($paymentStatus) {
            'paid' => round((float) ($invoice->total_amount ?? 0), 2),
            'partial' => round((float) ($attributes['paid_amount'] ?? 0), 2),
            default => 0.0,
        };

        $managedPayment = $callbacks['managed_imported_payment_query']($invoice, $rental->id)->first();
        $historicalPaymentsTotal = (float) $invoice->payments()
            ->when($managedPayment, fn ($query) => $query->whereKeyNot($managedPayment->id))
            ->sum('amount');

        if ($requestedPaidAmount + 0.01 < $historicalPaymentsTotal) {
            throw ValidationException::withMessages([
                'payment_status' => ['Existing recorded payments exceed the imported payment state for rental #'.$rental->id.'. Review this row manually.'],
            ]);
        }

        $managedAmount = round(max($requestedPaidAmount - $historicalPaymentsTotal, 0), 2);

        if ($managedAmount > 0) {
            $paymentDate = $attributes['payment_date']
                ?? $attributes['pickup_date']
                ?? $attributes['delivery_date']
                ?? $attributes['end_date']
                ?? $attributes['start_date']
                ?? now()->toDateString();

            if ($managedPayment) {
                $managedPayment->forceFill([
                    'payment_date' => $paymentDate,
                    'amount' => $managedAmount,
                    'payment_method' => Payment::normalizeMethod($attributes['payment_method'] ?? null),
                    'notes' => $callbacks['imported_payment_note']($rental->id),
                ])->save();
            } else {
                $callbacks['create_invoice_payment']($invoice, [
                    'payment_date' => $paymentDate,
                    'amount' => $managedAmount,
                    'payment_method' => $attributes['payment_method'] ?? 'other',
                    'notes' => $callbacks['imported_payment_note']($rental->id),
                ], $rental->id);
            }
        } elseif ($managedPayment) {
            $managedPayment->delete();
        }

        $invoice->refresh()->syncFinancialStatus();
    }

    public function applyImportedRentalDeliveryState(
        Rental $rental,
        array $attributes,
        bool $isUpdate,
        int $organizationId,
        bool $hasDeliveryAssignmentTypeColumn
    ): ?Delivery {
        $rawStatus = trim((string) ($attributes['delivery_status'] ?? ''));
        if ($isUpdate && $rawStatus === '') {
            return $rental->deliveryRecord()->first();
        }

        $importedStatus = strtolower($rawStatus);
        $rentalLifecycleStatus = strtolower(trim((string) ($attributes['status'] ?? 'active')));

        if ($importedStatus === '' || $importedStatus === 'not_assigned') {
            $importedStatus = $rentalLifecycleStatus === 'returned' ? 'completed' : 'pending';
        }

        if ($importedStatus === 'assigned') {
            $importedStatus = 'pending';
        }

        if (!in_array($importedStatus, ['pending', 'completed'], true)) {
            return null;
        }

        $scheduledAt = !empty($attributes['delivery_date'])
            ? Carbon::parse((string) $attributes['delivery_date'])->setTime(10, 0)
            : Carbon::parse((string) ($attributes['start_date'] ?? now()->toDateString()))->setTime(10, 0);

        $payload = [
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => $scheduledAt,
            'status' => $importedStatus,
            'notes' => $importedStatus === 'completed'
                ? 'Imported completed delivery for rental #'.$rental->id.'.'
                : 'Imported delivery assignment placeholder for rental #'.$rental->id.'.',
            'completed_at' => $importedStatus === 'completed' ? $scheduledAt : null,
        ];

        if ($hasDeliveryAssignmentTypeColumn) {
            $payload['assignment_type'] = 'delivery_team';
        }

        $existing = $rental->deliveryRecord()->first();

        if ($existing) {
            $existing->forceFill($payload)->save();
            return $existing->refresh();
        }

        return Delivery::create($payload);
    }

    public function applyImportedRentalPickupState(
        Rental $rental,
        array $attributes,
        bool $isUpdate,
        int $organizationId,
        bool $hasDeliveryAssignmentTypeColumn
    ): ?Delivery {
        $rawStatus = trim((string) ($attributes['pickup_status'] ?? ''));
        if ($isUpdate && $rawStatus === '') {
            return $rental->pickupRecord()->first();
        }

        $importedStatus = strtolower($rawStatus);
        $rentalLifecycleStatus = strtolower(trim((string) ($attributes['status'] ?? 'active')));

        if ($importedStatus === '' || $importedStatus === 'not_assigned') {
            if ($rentalLifecycleStatus !== 'returned') {
                return null;
            }

            $importedStatus = 'completed';
        }

        if ($importedStatus === 'assigned') {
            $importedStatus = 'pending';
        }

        if (!in_array($importedStatus, ['pending', 'completed'], true)) {
            return null;
        }

        $scheduledAt = !empty($attributes['pickup_date'])
            ? Carbon::parse((string) $attributes['pickup_date'])->setTime(10, 0)
            : Carbon::parse((string) ($attributes['end_date'] ?? now()->toDateString()))->setTime(10, 0);

        $payload = [
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => $scheduledAt,
            'status' => $importedStatus,
            'notes' => $importedStatus === 'completed'
                ? 'Imported completed pickup for rental #'.$rental->id.'.'
                : 'Imported pickup assignment placeholder for rental #'.$rental->id.'.',
            'completed_at' => $importedStatus === 'completed' ? $scheduledAt : null,
        ];

        if ($hasDeliveryAssignmentTypeColumn) {
            $payload['assignment_type'] = 'delivery_team';
        }

        $existing = $rental->pickupRecord()->first();

        if ($existing) {
            $existing->forceFill($payload)->save();
            return $existing->refresh();
        }

        return Delivery::create($payload);
    }
}
