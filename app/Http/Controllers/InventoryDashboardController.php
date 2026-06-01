<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Product;
use App\Models\SaleInventory;
use App\Models\Warehouse;
use App\Services\Metrics\InventoryMetricsService;
use Illuminate\Http\Request;

class InventoryDashboardController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function inventoryMetrics(): InventoryMetricsService
    {
        return app(InventoryMetricsService::class);
    }

    public function index(Request $request)
    {
        $organizationId = $this->orgId();
        $inventorySummary = $this->inventoryMetrics()->summary($organizationId);
        $activeNewStockStatuses = ['available_for_sale', 'reserved_for_sale'];
        $stockView = (string) $request->get('stock_view', 'all');
        $rentalAssetsQuery = Asset::where('organization_id', $organizationId)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK);
        $newStockAssetsQuery = Asset::where('organization_id', $organizationId)
            ->where('asset_stage', Asset::STAGE_NEW_STOCK)
            ->whereIn('asset_status', $activeNewStockStatuses);

        $dashboard = [
            'total_products' => Product::where('organization_id', $organizationId)->count(),
            'sellable_products' => Product::where('organization_id', $organizationId)->whereIn('product_type', [Product::TYPE_SELLABLE, Product::TYPE_BOTH])->count(),
            'rentable_products' => Product::where('organization_id', $organizationId)->whereIn('product_type', [Product::TYPE_RENTABLE, Product::TYPE_BOTH])->count(),
            'sale_stock' => (int) ($inventorySummary['saleStockAvailable'] ?? 0),
            'serialized_sale_units' => (int) ($inventorySummary['serializedSaleUnitsAvailable'] ?? 0),
            'total_assets' => (int) ($inventorySummary['rentalAssets'] ?? 0),
            'available_assets' => (int) ($inventorySummary['rentalAvailable'] ?? 0),
            'rented_assets' => (clone $rentalAssetsQuery)->where('asset_status', 'rented')->count(),
            'maintenance_assets' => (int) ($inventorySummary['maintenanceAlerts'] ?? 0),
            'warehouse_count' => Warehouse::where('organization_id', $organizationId)->count(),
        ];

        $recentMovements = AssetMovement::with(['asset.product', 'fromWarehouse', 'toWarehouse', 'movedBy'])
            ->where('organization_id', $organizationId)
            ->whereHas('asset', function ($query) {
                $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK);
            })
            ->latest()
            ->take(8)
            ->get();

        $warehouseSummaries = Warehouse::withCount([
            'assets as total_assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK),
            'assets as available_assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'available'),
            'assets as rented_assets_count' => fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->where('asset_status', 'rented'),
        ])
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->take(6)
            ->get();

        $productStockRows = Product::query()
            ->where('organization_id', $organizationId)
            ->withCount([
                'rentalUnits as rental_assets_total_count',
                'rentalUnits as rental_available_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_AVAILABLE),
                'rentalUnits as rental_out_count' => fn ($query) => $query->whereIn('asset_status', [
                    Asset::STATUS_RENTED,
                    Asset::STATUS_RESERVED,
                ]),
                'rentalUnits as awaiting_verification_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_AWAITING_VERIFICATION),
                'rentalUnits as under_repair_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_MAINTENANCE),
                'rentalUnits as retired_rental_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_RETIRED),
                'saleUnits as sale_units_total_count',
                'saleUnits as sale_available_count' => fn ($query) => $query->whereIn('asset_status', [
                    Asset::STATUS_AVAILABLE_FOR_SALE,
                    Asset::STATUS_AVAILABLE,
                ]),
                'saleUnits as sale_reserved_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_RESERVED_FOR_SALE),
                'saleUnits as sold_units_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_SOLD),
                'saleUnits as retired_sale_count' => fn ($query) => $query->where('asset_status', Asset::STATUS_RETIRED),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) {
                $usesUntrackedStock = $product->usesUntrackedStock();
                $openingTotalQuantity = max((int) ($product->total_quantity ?? 0), 0);
                $openingAvailableQuantity = max((int) ($product->available_quantity ?? 0), 0);

                $rentalTotal = $usesUntrackedStock && $product->canRent()
                    ? $openingTotalQuantity
                    : (int) $product->rental_assets_total_count;
                $rentalAvailable = $usesUntrackedStock && $product->canRent()
                    ? $openingAvailableQuantity
                    : (int) $product->rental_available_count;
                $rentalOut = (int) $product->rental_out_count;
                $awaitingVerification = (int) $product->awaiting_verification_count;
                $underRepair = (int) $product->under_repair_count;
                $retiredRental = (int) $product->retired_rental_count;
                $saleTotal = $usesUntrackedStock && $product->canSell()
                    ? $openingTotalQuantity
                    : (int) $product->sale_units_total_count;
                $saleAvailable = $usesUntrackedStock && $product->canSell()
                    ? $openingAvailableQuantity
                    : (int) $product->sale_available_count;
                $saleReserved = (int) $product->sale_reserved_count;
                $soldUnits = (int) $product->sold_units_count;
                $retiredSale = (int) $product->retired_sale_count;

                $product->setAttribute('effective_rental_assets_total_count', $rentalTotal);
                $product->setAttribute('effective_rental_available_count', $rentalAvailable);
                $product->setAttribute('effective_sale_units_total_count', $saleTotal);
                $product->setAttribute('effective_sale_available_count', $saleAvailable);

                $signals = collect();

                if ($rentalAvailable === 0 && $rentalOut > 0) {
                    $signals->push(['label' => 'Fully Deployed', 'tone' => 'info']);
                }

                if ($saleAvailable > 0 && $saleAvailable <= 1) {
                    $signals->push(['label' => 'Low Sale Stock', 'tone' => 'warning']);
                }

                if ($rentalAvailable <= 1 && $rentalTotal > 0) {
                    $signals->push(['label' => 'Low Rental Stock', 'tone' => 'warning']);
                }

                if ($awaitingVerification > 0) {
                    $signals->push(['label' => 'Awaiting Verification Pending', 'tone' => 'danger']);
                }

                if ($rentalOut >= 3) {
                    $signals->push(['label' => 'High Rentals', 'tone' => 'success']);
                }

                if (($retiredRental + $retiredSale) > 0) {
                    $signals->push(['label' => 'Retired Units Present', 'tone' => 'info']);
                }

                if ($saleReserved > 0) {
                    $signals->push(['label' => 'Sale Units Reserved', 'tone' => 'warning']);
                }

                $product->setAttribute('stock_signals', $signals->values());

                return $product;
            });

        $productStockRows = match ($stockView) {
            'rental_active' => $productStockRows->filter(fn ($product) => ((int) $product->effective_rental_assets_total_count) > 0)->values(),
            'sales_active' => $productStockRows->filter(fn ($product) => ((int) $product->effective_sale_units_total_count) > 0)->values(),
            'low_stock' => $productStockRows->filter(fn ($product) => ((int) $product->effective_sale_available_count) <= 1 && ((int) $product->effective_sale_units_total_count) > 0
                || ((int) $product->effective_rental_available_count) <= 1 && ((int) $product->effective_rental_assets_total_count) > 0)->values(),
            'awaiting_verification' => $productStockRows->filter(fn ($product) => ((int) $product->awaiting_verification_count) > 0)->values(),
            default => $productStockRows,
        };

        return view('inventory.dashboard', compact(
            'dashboard',
            'recentMovements',
            'warehouseSummaries',
            'productStockRows',
            'stockView'
        ));
    }
}
