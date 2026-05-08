<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Product;
use App\Models\SaleInventory;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class InventoryDashboardController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    public function index(Request $request)
    {
        $organizationId = $this->orgId();
        $activeNewStockStatuses = ['available_for_sale', 'reserved_for_sale'];
        $stockView = (string) $request->get('stock_view', 'all');
        $rentalAssetsQuery = Asset::where('organization_id', $organizationId)
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK);
        $newStockAssetsQuery = Asset::where('organization_id', $organizationId)
            ->where('asset_stage', Asset::STAGE_NEW_STOCK)
            ->whereIn('asset_status', $activeNewStockStatuses);

        $dashboard = [
            'total_products' => Product::where('organization_id', $organizationId)->count(),
            'sellable_products' => Product::where('organization_id', $organizationId)->where('product_type', Product::TYPE_SELLABLE)->count(),
            'rentable_products' => Product::where('organization_id', $organizationId)->where('product_type', Product::TYPE_RENTABLE)->count(),
            'sale_stock' => (int) SaleInventory::where('organization_id', $organizationId)->sum('quantity_in_stock'),
            'total_assets' => (clone $rentalAssetsQuery)->count(),
            'available_assets' => (clone $rentalAssetsQuery)->where('asset_status', 'available')->count(),
            'rented_assets' => (clone $rentalAssetsQuery)->where('asset_status', 'rented')->count(),
            'maintenance_assets' => (clone $rentalAssetsQuery)->where('asset_status', 'maintenance')->count(),
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
                $rentalTotal = (int) $product->rental_assets_total_count;
                $rentalAvailable = (int) $product->rental_available_count;
                $rentalOut = (int) $product->rental_out_count;
                $awaitingVerification = (int) $product->awaiting_verification_count;
                $underRepair = (int) $product->under_repair_count;
                $retiredRental = (int) $product->retired_rental_count;
                $saleTotal = (int) $product->sale_units_total_count;
                $saleAvailable = (int) $product->sale_available_count;
                $saleReserved = (int) $product->sale_reserved_count;
                $soldUnits = (int) $product->sold_units_count;
                $retiredSale = (int) $product->retired_sale_count;

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
            'rental_active' => $productStockRows->filter(fn ($product) => ((int) $product->rental_assets_total_count) > 0)->values(),
            'sales_active' => $productStockRows->filter(fn ($product) => ((int) $product->sale_units_total_count) > 0)->values(),
            'low_stock' => $productStockRows->filter(fn ($product) => ((int) $product->sale_available_count) <= 1 && ((int) $product->sale_units_total_count) > 0
                || ((int) $product->rental_available_count) <= 1 && ((int) $product->rental_assets_total_count) > 0)->values(),
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
