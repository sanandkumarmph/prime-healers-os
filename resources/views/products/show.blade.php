@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
    $canDeleteProducts = $currentUser?->canAccessModule('products', 'delete') ?? false;
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
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
    $productType = $product->product_type === \App\Models\Product::TYPE_RENTABLE
        ? \App\Models\Product::TYPE_RENTABLE
        : \App\Models\Product::TYPE_SELLABLE;
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

    $addStockUrl = $productType === \App\Models\Product::TYPE_SELLABLE
        ? route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock'])
        : route('assets.create', ['product_id' => $product->id]);
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
    $saleUnitCount = $usesUntrackedStock && $productType === \App\Models\Product::TYPE_SELLABLE
        ? $openingTotalQuantity
        : (int) ($saleInventorySummary['total_new_stock'] ?? 0);
    $saleAvailableCount = $usesUntrackedStock && $productType === \App\Models\Product::TYPE_SELLABLE
        ? $openingAvailableQuantity
        : (int) ($saleInventorySummary['available_new_stock'] ?? 0);
    $soldUnitCount = (int) ($saleInventorySummary['sold_new_stock'] ?? 0);
    $rentalAssetCount = $usesUntrackedStock && $productType === \App\Models\Product::TYPE_RENTABLE
        ? $openingTotalQuantity
        : (int) ($assetStats['total_assets'] ?? 0);
    $availableForRentCount = $usesUntrackedStock && $productType === \App\Models\Product::TYPE_RENTABLE
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
    $snapshotAssets = collect($assets->items())->take(4);
    if ($snapshotAssets->isEmpty()) {
        $snapshotAssets = $product->saleUnits->take(4);
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
        ['label' => 'Rental Price', 'value' => $productType === \App\Models\Product::TYPE_RENTABLE && $product->price_per_day !== null ? $rupee . ' ' . number_format((float) $product->price_per_day, 2) . ' / day' : null],
        ['label' => '15 Days', 'value' => $product->rental_price_15_days !== null ? $rupee . ' ' . number_format($product->rental_price_15_days, 2) : null],
        ['label' => '30 Days', 'value' => $product->rental_price_30_days !== null ? $rupee . ' ' . number_format($product->rental_price_30_days, 2) : null],
        ['label' => '3 Months', 'value' => $product->rental_price_3_months !== null ? $rupee . ' ' . number_format($product->rental_price_3_months, 2) : null],
        ['label' => 'GST Type', 'value' => $gstTaxTypeLabel],
        ['label' => 'GST Rate', 'value' => $gstRateSummary],
        ['label' => 'GST Mode', 'value' => $gstTaxTypeLabel ? ucfirst((string) ($product->gst_calculation_mode ?? 'exclusive')) : null],
    ])->filter(fn ($item) => filled($item['value']))->values();

    $stockCards = collect([
        ['label' => 'Sale Units', 'value' => $saleUnitCount, 'background' => '#fff7ed', 'border' => '#fed7aa', 'color' => '#9a3412'],
        ['label' => 'Rental Assets', 'value' => $rentalAssetCount, 'background' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1d4ed8'],
        ['label' => 'Available for Rent', 'value' => $availableForRentCount, 'background' => '#ecfdf5', 'border' => '#bbf7d0', 'color' => '#166534'],
        ['label' => 'Rented Out', 'value' => $rentedOutCount, 'background' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1d4ed8'],
        ['label' => 'Awaiting Verification', 'value' => $awaitingVerificationCount, 'background' => '#fef3c7', 'border' => '#fde68a', 'color' => '#b45309'],
        ['label' => 'Under Repair', 'value' => $underRepairCount, 'background' => '#fff7ed', 'border' => '#fed7aa', 'color' => '#c2410c'],
        ['label' => 'Sold Units', 'value' => $soldUnitCount, 'background' => '#f8fafc', 'border' => '#e2e8f0', 'color' => '#475569'],
        ['label' => 'Retired Units', 'value' => $retiredCount, 'background' => '#f8fafc', 'border' => '#e2e8f0', 'color' => '#475569'],
    ]);

@endphp

<style>
    .product-detail-page {
        display: grid;
        gap: 18px;
    }
    .product-detail-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 24px;
        padding: 22px;
    }
    .product-detail-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        flex-wrap: wrap;
    }
    .product-detail-header-meta {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 10px;
    }
    .product-detail-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: flex-end;
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
        min-height: 42px;
        padding: 0 14px;
        border-radius: 12px;
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
        gap: 18px;
    }
    .product-detail-master-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-top: 18px;
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
        padding: 14px 16px;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .product-stock-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-top: 18px;
    }
    .product-stock-card {
        padding: 16px;
        border-radius: 18px;
        border: 1px solid;
    }
    .product-action-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 18px;
    }
    .product-action-tile {
        display: grid;
        gap: 6px;
        padding: 16px;
        border-radius: 18px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #0f172a;
        text-decoration: none;
    }
    .product-conversion-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-top: 18px;
    }
    .product-conversion-card {
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        background: #f8fafc;
        overflow: hidden;
    }
    .product-snapshot-list,
    .product-history-list {
        display: grid;
        gap: 12px;
        margin-top: 18px;
    }
    .product-snapshot-row,
    .product-history-row {
        padding: 16px;
        border-radius: 18px;
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
    }
    @media (max-width: 767px) {
        .product-detail-page {
            gap: 12px;
        }
        .product-detail-card,
        .product-conversion-card {
            border-radius: 18px;
        }
        .product-detail-card {
            padding: 16px;
        }
        .product-detail-header {
            gap: 12px;
        }
        .product-detail-header h1 {
            margin: 8px 0 4px !important;
            font-size: 24px !important;
            line-height: 1.15 !important;
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
        .product-detail-grid-two,
        .product-conversion-grid {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .product-detail-master-grid,
        .product-stock-grid,
        .product-action-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
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
    }
</style>

@section('content')
    <div class="product-detail-page">
        <div class="product-detail-card">
            <div class="product-detail-header">
                <div>
                    <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#ecfeff; color:#0f766e; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Product Master</div>
                    <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">{{ $product->name }}</h1>
                    <p style="margin:0; color:#64748b; max-width:760px;">Catalog, pricing, stock mode, and physical unit actions for this product. For tracked products, quantity is added through sale units and rental assets, not by editing Product Master directly.</p>
                    @if($headerReference !== '')
                        <p style="margin:10px 0 0; color:#334155; font-size:13px; font-weight:600;">{{ $headerReference }}</p>
                    @endif
                    <div class="product-detail-header-meta">
                        <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:{{ $stockModeBackground }}; color:{{ $stockModeColor }}; font-size:11px; font-weight:700; text-transform:uppercase;">{{ $stockModeLabel }}</span>
                        @if($canSell)
                            <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Sellable</span>
                        @endif
                        @if($canRent)
                            <span style="display:inline-flex; padding:7px 12px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase;">Rentable</span>
                        @endif
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
                    <details class="product-action-menu">
                        <summary>More / Convert</summary>
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

        <div class="product-detail-grid-two">
            <div class="product-detail-card">
                <h2 style="margin:0;">Master Details</h2>
                <p style="margin:8px 0 0; color:#64748b;">Catalog and pricing details already stored for this product.</p>

                @if($masterDetails->isEmpty())
                    <div style="margin-top:18px; padding:16px; border-radius:18px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569;">
                        No catalog details are available yet beyond the product name.
                    </div>
                @else
                    <div class="product-detail-master-grid">
                        @foreach($masterDetails as $detail)
                            <div class="product-detail-master-item">
                                <div style="font-size:11px; color:#64748b; text-transform:uppercase; font-weight:700; letter-spacing:0.08em;">{{ $detail['label'] }}</div>
                                <div style="margin-top:6px; font-size:16px; font-weight:700; color:#0f172a;">{{ $detail['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="product-detail-card">
                <h2 style="margin:0;">Stock Summary</h2>
                <p style="margin:8px 0 0; color:#64748b;">
                    {{ $usesUntrackedStock
                        ? 'For untracked products, this summary reflects the opening quantity stored in Product Master.'
                        : 'The quickest read on how many sale units and rental assets exist right now.' }}
                </p>

                <div class="product-stock-grid">
                    @foreach($stockCards as $card)
                        <div class="product-stock-card" style="background:{{ $card['background'] }}; border-color:{{ $card['border'] }};">
                            <div style="font-size:11px; color:{{ $card['color'] }}; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">{{ $card['label'] }}</div>
                            <div style="margin-top:6px; font-size:24px; font-weight:800; color:{{ $card['color'] }};">{{ $card['value'] }}</div>
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
                    <div style="margin-top:18px; padding:18px; border-radius:18px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569;">
                        No physical units are linked to this product yet. Add sale units or rental assets to start tracking stock here.
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
                        <div style="padding:18px; border-radius:18px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569;">
                            No conversion history recorded yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="product-detail-card">
            <h2 style="margin:0;">Help</h2>
            <p style="margin:8px 0 0; color:#64748b; line-height:1.7;">Product Master stores catalog and pricing. Physical units are managed in Asset Register.</p>
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
