<?php

namespace App\Models;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleInventory extends Model
{
    protected $fillable = [
        'organization_id',
        'product_id',
        'warehouse_id',
        'quantity_in_stock',
        'reserved_quantity',
        'reorder_level',
        'purchase_cost',
    ];

    protected $casts = [
        'quantity_in_stock' => 'integer',
        'reserved_quantity' => 'integer',
        'reorder_level' => 'integer',
        'purchase_cost' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function availableToSell(): int
    {
        return max((int) $this->quantity_in_stock, 0);
    }

    public static function syncFromSaleUnits(int $organizationId, ?int $productId = null): void
    {
        $assetCounts = Asset::query()
            ->selectRaw("
                organization_id,
                product_id,
                warehouse_id,
                SUM(CASE WHEN asset_status = 'available_for_sale' THEN 1 ELSE 0 END) as available_count,
                SUM(CASE WHEN asset_status IN ('reserved_for_sale', 'reserved') THEN 1 ELSE 0 END) as reserved_count
            ")
            ->where('organization_id', $organizationId)
            ->where('asset_stage', Asset::STAGE_NEW_STOCK)
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->groupBy('organization_id', 'product_id', 'warehouse_id')
            ->get();

        $activeScopes = [];

        foreach ($assetCounts as $row) {
            $scope = [
                'organization_id' => (int) $row->organization_id,
                'product_id' => (int) $row->product_id,
                'warehouse_id' => (int) $row->warehouse_id,
            ];

            static::query()->updateOrCreate($scope, [
                'quantity_in_stock' => (int) $row->available_count,
                'reserved_quantity' => (int) ($row->reserved_count ?? 0),
            ]);

            $activeScopes[] = $scope;
        }

        $cleanupQuery = static::query()
            ->where('organization_id', $organizationId)
            ->when($productId, fn ($query) => $query->where('product_id', $productId));

        $cleanupQuery->get()->each(function (SaleInventory $inventory) use ($activeScopes) {
            $matches = collect($activeScopes)->contains(function (array $scope) use ($inventory) {
                return (int) $inventory->organization_id === $scope['organization_id']
                    && (int) $inventory->product_id === $scope['product_id']
                    && (int) $inventory->warehouse_id === $scope['warehouse_id'];
            });

            if (!$matches) {
                $inventory->update([
                    'quantity_in_stock' => 0,
                    'reserved_quantity' => 0,
                ]);
            }
        });
    }
}
