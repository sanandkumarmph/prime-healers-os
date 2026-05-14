<?php

namespace App\Services\Metrics;

use App\Models\Asset;
use App\Models\Product;

class InventoryMetricsService
{
    public function summary(int $organizationId): array
    {
        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->withCount([
                'saleUnits as serialized_sale_units_available_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_AVAILABLE_FOR_SALE),
            ])
            ->get();

        $saleStockAvailable = (int) $products->sum(function (Product $product): int {
            if ($product->usesUntrackedStock()) {
                return $product->isSellableProduct()
                    ? max((int) ($product->available_quantity ?? 0), 0)
                    : 0;
            }

            return (int) ($product->serialized_sale_units_available_count ?? 0);
        });

        return [
            'saleStockAvailable' => $saleStockAvailable,
            'serializedSaleUnitsAvailable' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                ->where('asset_status', Asset::STATUS_AVAILABLE_FOR_SALE)
                ->count(),
            'rentalAssets' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->count(),
            'rentalAvailable' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->where('asset_status', Asset::STATUS_AVAILABLE)
                ->count(),
            'maintenanceAlerts' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                ->where('asset_status', Asset::STATUS_MAINTENANCE)
                ->count(),
        ];
    }
}
