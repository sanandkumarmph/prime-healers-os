<?php

namespace App\Services\Imports;

class ImportMatchSignatureService
{
    public function rental(
        array $attributes,
        int $customerId,
        int $productId,
        ?int $dispatchWarehouseId,
        array $assetIds,
        int $quantity
    ): array {
        return [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'dispatch_warehouse_id' => $dispatchWarehouseId,
            'asset_ids' => $assetIds,
            'quantity' => $quantity,
            'start_date' => $attributes['start_date'] ?? null,
            'end_date' => $attributes['end_date'] ?? null,
            // Keep payment review behavior intact while excluding mutable fields
            // that should never influence match identity.
            'payment_status' => $attributes['payment_status'] ?? 'pending',
            'paid_amount' => $attributes['paid_amount'] ?? 0.0,
        ];
    }

    public function sale(
        array $attributes,
        int $customerId,
        int $productId,
        int $quantity,
        ?int $warehouseId
    ): array {
        return [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'sale_date' => $attributes['sale_date'] ?? null,
            'warehouse_id' => $warehouseId,
            // Keep payment review behavior intact while excluding mutable fields
            // that should never influence match identity.
            'payment_status' => $attributes['payment_status'] ?? 'pending',
            'paid_amount' => $attributes['paid_amount'] ?? 0.0,
        ];
    }
}
