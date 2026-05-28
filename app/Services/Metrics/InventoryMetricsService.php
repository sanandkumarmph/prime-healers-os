<?php

namespace App\Services\Metrics;

use App\Models\Asset;
use App\Models\Product;
use App\Services\Inventory\AssetStateService;

class InventoryMetricsService
{
    public function __construct(private readonly AssetStateService $assetStateService)
    {
    }

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

        $rentalSummary = $this->assetStateService->rentalSummary($organizationId);

        return [
            'saleStockAvailable' => $saleStockAvailable,
            'serializedSaleUnitsAvailable' => (int) Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                ->where('asset_status', Asset::STATUS_AVAILABLE_FOR_SALE)
                ->count(),
            'rentalAssets' => (int) ($rentalSummary['rentalAssets'] ?? 0),
            'rentalAvailable' => (int) ($rentalSummary['rentalAvailable'] ?? 0),
            'maintenanceAlerts' => (int) ($rentalSummary['maintenanceAlerts'] ?? 0),
        ];
    }
}
