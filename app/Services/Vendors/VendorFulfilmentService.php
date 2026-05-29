<?php

namespace App\Services\Vendors;

use App\Models\Rental;
use App\Models\Sale;
use App\Models\VendorOrderDetail;
use App\Support\ActivityLogger;

class VendorFulfilmentService
{
    public function syncRental(Rental $rental, array $attributes): VendorOrderDetail
    {
        $detail = VendorOrderDetail::firstOrNew(['rental_id' => $rental->id]);
        $wasRecentlyCreated = !$detail->exists;
        $originalVendorId = $detail->vendor_id;
        $originalDeliveryResponsibility = $detail->delivery_responsibility;
        $originalPickupResponsibility = $detail->pickup_responsibility;
        $originalVendorOrderStatus = $detail->vendor_order_status;

        $detail->fill([
            'organization_id' => (int) $rental->organization_id,
            'vendor_id' => $attributes['vendor_id'] ?? null,
            'sale_id' => null,
            'order_type' => VendorOrderDetail::ORDER_TYPE_RENTAL,
            'fulfilment_source' => $attributes['fulfilment_source'] ?? VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            'delivery_responsibility' => $attributes['delivery_responsibility'] ?? null,
            'pickup_responsibility' => $attributes['pickup_responsibility'] ?? null,
            'vendor_order_status' => $attributes['vendor_order_status'] ?? 'draft',
            'notes' => $attributes['notes'] ?? null,
        ])->save();

        $this->logTimeline(
            $rental,
            $detail,
            $wasRecentlyCreated,
            $originalVendorId,
            $originalDeliveryResponsibility,
            $originalPickupResponsibility,
            $originalVendorOrderStatus
        );

        return $detail->refresh();
    }

    public function syncSale(Sale $sale, array $attributes): VendorOrderDetail
    {
        $detail = VendorOrderDetail::firstOrNew(['sale_id' => $sale->id]);
        $wasRecentlyCreated = !$detail->exists;
        $originalVendorId = $detail->vendor_id;
        $originalDeliveryResponsibility = $detail->delivery_responsibility;
        $originalVendorOrderStatus = $detail->vendor_order_status;

        $detail->fill([
            'organization_id' => (int) $sale->organization_id,
            'vendor_id' => $attributes['vendor_id'] ?? null,
            'rental_id' => null,
            'order_type' => VendorOrderDetail::ORDER_TYPE_SALE,
            'fulfilment_source' => $attributes['fulfilment_source'] ?? VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            'delivery_responsibility' => $attributes['delivery_responsibility'] ?? null,
            'pickup_responsibility' => null,
            'vendor_order_status' => $attributes['vendor_order_status'] ?? 'draft',
            'notes' => $attributes['notes'] ?? null,
        ])->save();

        $this->logTimeline(
            $sale,
            $detail,
            $wasRecentlyCreated,
            $originalVendorId,
            $originalDeliveryResponsibility,
            null,
            $originalVendorOrderStatus
        );

        return $detail->refresh();
    }

    public function syncCosts(VendorOrderDetail $detail, array $attributes): VendorOrderDetail
    {
        $detail->fill([
            'procurement_cost' => $attributes['procurement_cost'] ?? $detail->procurement_cost ?? 0,
            'vendor_delivery_cost' => $attributes['vendor_delivery_cost'] ?? $detail->vendor_delivery_cost ?? 0,
            'vendor_pickup_cost' => $attributes['vendor_pickup_cost'] ?? $detail->vendor_pickup_cost ?? 0,
            'other_vendor_cost' => $attributes['other_vendor_cost'] ?? $detail->other_vendor_cost ?? 0,
            'vendor_invoice_number' => $attributes['vendor_invoice_number'] ?? $detail->vendor_invoice_number,
            'vendor_payment_status' => $attributes['vendor_payment_status'] ?? $detail->vendor_payment_status ?? 'pending',
            'vendor_paid_at' => $attributes['vendor_paid_at'] ?? $detail->vendor_paid_at,
            'vendor_order_status' => $attributes['vendor_order_status'] ?? $detail->vendor_order_status ?? 'draft',
            'notes' => $attributes['notes'] ?? $detail->notes,
        ]);
        $detail->save();

        if (filled($attributes['vendor_invoice_number'] ?? null)) {
            ActivityLogger::log('vendor.invoice_uploaded', $detail->sale ?? $detail->rental ?? $detail, [
                'vendor_order_detail_id' => $detail->id,
                'vendor_invoice_number' => $detail->vendor_invoice_number,
            ], 'Vendor invoice recorded.');
        }

        if (($detail->vendor_payment_status ?? null) === 'paid') {
            ActivityLogger::log('vendor.payment_completed', $detail->sale ?? $detail->rental ?? $detail, [
                'vendor_order_detail_id' => $detail->id,
                'vendor_paid_at' => optional($detail->vendor_paid_at)->toDateTimeString(),
                'vendor_paid_amount' => $detail->vendorPaidAmount(),
            ], 'Vendor payment marked completed.');
        }

        return $detail->refresh();
    }

    private function logTimeline(
        Rental|Sale $order,
        VendorOrderDetail $detail,
        bool $wasRecentlyCreated,
        ?int $originalVendorId,
        ?string $originalDeliveryResponsibility,
        ?string $originalPickupResponsibility,
        ?string $originalVendorOrderStatus
    ): void {
        if ($wasRecentlyCreated || $originalVendorId !== $detail->vendor_id) {
            ActivityLogger::log('vendor.assigned', $order, [
                'vendor_order_detail_id' => $detail->id,
                'vendor_id' => $detail->vendor_id,
                'fulfilment_source' => $detail->fulfilment_source,
            ], 'Vendor assigned to fulfilment.');
        }

        if ($detail->delivery_responsibility === 'vendor_delivery' && $originalDeliveryResponsibility !== 'vendor_delivery') {
            ActivityLogger::log('vendor.delivery_scheduled', $order, [
                'vendor_order_detail_id' => $detail->id,
                'vendor_id' => $detail->vendor_id,
            ], 'Vendor delivery scheduled.');
        }

        if ($detail->pickup_responsibility === 'vendor_pickup' && $originalPickupResponsibility !== 'vendor_pickup') {
            ActivityLogger::log('vendor.pickup_scheduled', $order, [
                'vendor_order_detail_id' => $detail->id,
                'vendor_id' => $detail->vendor_id,
            ], 'Vendor pickup scheduled.');
        }

        if (($detail->vendor_order_status ?? null) === 'completed' && $originalVendorOrderStatus !== 'completed') {
            ActivityLogger::log('vendor.fulfilment_completed', $order, [
                'vendor_order_detail_id' => $detail->id,
                'vendor_id' => $detail->vendor_id,
            ], 'Vendor fulfilment completed.');
        }
    }
}
