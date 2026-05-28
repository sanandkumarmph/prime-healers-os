<?php

namespace App\Services\Inventory;

use App\Models\Asset;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class StockMovementRecorder
{
    public function record(array $attributes): ?StockMovement
    {
        $movementType = strtolower(trim((string) ($attributes['movement_type'] ?? '')));
        $quantity = max((int) ($attributes['quantity'] ?? 0), 0);
        $movementAt = $attributes['movement_at'] ?? Carbon::now();

        if ($movementType === '' || $quantity <= 0) {
            return null;
        }

        if (!in_array($movementType, StockMovement::MOVEMENT_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported stock movement type: '.$movementType);
        }

        $payload = [
            'organization_id' => (int) $attributes['organization_id'],
            'product_id' => $attributes['product_id'] ?? null,
            'asset_id' => $attributes['asset_id'] ?? null,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'from_status' => $attributes['from_status'] ?? null,
            'to_status' => $attributes['to_status'] ?? null,
            'from_warehouse_id' => $attributes['from_warehouse_id'] ?? null,
            'to_warehouse_id' => $attributes['to_warehouse_id'] ?? null,
            'rental_id' => $attributes['rental_id'] ?? null,
            'sale_id' => $attributes['sale_id'] ?? null,
            'delivery_id' => $attributes['delivery_id'] ?? null,
            'invoice_id' => $attributes['invoice_id'] ?? null,
            'payment_id' => $attributes['payment_id'] ?? null,
            'performed_by_user_id' => $attributes['performed_by_user_id'] ?? auth()->id(),
            'movement_at' => $movementAt,
            'notes' => $attributes['notes'] ?? null,
        ];

        $payload['checksum'] = StockMovement::checksumFor($payload);

        return StockMovement::create($payload);
    }

    public function recordForProduct(Product $product, string $movementType, int $quantity, array $attributes = []): ?StockMovement
    {
        return $this->record(array_merge($attributes, [
            'organization_id' => $product->organization_id,
            'product_id' => $product->id,
            'movement_type' => $movementType,
            'quantity' => $quantity,
        ]));
    }

    public function recordForAsset(Asset $asset, string $movementType, int $quantity = 1, array $attributes = []): ?StockMovement
    {
        return $this->record(array_merge($attributes, [
            'organization_id' => $asset->organization_id,
            'product_id' => $asset->product_id,
            'asset_id' => $asset->id,
            'movement_type' => $movementType,
            'quantity' => $quantity,
        ]));
    }
}
