@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
    $canDeleteProducts = $currentUser?->canAccessModule('products', 'delete') ?? false;
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $canViewProductStockHistory = $currentUser?->hasAnyPermission(['stock_history.view', 'stock_history.product']) ?? false;
    $rupee = html_entity_decode('&#8377;');

    $statusBadge = fn ($status) => match($status) {
        \App\Models\Asset::STATUS_AVAILABLE => ['#ecfdf5', '#166534'],
        \App\Models\Asset::STATUS_AWAITING_VERIFICATION => ['#fef3c7', '#b45309'],
        \App\Models\Asset::STATUS_RENTED => ['#eff6ff', '#1d4ed8'],
        \App\Models\Asset::STATUS_MAINTENANCE => ['#fff7ed', '#c2410c'],
        \App\Models\Asset::STATUS_RESERVED => ['#f5f3ff', '#6d28d9'],
        \App\Models\Asset::STATUS_RETIRED => ['#f8fafc', '#475569'],
        \App\Models\Asset::STATUS_AVAILABLE_FOR_SALE => ['#fff7ed', '#9a3412'],
        \App\Models\Asset::STATUS_RESERVED_FOR_SALE => ['#fef3c7', '#b45309'],
        \App\Models\Asset::STATUS_SOLD => ['#f3f4f6', '#4b5563'],
        \App\Models\Asset::STATUS_CONVERTED_TO_RENTAL => ['#ecfeff', '#0f766e'],
        default => ['#f8fafc', '#334155'],
    };

    $canSell = $product->canSell();
    $canRent = $product->canRent();
    $usesUntrackedStock = $product->usesUntrackedStock();
    $productType = $canSell && $canRent
        ? \App\Models\Product::TYPE_BOTH
        : ($canRent ? \App\Models\Product::TYPE_RENTABLE : \App\Models\Product::TYPE_SELLABLE);
    $productTypeLabel = match (true) {
        $canSell && $canRent => 'Sellable + Rentable Product',
        $canRent => 'Rentable Product',
        default => 'Sellable Product',
    };
    $stockModeLabel = $product->stockModeLabel();
    $gstTaxTypeLabel = $product->gstTaxTypeLabel();
    $gstRateSummary = $product->gstRateSummary();
    [$stockModeBackground, $stockModeColor] = match ($product->stock_mode) {
        \App\Models\Product::STOCK_MODE_TRACKED_SALE => ['#fff7ed', '#9a3412'],
        \App\Models\Product::STOCK_MODE_TRACKED_RENTAL => ['#eff6ff', '#1d4ed8'],
        \App\Models\Product::STOCK_MODE_TRACKED_BOTH => ['#f5f3ff', '#6d28d9'],
        default => ['#f8fafc', '#475569'],
    };

    $addStockUrl = $canRent && !$canSell
        ? route('assets.create', ['product_id' => $product->id])
        : route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']);
    $addSaleUnitsUrl = route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']);
    $addRentalAssetsUrl = route('assets.create', ['product_id' => $product->id]);
    $assetRegisterUrl = route('assets.index', ['search' => $product->name]);
    $returnVerificationUrl = route('assets.pending-verification');

    $compactCode = function (?string $value, int $visible = 12) {
        $value = trim((string) $value);

        if ($value === '') {
            return 'N/A';
        }

        if (mb_strlen($value) <= $visible) {
            return $value;
        }

        return mb_substr($value, 0, $visible) . '...';
    };

    $openingTotalQuantity = max((int) ($product->total_quantity ?? 0), 0);
    $openingAvailableQuantity = max((int) ($product->available_quantity ?? 0), 0);
    $saleUnitCount = $usesUntrackedStock && $canSell
        ? $openingTotalQuantity
        : (int) ($saleInventorySummary['total_new_stock'] ?? 0);
    $saleAvailableCount = $usesUntrackedStock && $canSell
        ? $openingAvailableQuantity
        : (int) ($saleInventorySummary['available_new_stock'] ?? 0);
    $soldUnitCount = (int) ($saleInventorySummary['sold_new_stock'] ?? 0);
    $rentalAssetCount = $usesUntrackedStock && $canRent
        ? $openingTotalQuantity
        : (int) ($assetStats['total_assets'] ?? 0);
    $availableForRentCount = $usesUntrackedStock && $canRent
        ? $openingAvailableQuantity
        : (int) ($assetStats['available_assets'] ?? 0);
    $rentedOutCount = (int) ($assetStats['rented_assets'] ?? 0);
    $underRepairCount = (int) ($assetStats['maintenance_assets'] ?? 0);
    $reservedRentalCount = (int) ($assetStats['reserved_assets'] ?? 0);
    $awaitingVerificationCount = $product->assets()
        ->where('organization_id', $product->organization_id)
        ->where('asset_stage', \App\Models\Asset::STAGE_RENTAL_STOCK)
        ->where('asset_status', \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
        ->count();
    $retiredCount = $product->assets()
        ->where('organization_id', $product->organization_id)
        ->where('asset_stage', \App\Models\Asset::STAGE_RENTAL_STOCK)
        ->where('asset_status', \App\Models\Asset::STATUS_RETIRED)
        ->count();
    $conversionWarehouses = $product->saleInventories
        ->pluck('warehouse')
        ->filter()
        ->unique('id')
        ->values();
    $snapshotAssets = collect($assets->items())->take(5);
    if ($snapshotAssets->isEmpty()) {
        $snapshotAssets = $product->saleUnits->take(5);
    }
    $headerReference = collect([
        filled($product->brand) || filled($product->model_name)
            ? trim(collect([$product->brand, $product->model_name])->filter()->implode(' '))
            : null,
        $product->category ? 'Category: ' . $product->category : null,
        $product->product_code ? 'Code: ' . $product->product_code : null,
        $product->sku ? 'SKU: ' . $product->sku : null,
    ])->filter()->implode(' | ');

    $masterDetails = collect([
        ['label' => 'Category', 'value' => $product->category],
        ['label' => 'Brand', 'value' => $product->brand],
        ['label' => 'Model Name', 'value' => $product->model_name],
        ['label' => 'Product Code', 'value' => $product->product_code],
        ['label' => 'SKU', 'value' => $product->sku],
        ['label' => 'Stock Mode', 'value' => $stockModeLabel],
        ['label' => 'Product Type', 'value' => $productTypeLabel],
        ['label' => 'Sale Price', 'value' => $product->sale_price !== null ? $rupee . ' ' . number_format($product->sale_price, 2) : null],
        ['label' => 'Rental Price', 'value' => $canRent && $product->price_per_day !== null ? $rupee . ' ' . number_format((float) $product->price_per_day, 2) . ' / day' : null],
        ['label' => '15 Days', 'value' => $product->rental_price_15_days !== null ? $rupee . ' ' . number_format($product->rental_price_15_days, 2) : null],
        ['label' => '30 Days', 'value' => $product->rental_price_30_days !== null ? $rupee . ' ' . number_format($product->rental_price_30_days, 2) : null],
        ['label' => '3 Months', 'value' => $product->rental_price_3_months !== null ? $rupee . ' ' . number_format($product->rental_price_3_months, 2) : null],
        ['label' => 'GST Type', 'value' => $gstTaxTypeLabel],
        ['label' => 'GST Rate', 'value' => $gstRateSummary],
        ['label' => 'GST Mode', 'value' => $gstTaxTypeLabel ? ucfirst((string) ($product->gst_calculation_mode ?? 'exclusive')) : null],
    ])->filter(fn ($item) => filled($item['value']))->values();

    $stockCards = collect([
        ['label' => 'Available Assets', 'value' => $availableForRentCount, 'background' => '#ecfdf5', 'border' => '#bbf7d0', 'color' => '#166534'],
        ['label' => 'Rented Assets', 'value' => $rentedOutCount, 'background' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1d4ed8'],
        ['label' => 'Under Repair', 'value' => $underRepairCount, 'background' => '#fff7ed', 'border' => '#fed7aa', 'color' => '#c2410c'],
        ['label' => 'Awaiting Verification', 'value' => $awaitingVerificationCount, 'background' => '#fef3c7', 'border' => '#fde68a', 'color' => '#b45309'],
    ]);
    $rentalItems = \App\Models\RentalItem::query()
        ->where('organization_id', $product->organization_id)
        ->where('product_id', $product->id);
    $saleItems = \App\Models\SaleItem::query()
        ->where('organization_id', $product->organization_id)
        ->where('product_id', $product->id);
    $totalRentalItems = (clone $rentalItems)->count();
    $totalSaleItems = (clone $saleItems)->count();
    $lastRentalAt = (clone $rentalItems)->latest('created_at')->value('created_at');
    $lastSaleAt = (clone $saleItems)->latest('created_at')->value('created_at');
    $lastMovementAt = \App\Models\StockMovement::query()
        ->where('organization_id', $product->organization_id)
        ->where('product_id', $product->id)
        ->latest('created_at')
        ->value('created_at');
    $rentalRevenue = (float) ((clone $rentalItems)->sum('line_total') ?: 0);
    $saleRevenue = (float) ((clone $saleItems)->sum('line_total') ?: 0);
    $totalRentableAssets = $availableForRentCount + $rentedOutCount + $reservedRentalCount + $underRepairCount + $awaitingVerificationCount;
    $rentalHealthBase = max($totalRentableAssets, 1);
    $rentalUtilization = min(100, round(($rentedOutCount / $rentalHealthBase) * 100));
    $repairRate = min(100, round(($underRepairCount / $rentalHealthBase) * 100));
    [$utilizationLabel, $utilizationTone, $utilizationBackground, $utilizationBorder, $utilizationColor, $utilizationInsight] = match (true) {
        $rentalUtilization <= 25 => ['Very Low Utilization', 'neutral', '#f8fafc', '#e2e8f0', '#475569', 'Review demand or excess inventory.'],
        $rentalUtilization <= 50 => ['Low Utilization', 'warning', '#fef3c7', '#fde68a', '#b45309', 'Review demand or excess inventory.'],
        $rentalUtilization <= 80 => ['Healthy Utilization', 'success', '#ecfdf5', '#bbf7d0', '#166534', 'Healthy inventory utilization.'],
        $rentalUtilization <= 95 => ['High Demand', 'info', '#eff6ff', '#bfdbfe', '#1d4ed8', 'Consider purchasing additional assets.'],
        default => ['Stock Constraint', 'danger', '#fff1f2', '#fecdd3', '#be123c', 'Consider purchasing additional assets.'],
    };
    $formatWhen = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->diffForHumans() : 'No activity yet';
    $attentionItems = collect([
        $availableForRentCount === 0 && $canRent ? ['label' => 'No rental assets available', 'tone' => 'danger'] : null,
        $saleAvailableCount === 0 && $canSell ? ['label' => 'No sale units available', 'tone' => 'danger'] : null,
        $underRepairCount > 0 ? ['label' => $underRepairCount . ' repair pending', 'tone' => 'danger'] : null,
        $awaitingVerificationCount > 0 ? ['label' => $awaitingVerificationCount . ' awaiting verification', 'tone' => 'warning'] : null,
        $rentalAssetCount === 0 && $saleUnitCount === 0 ? ['label' => 'No assets linked', 'tone' => 'warning'] : null,
        blank($lastMovementAt) ? ['label' => 'No stock movement yet', 'tone' => 'neutral'] : null,
    ])->filter()->values();
    $performanceCards = collect([
        ['label' => 'Rental Revenue', 'value' => $rupee . ' ' . number_format($rentalRevenue, 2), 'note' => $totalRentalItems . ' rental line(s)'],
        ['label' => 'Sale Revenue', 'value' => $rupee . ' ' . number_format($saleRevenue, 2), 'note' => $totalSaleItems . ' sale line(s)'],
        ['label' => 'Active Rentals', 'value' => number_format($rentedOutCount), 'note' => 'Currently rented'],
        ['label' => 'Last Rental', 'value' => $formatWhen($lastRentalAt), 'note' => 'Product rental activity'],
        ['label' => 'Last Sale', 'value' => $formatWhen($lastSaleAt), 'note' => 'Product sale activity'],
        ['label' => 'Last Movement', 'value' => $formatWhen($lastMovementAt), 'note' => 'Stock ledger activity'],
    ]);
    $mobileBlockedCount = $reservedRentalCount + $awaitingVerificationCount + $retiredCount;
    $mobileOverviewDetails = collect([
        ['label' => 'Category', 'value' => $product->category],
        ['label' => 'Brand', 'value' => $product->brand],
        ['label' => 'Unit', 'value' => $product->unit ?? null],
        ['label' => 'HSN', 'value' => $product->hsn_code ?? null],
        ['label' => 'Tax', 'value' => $gstRateSummary],
        ['label' => 'Stock Mode', 'value' => $stockModeLabel],
        ['label' => 'Product Type', 'value' => $productTypeLabel],
        ['label' => 'Created', 'value' => optional($product->created_at)->format('d M Y')],
    ])->filter(fn ($item) => filled($item['value']))->values();
    $mobilePricingDetails = collect([
        ['label' => 'Sale Price', 'value' => $product->sale_price !== null ? $rupee . number_format((float) $product->sale_price, 2) : null],
        ['label' => 'Rental Price', 'value' => $canRent && $product->price_per_day !== null ? $rupee . number_format((float) $product->price_per_day, 2) . ' / day' : null],
        ['label' => '15 Days', 'value' => $product->rental_price_15_days !== null ? $rupee . number_format((float) $product->rental_price_15_days, 2) : null],
        ['label' => '30 Days', 'value' => $product->rental_price_30_days !== null ? $rupee . number_format((float) $product->rental_price_30_days, 2) : null],
        ['label' => 'GST', 'value' => $gstRateSummary],
        ['label' => 'Tax Mode', 'value' => $gstTaxTypeLabel ? ucfirst((string) ($product->gst_calculation_mode ?? 'exclusive')) : null],
    ])->filter(fn ($item) => filled($item['value']))->values();

    $productImageUrl = $product->product_image_url;
@endphp

<style>
    .product-detail-page {
        display: grid;
        gap: 10px;
    }
    .product-detail-identity {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        min-width: 0;
    }
    .product-detail-image,
    .product-mobile-hero-image {
        border: 1px solid #dbe3ef;
        background: linear-gradient(135deg, #eef2ff, #ffffff);
        color: #4f46e5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex: 0 0 auto;
        font-weight: 900;
    }
    .product-detail-image {
        width: 96px;
        height: 96px;
        border-radius: 18px;
    }
    .product-mobile-hero-image {
        width: 62px;
        height: 62px;
        border-radius: 16px;
    }
    .product-detail-image img,
    .product-mobile-hero-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .product-detail-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 14px;
    }
    .product-command-header {
        position: static;
        box-shadow: 0 8px 22px rgba(15,23,42,.05);
    }
    .product-detail-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex-wrap: wrap;
    }
    .product-detail-header h1 {
        margin: 4px 0 2px !important;
        font-size: 22px !important;
        line-height: 1.08;
    }
    .product-detail-header p {
        font-size: 11.5px;
        line-height: 1.25;
    }
    .product-detail-header-meta {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        margin-top: 6px;
    }
    .product-detail-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: flex-end;
    }
    .product-detail-actions > a {
        min-height: 34px !important;
        padding: 7px 10px !important;
        border-radius: 10px !important;
        font-size: 12px;
    }
    .product-action-menu {
        position: relative;
        display: inline-block;
    }
    .product-action-menu summary {
        list-style: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        min-width: 34px;
        min-height: 34px;
        padding: 0;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #0f172a;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }
    .product-action-menu summary::-webkit-details-marker { display: none; }
    .product-action-menu[open] summary {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }
    .product-action-panel {
        position: absolute;
        right: 0;
        top: 48px;
        z-index: 40;
        min-width: 210px;
        display: grid;
        gap: 6px;
        padding: 8px;
        border: 1px solid #dbe3ef;
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 18px 40px rgba(15,23,42,.14);
    }
    .product-action-link {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        min-height: 36px;
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid #edf2f7;
        background: #ffffff;
        color: #334155;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
    }
    .product-detail-grid-two {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .product-health-strip {
        display:grid;
        grid-template-columns:1.35fr repeat(5, minmax(0, 1fr));
        gap:8px;
    }
    .product-health-card,
    .product-performance-card,
    .product-command-panel {
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:#fff;
        padding:10px;
    }
    .product-health-card span,
    .product-performance-card span {
        display:block;
        font-size:10px;
        text-transform:uppercase;
        letter-spacing:.08em;
        font-weight:800;
        color:#64748b;
    }
    .product-health-card strong,
    .product-performance-card strong {
        display:block;
        margin-top:4px;
        color:#0f172a;
        font-size:20px;
        line-height:1;
    }
    .product-health-card.is-utilization {
        padding:12px;
    }
    .product-utilization-top {
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:8px;
    }
    .product-utilization-badge {
        display:inline-flex;
        padding:4px 7px;
        border-radius:999px;
        font-size:10px;
        font-weight:800;
        background:#fff;
        border:1px solid currentColor;
        white-space:nowrap;
    }
    .product-utilization-bar {
        height:8px;
        margin-top:8px;
        border-radius:999px;
        background:rgba(148,163,184,.28);
        overflow:hidden;
    }
    .product-utilization-fill {
        display:block;
        height:100%;
        border-radius:inherit;
    }
    .product-utilization-meta {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:7px;
        color:#475569;
        font-size:11px;
        font-weight:700;
    }
    .product-utilization-insight {
        margin-top:5px;
        color:#64748b;
        font-size:11px;
        line-height:1.25;
    }
    .product-command-grid {
        display:grid;
        grid-template-columns:1.1fr .9fr;
        gap:10px;
    }
    .product-performance-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:8px;
    }
    .product-performance-card strong {
        font-size:16px;
    }
    .product-performance-card small {
        display:block;
        margin-top:4px;
        color:#64748b;
        font-size:11px;
    }
    .product-progress-row {
        display:grid;
        grid-template-columns:150px minmax(0, 1fr) 46px;
        align-items:center;
        gap:8px;
        margin-top:8px;
        color:#475569;
        font-size:12px;
        font-weight:700;
    }
    .product-progress-track {
        height:8px;
        border-radius:999px;
        background:#e2e8f0;
        overflow:hidden;
    }
    .product-progress-fill {
        display:block;
        height:100%;
        border-radius:inherit;
        background:#2563eb;
    }
    .product-attention-row {
        display:flex;
        flex-wrap:wrap;
        gap:6px;
        margin-top:8px;
    }
    .product-attention-badge {
        display:inline-flex;
        padding:5px 8px;
        border-radius:999px;
        font-size:11px;
        font-weight:800;
        background:#f8fafc;
        color:#475569;
        border:1px solid #e2e8f0;
    }
    .product-attention-badge.is-danger { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
    .product-attention-badge.is-warning { background:#fef3c7; color:#b45309; border-color:#fde68a; }
    .product-recommend-actions {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));
        gap:7px;
        margin-top:8px;
    }
    .product-recommend-actions a {
        display:flex;
        align-items:center;
        justify-content:center;
        min-height:34px;
        padding:7px 9px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        color:#0f172a;
        background:#fff;
        text-decoration:none;
        font-size:12px;
        font-weight:800;
    }
    .product-compact-empty {
        margin-top:10px;
        min-height:58px;
        padding:12px;
        border-radius:12px;
        border:1px dashed #cbd5e1;
        background:#f8fafc;
        color:#64748b;
        font-size:12px;
    }
    .product-detail-master-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
    }
    .product-detail-master-item,
    .product-stock-card,
    .product-action-tile,
    .product-snapshot-row,
    .product-history-row,
    .product-convert-note {
        min-width: 0;
    }
    .product-detail-master-item {
        padding: 8px 10px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .product-stock-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
    }
    .product-stock-card {
        padding: 8px 10px;
        border-radius: 12px;
        border: 1px solid;
    }
    .product-action-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin-top: 10px;
    }
    .product-action-tile {
        display: grid;
        gap: 3px;
        padding: 10px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #0f172a;
        text-decoration: none;
    }
    .product-conversion-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 10px;
    }
    .product-conversion-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #f8fafc;
        overflow: hidden;
    }
    .product-snapshot-list,
    .product-history-list {
        display: grid;
        gap: 8px;
        margin-top: 10px;
    }
    .product-snapshot-row,
    .product-history-row {
        padding: 10px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
    }
    .product-copy-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 28px;
        padding: 5px 8px;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        background: #fff;
        color: #334155;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }
    .product-code-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        max-width: 100%;
    }
    .product-code-value {
        min-width: 0;
        font-weight: 700;
        color: #0f172a;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
    .product-mobile-view {
        display: none;
    }
    @media (max-width: 767px) {
        .product-detail-page {
            gap: 12px;
            padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px));
        }
        .product-desktop-view {
            display: none !important;
        }
        .product-mobile-view {
            display: grid;
            gap: 10px;
        }
        .product-mobile-topbar {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            position: relative;
            z-index: 60;
        }
        .product-mobile-titlebar {
            text-align: center;
            color: #0f172a;
            font-size: 16px;
            font-weight: 900;
        }
        .product-mobile-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border: 1px solid #dbe3ef;
            border-radius: 13px;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
        }
        .product-mobile-icon svg { width: 18px; height: 18px; }
        .product-mobile-hero,
        .product-mobile-panel,
        .product-mobile-accordion details,
        .product-mobile-footer-summary {
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 10px 26px rgba(15, 23, 42, .06);
            overflow: hidden;
        }
        .product-mobile-hero-main {
            display: grid;
            gap: 8px;
            padding: 12px;
        }
        .product-mobile-name {
            margin: 0;
            color: #0f172a;
            font-size: 20px;
            line-height: 1.12;
            letter-spacing: -.02em;
        }
        .product-mobile-sku {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
        }
        .product-mobile-chip-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .product-mobile-chip {
            display: inline-flex;
            align-items: center;
            min-height: 25px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .product-mobile-chip.is-sale { background: #ecfdf5; color: #15803d; }
        .product-mobile-chip.is-rent { background: #eff6ff; color: #1d4ed8; }
        .product-mobile-chip.is-stock { background: {{ $stockModeBackground }}; color: {{ $stockModeColor }}; }
        .product-mobile-price-band {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            border-top: 1px solid #e2e8f0;
        }
        .product-mobile-price-item {
            display: grid;
            gap: 3px;
            padding: 10px 12px;
            min-width: 0;
        }
        .product-mobile-price-item + .product-mobile-price-item { border-left: 1px solid #e2e8f0; }
        .product-mobile-price-item span {
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
        }
        .product-mobile-price-item strong {
            color: #1d4ed8;
            font-size: 18px;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }
        .product-mobile-panel { padding: 10px; }
        .product-mobile-panel[aria-label="Quick product actions"] {
            overflow: visible;
            position: relative;
            z-index: 30;
        }
        .product-mobile-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }
        .product-mobile-panel-head h2 {
            margin: 0;
            font-size: 15px;
            color: #0f172a;
        }
        .product-mobile-panel-head span {
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-align: right;
        }
        .product-mobile-stock-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px;
        }
        .product-mobile-stock-chip {
            display: grid;
            gap: 2px;
            min-height: 58px;
            padding: 7px;
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #f8fafc;
        }
        .product-mobile-stock-chip span {
            color: #64748b;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .product-mobile-stock-chip strong {
            color: #0f172a;
            font-size: 18px;
            line-height: 1;
        }
        .product-mobile-stock-chip.is-available { background: #ecfdf5; border-color: #bbf7d0; }
        .product-mobile-stock-chip.is-available strong { color: #15803d; }
        .product-mobile-stock-chip.is-rent { background: #eff6ff; border-color: #bfdbfe; }
        .product-mobile-stock-chip.is-rent strong { color: #2563eb; }
        .product-mobile-stock-chip.is-repair { background: #fff7ed; border-color: #fed7aa; }
        .product-mobile-stock-chip.is-repair strong { color: #ea580c; }
        .product-mobile-stock-chip.is-blocked { background: #f5f3ff; border-color: #ddd6fe; }
        .product-mobile-stock-chip.is-blocked strong { color: #6d28d9; }
        .product-mobile-actions {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 7px;
            padding: 10px;
            position: relative;
            overflow: visible;
        }
        .product-mobile-action {
            display: grid;
            justify-items: center;
            align-content: center;
            gap: 4px;
            min-height: 54px;
            padding: 5px;
            border: 1px solid #dbe3ef;
            border-radius: 13px;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            font-size: 10px;
            font-weight: 850;
            line-height: 1;
        }
        .product-mobile-action svg {
            width: 18px;
            height: 18px;
            color: #4f46e5;
        }
        .product-mobile-action-menu { position: relative; min-width: 0; }
        .product-mobile-action-menu summary {
            list-style: none;
            cursor: pointer;
        }
        .product-mobile-action-menu summary::-webkit-details-marker { display: none; }
        .product-mobile-action-menu summary::marker { content: ""; }
        .product-mobile-action-menu .product-action-panel {
            right: 0;
            top: calc(100% + 6px);
            left: auto;
            min-width: min(220px, calc(100vw - 32px));
            z-index: 40;
        }
        .product-mobile-accordion {
            display: grid;
            gap: 8px;
        }
        .product-mobile-accordion summary {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-height: 54px;
            padding: 9px 12px;
            cursor: pointer;
        }
        .product-mobile-accordion summary::-webkit-details-marker { display: none; }
        .product-mobile-section-title {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
            color: #0f172a;
            font-size: 15px;
            font-weight: 900;
        }
        .product-mobile-section-title span:first-child {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 10px;
            background: #f5f3ff;
            color: #4f46e5;
            flex: 0 0 auto;
        }
        .product-mobile-section-body {
            border-top: 1px solid #e2e8f0;
            padding: 10px 12px 12px;
        }
        .product-mobile-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 12px;
        }
        .product-mobile-detail {
            display: grid;
            gap: 2px;
            min-width: 0;
        }
        .product-mobile-detail span {
            color: #64748b;
            font-size: 10px;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .product-mobile-detail strong {
            color: #0f172a;
            font-size: 13px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }
        .product-mobile-asset-list,
        .product-mobile-activity-list {
            display: grid;
            gap: 7px;
        }
        .product-mobile-asset-row,
        .product-mobile-activity-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }
        .product-mobile-asset-row strong,
        .product-mobile-activity-row strong {
            color: #0f172a;
            font-size: 12px;
        }
        .product-mobile-asset-row span,
        .product-mobile-activity-row span {
            color: #64748b;
            font-size: 11px;
        }
        .product-mobile-footer-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1px;
            background: #e2e8f0;
        }
        .product-mobile-footer-summary div {
            display: grid;
            gap: 3px;
            padding: 10px 6px;
            background: #fff;
            text-align: center;
        }
        .product-mobile-footer-summary span {
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
        }
        .product-mobile-footer-summary strong {
            color: #0f172a;
            font-size: 14px;
            line-height: 1.1;
        }
        .product-detail-card,
        .product-conversion-card {
            border-radius: 18px;
        }
        .product-detail-identity {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        min-width: 0;
    }
    .product-detail-image,
    .product-mobile-hero-image {
        border: 1px solid #dbe3ef;
        background: linear-gradient(135deg, #eef2ff, #ffffff);
        color: #4f46e5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex: 0 0 auto;
        font-weight: 900;
    }
    .product-detail-image {
        width: 96px;
        height: 96px;
        border-radius: 18px;
    }
    .product-mobile-hero-image {
        width: 62px;
        height: 62px;
        border-radius: 16px;
    }
    .product-detail-image img,
    .product-mobile-hero-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .product-detail-card {
            padding: 14px;
        }
        .product-detail-header {
            gap: 10px;
        }
        .product-detail-header h1 {
            margin: 6px 0 3px !important;
            font-size: 21px !important;
            line-height: 1.15 !important;
        }
        .product-detail-header p {
            font-size: 11px !important;
            line-height: 1.35 !important;
        }
        .product-detail-actions {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            justify-content: stretch;
        }
        .product-detail-actions a,
        .product-detail-actions button,
        .product-action-menu summary {
            width: 100%;
            min-height: 40px !important;
            padding: 8px 12px !important;
            font-size: 12px !important;
            box-sizing: border-box;
        }
        .product-action-panel {
            position: static;
            min-width: 0;
            margin-top: 8px;
            box-shadow: none;
        }
        .product-mobile-view .product-mobile-action-menu .product-action-panel {
            position: absolute;
            right: 0;
            left: auto;
            top: calc(100% + 6px);
            min-width: min(220px, calc(100vw - 32px));
            margin-top: 0;
            z-index: 80;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
        }
        .product-mobile-view .product-mobile-topbar .product-action-panel {
            top: calc(100% + 8px);
        }
        .product-detail-grid-two,
        .product-conversion-grid,
        .product-health-strip,
        .product-command-grid,
        .product-performance-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .product-command-header {
            position: static;
        }
        .product-detail-master-grid,
        .product-stock-grid,
        .product-action-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .product-action-tile,
        .product-detail-master-item,
        .product-stock-card,
        .product-snapshot-row,
        .product-history-row {
            padding: 12px;
            border-radius: 14px;
        }
        .product-detail-card h2 {
            font-size: 16px !important;
        }
        .product-detail-card p,
        .product-detail-card label,
        .product-detail-card input,
        .product-detail-card textarea,
        .product-detail-card select,
        .product-detail-card button {
            font-size: 12px !important;
            line-height: 1.45 !important;
        }
        .product-detail-card textarea,
        .product-detail-card input,
        .product-detail-card select {
            min-width: 0;
            box-sizing: border-box;
        }
        .product-convert-note {
            padding: 12px !important;
        }
        .product-detail-card div,
        .product-detail-card span,
        .product-detail-card p,
        .product-detail-card strong,
        .product-detail-card a {
            overflow-wrap:anywhere;
            word-break:break-word;
        }
    }
</style>

@section('content')
    <div class="product-detail-page">
        <div class="product-mobile-view" aria-label="Mobile product details">
            <div class="product-mobile-topbar">
                <a href="{{ route('products.index') }}" class="product-mobile-icon" aria-label="Back to Products">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <div class="product-mobile-titlebar">Product Details</div>
                @if($canUpdateProducts)
                    <a href="{{ route('products.edit', $product) }}" class="product-mobile-icon" aria-label="Edit Product">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    </a>
                @endif
                <details class="product-mobile-action-menu" data-product-mobile-menu>
                    <summary class="product-mobile-icon" aria-label="More product actions">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="12" cy="19" r="1.7"/></svg>
                    </summary>
                    <div class="product-action-panel">
                        @if($canCreateAssets)
                            <a href="{{ $addStockUrl }}" class="product-action-link">Add Asset</a>
                        @endif
                        <a href="{{ $assetRegisterUrl }}" class="product-action-link">Asset Register</a>
                        @if($canViewProductStockHistory)
                            <a href="{{ route('stock-history.index', ['product_id' => $product->id]) }}" class="product-action-link">Stock History</a>
                        @endif
                        <a href="#mobile-product-assets" class="product-action-link">Assets</a>
                    </div>
                </details>
            </div>

            <section class="product-mobile-hero">
                <div class="product-mobile-hero-main">
                    <div class="product-mobile-hero-image" data-product-detail-image>@if($productImageUrl)<img src="{{ $productImageUrl }}" alt="{{ $product->name }}">@else{{ strtoupper(mb_substr($product->name, 0, 1)) }}@endif</div>
                    <div style="min-width:0;">
                        <h1 class="product-mobile-name">{{ $product->name }}</h1>
                        <div class="product-mobile-sku">SKU: {{ $product->sku ?: ($product->product_code ?: 'Not set') }}</div>
                        <div class="product-mobile-chip-row">
                            <span class="product-mobile-chip is-stock">{{ $stockModeLabel }}</span>
                            @if($canRent)
                                <span class="product-mobile-chip is-rent">Rentable</span>
                            @endif
                            @if($canSell)
                                <span class="product-mobile-chip is-sale">Sellable</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="product-mobile-price-band">
                    <div class="product-mobile-price-item">
                        <span>Sale Price</span>
                        <strong>{{ $product->sale_price !== null ? $rupee . number_format((float) $product->sale_price, 0) : 'â€”' }}</strong>
                    </div>
                    <div class="product-mobile-price-item">
                        <span>Rental Price</span>
                        <strong>{{ $canRent && $product->price_per_day !== null ? $rupee . number_format((float) $product->price_per_day, 0) . ' / day' : 'â€”' }}</strong>
                    </div>
                </div>
            </section>

            <section class="product-mobile-panel">
                <div class="product-mobile-panel-head">
                    <h2>Stock Overview</h2>
                    <span>As on {{ now()->format('d M Y') }}</span>
                </div>
                <div class="product-mobile-stock-grid">
                    <div class="product-mobile-stock-chip is-available"><span>Available</span><strong>{{ number_format($availableForRentCount + $saleAvailableCount) }}</strong></div>
                    <div class="product-mobile-stock-chip is-rent"><span>On Rent</span><strong>{{ number_format($rentedOutCount) }}</strong></div>
                    <div class="product-mobile-stock-chip is-repair"><span>Repair</span><strong>{{ number_format($underRepairCount) }}</strong></div>
                    <div class="product-mobile-stock-chip is-blocked"><span>Blocked</span><strong>{{ number_format($mobileBlockedCount) }}</strong></div>
                </div>
            </section>

            <section class="product-mobile-panel" aria-label="Quick product actions">
                <div class="product-mobile-panel-head">
                    <h2>Quick Actions</h2>
                </div>
                <div class="product-mobile-actions">
                    @if($canUpdateProducts)
                        <a href="{{ route('products.edit', $product) }}" class="product-mobile-action">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            Edit
                        </a>
                    @endif
                    @if($canCreateAssets)
                        <a href="{{ $addStockUrl }}" class="product-mobile-action">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
                            Add Asset
                        </a>
                    @endif
                    @if($canViewProductStockHistory)
                        <a href="{{ route('stock-history.index', ['product_id' => $product->id]) }}" class="product-mobile-action">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 15 4-4 3 3 5-7"/></svg>
                            History
                        </a>
                    @endif
                    <a href="{{ $assetRegisterUrl }}" class="product-mobile-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>
                        Labels
                    </a>
                    <details class="product-mobile-action-menu" data-product-mobile-menu>
                        <summary class="product-mobile-action">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.7"/><circle cx="12" cy="12" r="1.7"/><circle cx="19" cy="12" r="1.7"/></svg>
                            More
                        </summary>
                        <div class="product-action-panel">
                            <a href="{{ $assetRegisterUrl }}" class="product-action-link">Asset Register</a>
                            <a href="#mobile-product-assets" class="product-action-link">View Assets</a>
                            <a href="#mobile-product-activity" class="product-action-link">Activity</a>
                            @if($awaitingVerificationCount > 0)
                                <a href="{{ $returnVerificationUrl }}" class="product-action-link">Return Verification</a>
                            @endif
                        </div>
                    </details>
                </div>
            </section>

            <div class="product-mobile-accordion">
                <details open>
                    <summary>
                        <span class="product-mobile-section-title"><span>i</span><span>Overview</span></span>
                        <span>âŒƒ</span>
                    </summary>
                    <div class="product-mobile-section-body">
                        <div class="product-mobile-detail-grid">
                            @foreach($mobileOverviewDetails as $detail)
                                <div class="product-mobile-detail"><span>{{ $detail['label'] }}</span><strong>{{ $detail['value'] }}</strong></div>
                            @endforeach
                        </div>
                    </div>
                </details>
                <details>
                    <summary><span class="product-mobile-section-title"><span>â–¡</span><span>Inventory</span></span><span>âŒ„</span></summary>
                    <div class="product-mobile-section-body">
                        <div class="product-mobile-detail-grid">
                            <div class="product-mobile-detail"><span>Available</span><strong>{{ number_format($availableForRentCount + $saleAvailableCount) }}</strong></div>
                            <div class="product-mobile-detail"><span>On Rent</span><strong>{{ number_format($rentedOutCount) }}</strong></div>
                            <div class="product-mobile-detail"><span>Repair</span><strong>{{ number_format($underRepairCount) }}</strong></div>
                            <div class="product-mobile-detail"><span>Blocked</span><strong>{{ number_format($mobileBlockedCount) }}</strong></div>
                            <div class="product-mobile-detail"><span>Sale Units</span><strong>{{ number_format($saleUnitCount) }}</strong></div>
                            <div class="product-mobile-detail"><span>Rental Assets</span><strong>{{ number_format($rentalAssetCount) }}</strong></div>
                        </div>
                    </div>
                </details>
                <details>
                    <summary><span class="product-mobile-section-title"><span>{{ $rupee }}</span><span>Pricing</span></span><span>âŒ„</span></summary>
                    <div class="product-mobile-section-body">
                        <div class="product-mobile-detail-grid">
                            @forelse($mobilePricingDetails as $detail)
                                <div class="product-mobile-detail"><span>{{ $detail['label'] }}</span><strong>{{ $detail['value'] }}</strong></div>
                            @empty
                                <div class="product-mobile-detail"><strong>No pricing configured.</strong></div>
                            @endforelse
                        </div>
                    </div>
                </details>
                <details id="mobile-product-assets">
                    <summary><span class="product-mobile-section-title"><span>â—‡</span><span>Assets ({{ number_format($rentalAssetCount + $saleUnitCount) }})</span></span><span>âŒ„</span></summary>
                    <div class="product-mobile-section-body">
                        <div class="product-mobile-asset-list">
                            @forelse($snapshotAssets as $asset)
                                @php $badge = $statusBadge($asset->asset_status); @endphp
                                <div class="product-mobile-asset-row">
                                    <div>
                                        <strong>{{ $asset->serial_number ?: $asset->asset_name }}</strong><br>
                                        <span>{{ optional($asset->warehouse)->name ?: 'Warehouse not set' }}</span>
                                    </div>
                                    <span style="color:{{ $badge[1] }}; font-weight:900;">{{ str_replace('_', ' ', $asset->asset_status) }}</span>
                                </div>
                            @empty
                                <div class="product-mobile-asset-row"><strong>No assets linked yet.</strong></div>
                            @endforelse
                        </div>
                    </div>
                </details>
                <details id="mobile-product-activity">
                    <summary><span class="product-mobile-section-title"><span>â—·</span><span>Activity</span></span><span>âŒ„</span></summary>
                    <div class="product-mobile-section-body">
                        <div class="product-mobile-activity-list">
                            @forelse($product->inventoryConversions->take(5) as $conversion)
                                <div class="product-mobile-activity-row">
                                    <div>
                                        <strong>{{ $conversion->conversion_type === \App\Models\InventoryConversion::TYPE_RENTAL_TO_SALE ? 'Rental to Sale' : 'Sale to Rental' }}</strong><br>
                                        <span>{{ optional($conversion->created_at)->format('d M Y, h:i A') }}</span>
                                    </div>
                                    <span>{{ $conversion->quantity_converted }} unit(s)</span>
                                </div>
                            @empty
                                <div class="product-mobile-activity-row"><strong>No stock activity yet.</strong></div>
                            @endforelse
                        </div>
                    </div>
                </details>
                <details>
                    <summary><span class="product-mobile-section-title"><span>â–£</span><span>Documents</span></span><span>âŒ„</span></summary>
                    <div class="product-mobile-section-body">
                        <div class="product-mobile-detail"><strong>No product documents attached.</strong></div>
                    </div>
                </details>
            </div>

            <div class="product-mobile-footer-summary">
                <div><span>Total Assets</span><strong>{{ number_format($rentalAssetCount + $saleUnitCount) }}</strong></div>
                <div><span>Total Value</span><strong>{{ $rupee }}{{ number_format($rentalRevenue + $saleRevenue, 0) }}</strong></div>
                <div><span>Updated</span><strong>{{ $formatWhen($lastMovementAt) }}</strong></div>
            </div>
        </div>

        <div class="product-desktop-view">
        <div class="product-detail-card product-command-header">
            <div class="product-detail-header">
                <div class="product-detail-identity">
                    <div class="product-detail-image" data-product-detail-image>@if($productImageUrl)<img src="{{ $productImageUrl }}" alt="{{ $product->name }}">@else{{ strtoupper(mb_substr($product->name, 0, 1)) }}@endif</div>
                    <div style="min-width:0;">
                    <div style="display:inline-flex; padding:4px 8px; border-radius:999px; background:#ecfeff; color:#0f766e; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.08em;">Product Master</div>
                    <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $product->name }}</h1>
                    <p style="margin:0; color:#64748b; max-width:760px;">Catalog, pricing, stock mode, and physical unit actions.</p>
                    @if($headerReference !== '')
                        <p style="margin:5px 0 0; color:#334155; font-size:12px; font-weight:700;">{{ $headerReference }}</p>
                    @endif
                    <div class="product-detail-header-meta">
                        <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:{{ $stockModeBackground }}; color:{{ $stockModeColor }}; font-size:10px; font-weight:800; text-transform:uppercase;">{{ $stockModeLabel }}</span>
                        @if($canSell)
                            <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Sellable</span>
                        @endif
                        @if($canRent)
                            <span style="display:inline-flex; padding:5px 9px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:10px; font-weight:800; text-transform:uppercase;">Rentable</span>
                        @endif
                    </div>
                    </div>
                </div>

                <div class="product-detail-actions">
                    @if($canUpdateProducts)
                        <a href="{{ route('products.edit', $product) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:1px solid #cbd5e1; border-radius:12px; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Edit Product</a>
                    @endif
                    @if($canCreateAssets)
                        <a href="{{ $addStockUrl }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:700;">Add Stock</a>
                    @endif
                    <a href="{{ $assetRegisterUrl }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:1px solid #cbd5e1; border-radius:12px; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Asset Register</a>
                    @if($canViewProductStockHistory)
                        <a href="{{ route('stock-history.index', ['product_id' => $product->id]) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border:1px solid #bfdbfe; border-radius:12px; background:#eff6ff; color:#1d4ed8; text-decoration:none; font-weight:700;">Stock History</a>
                    @endif
                    <details class="product-action-menu">
                        <summary aria-label="More product actions">...</summary>
                        <div class="product-action-panel">
                            @if($canCreateAssets)
                                <a href="{{ $addSaleUnitsUrl }}" class="product-action-link">Add Sale Units</a>
                                <a href="{{ $addRentalAssetsUrl }}" class="product-action-link">Add Rental Assets</a>
                            @endif
                            <a href="#stock-actions" class="product-action-link">Open Stock Actions</a>
                            <a href="#conversion-history" class="product-action-link">Convert Stock</a>
                            @if($awaitingVerificationCount > 0)
                                <a href="{{ $returnVerificationUrl }}" class="product-action-link">Return Verification</a>
                            @endif
                        </div>
                    </details>
                </div>
            </div>
        </div>

        @if(session('success'))
            <div style="padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div style="padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div style="padding:14px 16px; border-radius:16px; border:1px solid #fecaca; background:#fff1f2; color:#991b1b;">
                <strong>Please fix the following:</strong>
                <ul style="margin:10px 0 0 18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="product-health-strip">
            <div class="product-health-card is-utilization" style="background:{{ $utilizationBackground }}; border-color:{{ $utilizationBorder }};">
                <div class="product-utilization-top">
                    <span style="color:{{ $utilizationColor }};">Rental Utilization</span>
                    <span class="product-utilization-badge" style="color:{{ $utilizationColor }};">{{ $utilizationLabel }}</span>
                </div>
                <strong style="color:{{ $utilizationColor }};">{{ $rentalUtilization }}%</strong>
                <div class="product-utilization-bar">
                    <span class="product-utilization-fill" style="width:{{ $rentalUtilization }}%; background:{{ $utilizationColor }};"></span>
                </div>
                <div class="product-utilization-meta">
                    <span>Available: {{ number_format($availableForRentCount) }}</span>
                    <span>Rented: {{ number_format($rentedOutCount) }}</span>
                </div>
                <div class="product-utilization-insight">{{ $utilizationInsight }}</div>
            </div>
            @foreach($stockCards as $card)
                <div class="product-health-card" style="background:{{ $card['background'] }}; border-color:{{ $card['border'] }};">
                    <span style="color:{{ $card['color'] }};">{{ $card['label'] }}</span>
                    <strong style="color:{{ $card['color'] }};">{{ number_format((int) $card['value']) }}</strong>
                </div>
            @endforeach
            <div class="product-health-card" style="background:#f8fafc; border-color:#e2e8f0;">
                <span>Revenue Generated</span>
                <strong>{{ $rupee }} {{ number_format($rentalRevenue + $saleRevenue, 2) }}</strong>
            </div>
        </div>

        <div class="product-command-grid">
            <div class="product-command-panel">
                <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0; font-size:16px;">Business Performance</h2>
                        <p style="margin:4px 0 0; color:#64748b; font-size:12px;">Product activity and revenue signals.</p>
                    </div>
                    @if($attentionItems->isNotEmpty())
                        <div class="product-attention-row" style="margin-top:0;">
                            @foreach($attentionItems->take(2) as $item)
                                <span class="product-attention-badge is-{{ $item['tone'] }}">{{ $item['label'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="product-performance-grid" style="margin-top:10px;">
                    @foreach($performanceCards as $card)
                        <div class="product-performance-card">
                            <span>{{ $card['label'] }}</span>
                            <strong>{{ $card['value'] }}</strong>
                            <small>{{ $card['note'] }}</small>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="product-command-panel">
                <h2 style="margin:0; font-size:16px;">Inventory Health</h2>
                <p style="margin:4px 0 0; color:#64748b; font-size:12px;">Availability, utilization, and exceptions.</p>
                <div class="product-progress-row">
                    <span>Rental Utilization</span>
                    <span class="product-progress-track"><span class="product-progress-fill" style="width:{{ $rentalUtilization }}%;"></span></span>
                    <strong>{{ $rentalUtilization }}%</strong>
                </div>
                <div class="product-progress-row">
                    <span>Repair Rate</span>
                    <span class="product-progress-track"><span class="product-progress-fill" style="width:{{ $repairRate }}%; background:#dc2626;"></span></span>
                    <strong>{{ $repairRate }}%</strong>
                </div>
                <div class="product-progress-row">
                    <span>Verification Queue</span>
                    <span class="product-progress-track"><span class="product-progress-fill" style="width:{{ min(100, $awaitingVerificationCount * 10) }}%; background:#d97706;"></span></span>
                    <strong>{{ number_format($awaitingVerificationCount) }}</strong>
                </div>
                <div class="product-recommend-actions">
                    @if($canCreateAssets && $saleAvailableCount === 0 && $canSell)
                        <a href="{{ $addSaleUnitsUrl }}">Create Sale Unit</a>
                    @endif
                    @if($canCreateAssets && $availableForRentCount === 0 && $canRent)
                        <a href="{{ $addRentalAssetsUrl }}">Add First Asset</a>
                    @endif
                    @if($underRepairCount > 0)
                        <a href="{{ $assetRegisterUrl }}">Review Repairs</a>
                    @endif
                    @if($awaitingVerificationCount > 0)
                        <a href="{{ $returnVerificationUrl }}">Verify Returns</a>
                    @endif
                    <a href="#stock-actions">Stock Actions</a>
                </div>
            </div>
        </div>

        <div class="product-detail-grid-two">
            <div class="product-detail-card">
                <h2 style="margin:0;">Master Details</h2>
                <p style="margin:4px 0 0; color:#64748b; font-size:12px;">Catalog and pricing details already stored.</p>

                @if($masterDetails->isEmpty())
                    <div style="margin-top:18px; padding:16px; border-radius:18px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569;">
                        No catalog details are available yet beyond the product name.
                    </div>
                @else
                    <div class="product-detail-master-grid">
                        @foreach($masterDetails as $detail)
                            <div class="product-detail-master-item">
                                <div style="font-size:10px; color:#64748b; text-transform:uppercase; font-weight:800; letter-spacing:0.08em;">{{ $detail['label'] }}</div>
                                <div style="margin-top:4px; font-size:14px; font-weight:800; color:#0f172a;">{{ $detail['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="product-detail-card">
                <h2 style="margin:0;">Stock Summary</h2>
                <p style="margin:4px 0 0; color:#64748b; font-size:12px;">
                    {{ $usesUntrackedStock
                        ? 'For untracked products, this summary reflects the opening quantity stored in Product Master.'
                        : 'The quickest read on how many sale units and rental assets exist right now.' }}
                </p>

                <div class="product-stock-grid">
                    @foreach($stockCards as $card)
                        <div class="product-stock-card" style="background:{{ $card['background'] }}; border-color:{{ $card['border'] }};">
                            <div style="font-size:10px; color:{{ $card['color'] }}; font-weight:800; text-transform:uppercase; letter-spacing:0.08em;">{{ $card['label'] }}</div>
                            <div style="margin-top:4px; font-size:20px; font-weight:800; color:{{ $card['color'] }};">{{ $card['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div id="stock-actions" class="product-detail-card">
            <h2 style="margin:0;">Stock Actions</h2>
            <p style="margin:8px 0 0; color:#64748b;">Add physical units here, open Asset Register for serial and barcode details, and use conversion only when business use changes.</p>

            <div class="product-action-grid">
                @if($canCreateAssets)
                    <a href="{{ $addSaleUnitsUrl }}" class="product-action-tile">
                        <strong>Add Sale Units</strong>
                        <span style="color:#64748b; font-size:13px;">Register fresh sale stock units.</span>
                    </a>
                    <a href="{{ $addRentalAssetsUrl }}" class="product-action-tile">
                        <strong>Add Rental Assets</strong>
                        <span style="color:#64748b; font-size:13px;">Register rental-ready physical units.</span>
                    </a>
                @endif
                <a href="{{ $assetRegisterUrl }}" class="product-action-tile">
                    <strong>Open Asset Register</strong>
                    <span style="color:#64748b; font-size:13px;">See serial, barcode, warehouse, and unit status.</span>
                </a>
                <a href="#conversion-history" class="product-action-tile">
                    <strong>Convert Stock</strong>
                    <span style="color:#64748b; font-size:13px;">Move sale units to rental assets or move rental assets back to sale units.</span>
                </a>
                @if($awaitingVerificationCount > 0)
                    <a href="{{ $returnVerificationUrl }}" class="product-action-tile">
                        <strong>Return Verification</strong>
                        <span style="color:#64748b; font-size:13px;">{{ $awaitingVerificationCount }} unit(s) waiting for review.</span>
                    </a>
                @endif
            </div>

            <div id="conversion-history" class="product-conversion-grid">
                <div id="convert-sale-to-rental" class="product-conversion-card">
                    <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0; background:#ffffff;">
                        <h3 style="margin:0; font-size:20px;">Convert Sale Units to Rental Assets</h3>
                        <p style="margin:8px 0 0; color:#64748b;">Move sale units into tracked rental units for dispatch workflows.</p>
                    </div>

                    <div style="padding:22px;">
                        <div class="product-convert-note" style="margin-bottom:16px; padding:14px 16px; border-radius:16px; background:#ffffff; border:1px solid #e2e8f0; color:#475569; line-height:1.6;">
                            Choose how many sale units should become rental assets. Existing serial and barcode history stays attached.
                        </div>

                        @if($conversionWarehouses->isNotEmpty() && $saleAvailableCount > 0)
                            <form method="POST" action="{{ route('products.convert-to-rental', $product) }}" style="display:grid; gap:16px;">
                                @csrf

                                <div class="product-form-grid-two" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                                    <div>
                                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Warehouse</label>
                                        <select name="warehouse_id" required style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                                            <option value="">Select warehouse</option>
                                            @foreach($conversionWarehouses as $warehouse)
                                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Quantity to Convert</label>
                                        <input type="number" name="quantity" min="1" value="{{ old('quantity', 1) }}" required style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                                    </div>
                                </div>

                                <div class="product-form-grid-two" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                                    <div>
                                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Serial Numbers</label>
                                        <textarea id="serial_numbers_input" rows="1" placeholder="One serial number per line" style="width:100%; min-height:48px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; resize:vertical;">{{ old('serial_numbers_text') }}</textarea>
                                        <div style="margin-top:6px; color:#64748b; font-size:12px;">Leave blank to auto-generate serial numbers.</div>
                                    </div>

                                    <div>
                                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Barcode Values</label>
                                        <textarea id="barcode_values_input" rows="1" placeholder="One barcode per line" style="width:100%; min-height:48px; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; resize:vertical;">{{ old('barcode_values_text') }}</textarea>
                                        <div style="margin-top:6px; color:#64748b; font-size:12px;">Optional. Add only where you already have label values.</div>
                                    </div>
                                </div>

                                <div class="product-form-grid-two" style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                                    <div>
                                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Purchase Cost (optional)</label>
                                        <input type="number" name="purchase_cost" min="0" step="0.01" value="{{ old('purchase_cost') }}" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Remarks</label>
                                        <input type="text" name="remarks" value="{{ old('remarks') }}" placeholder="Optional conversion note" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                                    </div>
                                </div>

                                <div id="serial_numbers_hidden_fields"></div>
                                <div id="barcode_values_hidden_fields"></div>

                                <div style="display:flex; justify-content:flex-end;">
                                    <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#0f766e; color:#ffffff; font-weight:700; cursor:pointer;">
                                        Convert to Rental Assets
                                    </button>
                                </div>
                            </form>
                        @else
                            <div style="padding:18px; border-radius:18px; background:#fffaf0; border:1px solid #fed7aa; color:#9a3412;">
                                Conversion is unavailable because this product currently has no available sale units to convert.
                            </div>
                        @endif
                    </div>
                </div>

                <div id="convert-rental-to-sale" class="product-conversion-card">
                    <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0; background:#ffffff;">
                        <h3 style="margin:0; font-size:20px;">Convert Rental Assets to Sale Units</h3>
                        <p style="margin:8px 0 0; color:#64748b;">Select available rental assets that should go back into sale units.</p>
                    </div>

                    <div style="padding:22px;">
                        <div class="product-convert-note" style="margin-bottom:16px; padding:14px 16px; border-radius:16px; background:#ffffff; border:1px solid #e2e8f0; color:#475569; line-height:1.6;">
                            Only available rental assets can be moved back to sale units. Their serial and barcode history stays intact.
                        </div>

                        @if($convertibleRentalAssets->isNotEmpty())
                            <form method="POST" action="{{ route('products.convert-to-sellable', $product) }}" style="display:grid; gap:16px;">
                                @csrf

                                <div style="display:grid; gap:10px; max-height:280px; overflow:auto; padding-right:4px;">
                                    @foreach($convertibleRentalAssets as $asset)
                                        <label style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:14px; background:#ffffff; cursor:pointer;">
                                            <input type="checkbox" name="asset_ids[]" value="{{ $asset->id }}" {{ in_array($asset->id, old('asset_ids', [])) ? 'checked' : '' }} style="margin-top:2px;">
                                            <span style="display:grid; gap:4px;">
                                                <span class="product-code-chip">
                                                    <span class="product-code-value" title="{{ $asset->serial_number }}">SN: {{ $compactCode($asset->serial_number) }}</span>
                                                    <button type="button" class="product-copy-btn" data-copy-text="{{ $asset->serial_number }}" aria-label="Copy serial number">Copy</button>
                                                </span>
                                                <span style="font-size:13px; color:#64748b;">
                                                    {{ optional($asset->warehouse)->name ?: 'Warehouse' }}
                                                    @if($asset->barcode_value)
                                                        <span class="product-code-chip" style="margin-top:6px;">
                                                            <span class="product-code-value" title="{{ $asset->barcode_value }}">Barcode: {{ $compactCode($asset->barcode_value) }}</span>
                                                            <button type="button" class="product-copy-btn" data-copy-text="{{ $asset->barcode_value }}" aria-label="Copy barcode">Copy</button>
                                                        </span>
                                                    @endif
                                                </span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>

                                <div>
                                    <label style="display:block; margin-bottom:8px; color:#475569; font-size:13px; font-weight:700;">Remarks</label>
                                    <input type="text" name="remarks" value="{{ old('remarks') }}" placeholder="Optional conversion note" style="width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff;">
                                </div>

                                <div style="display:flex; justify-content:flex-end;">
                                    <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 18px; border:none; border-radius:12px; background:#6d28d9; color:#ffffff; font-weight:700; cursor:pointer;">
                                        Convert to Sellable Stock
                                    </button>
                                </div>
                            </form>
                        @else
                            <div style="padding:18px; border-radius:18px; background:#f5f3ff; border:1px solid #ddd6fe; color:#5b21b6;">
                                No available rental assets are ready for conversion back to sale units.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="product-detail-grid-two">
            <div class="product-detail-card">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0;">Asset Snapshot</h2>
                        <p style="margin:8px 0 0; color:#64748b;">A quick look at recent physical units without leaving this page.</p>
                    </div>
                    <a href="{{ $assetRegisterUrl }}" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">View all in Asset Register</a>
                </div>

                @if($snapshotAssets->isEmpty())
                    <div class="product-compact-empty">
                        <strong style="color:#0f172a;">Asset Snapshot</strong><br>
                        No assets linked yet.
                    </div>
                @else
                    <div class="product-snapshot-list">
                        @foreach($snapshotAssets as $asset)
                            @php
                                $badge = $statusBadge($asset->asset_status);
                                $activeAssignment = $asset->activeRentalAssignments()->latest('assigned_at')->first();
                                $linkedRental = $activeAssignment?->rental;
                                $snapshotProductName = optional($asset->product)->name ?: 'No linked product';
                                $snapshotProductSecondary = trim(collect([optional($asset->product)->brand, optional($asset->product)->model_name])->filter()->implode(' '));
                                if ($snapshotProductSecondary === '') {
                                    $snapshotProductSecondary = trim((string) (optional($asset->product)->product_code ?: optional($asset->product)->sku ?: ''));
                                }
                                if ($snapshotProductSecondary === '') {
                                    $snapshotProductSecondary = 'No model assigned';
                                }
                            @endphp
                            <div class="product-snapshot-row">
                                <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                                    <div style="display:grid; gap:6px;">
                                        <div style="font-size:15px; font-weight:800; color:#0f172a; word-break:normal; overflow-wrap:anywhere;">{{ $snapshotProductName }}</div>
                                        <div style="font-size:13px; color:#64748b; word-break:normal; overflow-wrap:anywhere;">{{ $snapshotProductSecondary }}</div>
                                        @if($asset->isSerialPending())
                                            <span style="display:inline-flex; width:max-content; padding:5px 9px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:10px; font-weight:800; text-transform:uppercase;">Serial Pending</span>
                                        @endif
                                        <div class="product-code-chip">
                                            <span class="product-code-value" title="{{ $asset->serial_number }}">SN: {{ $compactCode($asset->serial_number) }}</span>
                                            <button type="button" class="product-copy-btn" data-copy-text="{{ $asset->serial_number }}">Copy</button>
                                        </div>
                                        @if($asset->barcode_value)
                                            <div class="product-code-chip">
                                                <span class="product-code-value" title="{{ $asset->barcode_value }}">Barcode: {{ $compactCode($asset->barcode_value) }}</span>
                                                <button type="button" class="product-copy-btn" data-copy-text="{{ $asset->barcode_value }}">Copy</button>
                                            </div>
                                        @endif
                                    </div>
                                    <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $badge[0] }}; color:{{ $badge[1] }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                                        {{ str_replace('_', ' ', $asset->asset_status) }}
                                    </span>
                                </div>

                                <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; margin-top:12px;">
                                    <div>
                                        <div style="font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.06em;">Warehouse</div>
                                        <div style="margin-top:4px; color:#0f172a; font-weight:700;">{{ optional($asset->warehouse)->name ?: 'N/A' }}</div>
                                    </div>
                                    <div>
                                        <div style="font-size:10px; color:#64748b; font-weight:800; text-transform:uppercase; letter-spacing:.06em;">Linked Rental</div>
                                        <div style="margin-top:4px; color:#0f172a; font-weight:700;">
                                            @if($asset->asset_stage === \App\Models\Asset::STAGE_NEW_STOCK)
                                                Sale Unit
                                            @elseif($linkedRental && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                                <a href="{{ route('rentals.show', $linkedRental) }}" style="color:#1d4ed8; text-decoration:none;">Rental #{{ $linkedRental->id }}</a>
                                            @else
                                                {{ $linkedRental ? 'Rental #' . $linkedRental->id : 'Not linked' }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="product-detail-card">
                <h2 style="margin:0;">Conversion History</h2>
                <p style="margin:8px 0 0; color:#64748b;">Recent stock movements between sale units and rental assets.</p>

                <div class="product-history-list">
                    @forelse($product->inventoryConversions as $conversion)
                        <div class="product-history-row">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                                <div style="display:grid; gap:4px;">
                                    <strong style="color:#0f172a;">
                                        {{ $conversion->conversion_type === \App\Models\InventoryConversion::TYPE_RENTAL_TO_SALE ? 'Rental Assets -> Sale Units' : 'Sale Units -> Rental Assets' }}
                                    </strong>
                                    <span style="color:#475569; font-size:13px;">{{ $conversion->quantity_converted }} unit(s)</span>
                                </div>
                                <div style="text-align:right; color:#64748b; font-size:12px;">
                                    <div>{{ optional($conversion->created_at)->format('d M Y, h:i A') }}</div>
                                    <div style="margin-top:4px;">{{ optional($conversion->convertedBy)->name ?: 'System / User' }}</div>
                                </div>
                            </div>
                            <div style="margin-top:10px; color:#475569; font-size:13px;">
                                Warehouse: {{ optional($conversion->warehouse)->name ?: 'Warehouse not available' }} | Sale units: {{ $conversion->sale_stock_before }} to {{ $conversion->sale_stock_after }}
                            </div>
                            @if($conversion->remarks)
                                <div style="margin-top:8px; color:#64748b; font-size:13px;">{{ $conversion->remarks }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="product-compact-empty">
                            <strong style="color:#0f172a;">Conversion History</strong><br>
                            No conversions yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const serialInput = document.getElementById('serial_numbers_input');
    const barcodeInput = document.getElementById('barcode_values_input');
    const serialContainer = document.getElementById('serial_numbers_hidden_fields');
    const barcodeContainer = document.getElementById('barcode_values_hidden_fields');
    const copyButtons = Array.from(document.querySelectorAll('.product-copy-btn'));
    const productMobileMenus = Array.from(document.querySelectorAll('[data-product-mobile-menu]'));

    if (productMobileMenus.length) {
        productMobileMenus.forEach(function (menu) {
            menu.addEventListener('toggle', function () {
                if (!menu.open) {
                    return;
                }

                productMobileMenus.forEach(function (otherMenu) {
                    if (otherMenu !== menu) {
                        otherMenu.open = false;
                    }
                });
            });
        });

        document.addEventListener('click', function (event) {
            productMobileMenus.forEach(function (menu) {
                if (menu.open && !menu.contains(event.target)) {
                    menu.open = false;
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            productMobileMenus.forEach(function (menu) {
                menu.open = false;
            });
        });
    }

    if (serialContainer && barcodeContainer) {
        const syncHiddenFields = function (source, container, fieldName) {
            container.innerHTML = '';

            if (!source) {
                return;
            }

            source.value
                .split(/\r?\n/)
                .map(function (value) { return value.trim(); })
                .filter(function (value) { return value.length > 0; })
                .forEach(function (value) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = fieldName + '[]';
                    input.value = value;
                    container.appendChild(input);
                });
        };

        const syncAll = function () {
            syncHiddenFields(serialInput, serialContainer, 'serial_numbers');
            syncHiddenFields(barcodeInput, barcodeContainer, 'barcode_values');
        };

        if (serialInput) {
            serialInput.addEventListener('input', syncAll);
        }

        if (barcodeInput) {
            barcodeInput.addEventListener('input', syncAll);
        }

        syncAll();
    }

    copyButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            const value = button.dataset.copyText || '';

            if (!value) {
                return;
            }

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = value;
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'absolute';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    helper.remove();
                }

                const previous = button.textContent;
                button.textContent = 'Copied';
                setTimeout(function () {
                    button.textContent = previous;
                }, 1200);
            } catch (error) {
                console.warn('Copy failed', error);
            }
        });
    });
});
</script>
@endpush
