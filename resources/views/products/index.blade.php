@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canCreateProducts = $currentUser?->canAccessModule('products', 'create') ?? false;
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
    $canDeleteProducts = $currentUser?->canAccessModule('products', 'delete') ?? false;
    $canReadAssets = $currentUser?->canAccessModule('assets', 'read') ?? false;
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $rupee = html_entity_decode('&#8377;');
    $productDashboardCards = [
        ['label' => 'Product Master', 'value' => null, 'url' => route('products.index'), 'background' => '#ffffff', 'border' => '#e2e8f0', 'labelColor' => '#64748b', 'valueColor' => '#0f172a', 'subtext' => 'Catalog and stock-mode view'],
        ['label' => 'Sellable', 'value' => null, 'url' => route('products.index'), 'background' => '#f0fdf4', 'border' => '#bbf7d0', 'labelColor' => '#166534', 'valueColor' => '#166534', 'subtext' => 'Sale-unit products'],
        ['label' => 'Rentable', 'value' => null, 'url' => route('products.index'), 'background' => '#eff6ff', 'border' => '#bfdbfe', 'labelColor' => '#1d4ed8', 'valueColor' => '#1d4ed8', 'subtext' => 'Asset-tracked products'],
        ['label' => 'Sale Units', 'value' => null, 'url' => route('products.index'), 'background' => '#fff7ed', 'border' => '#fed7aa', 'labelColor' => '#c2410c', 'valueColor' => '#9a3412', 'subtext' => 'Available to sell'],
        ['label' => 'Rental Assets', 'value' => null, 'url' => route('assets.index', ['asset_stage' => 'rental_stock']), 'background' => '#faf5ff', 'border' => '#ddd6fe', 'labelColor' => '#6d28d9', 'valueColor' => '#6d28d9', 'subtext' => 'Tracked units'],
        ['label' => 'Rental Available', 'value' => null, 'url' => route('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'available']), 'background' => '#f8fafc', 'border' => '#cbd5e1', 'labelColor' => '#475569', 'valueColor' => '#0f172a', 'subtext' => 'Ready for dispatch'],
    ];
@endphp

@section('content')
    <style>
        .product-action-menu { position:relative; display:inline-block; }
        .product-action-menu summary {
            list-style:none; display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px;
            border-radius:12px; border:1px solid #dbe3ef; background:#fff; color:#0f172a; cursor:pointer; font-weight:900;
            box-shadow:0 8px 20px rgba(15,23,42,.04);
        }
        .product-action-menu summary::-webkit-details-marker { display:none; }
        .product-action-menu[open] summary { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .product-action-panel {
            position:absolute; right:48px; top:0; z-index:40; min-width:188px;
            display:grid; gap:6px; padding:8px; border:1px solid #dbe3ef; border-radius:14px; background:#fff;
            box-shadow:0 18px 40px rgba(15,23,42,.14);
        }
        .product-action-link,
        .product-action-panel button {
            display:flex; align-items:center; justify-content:flex-start; min-height:36px; padding:8px 10px;
            border-radius:10px; border:1px solid #edf2f7; background:#fff; color:#334155; text-decoration:none; font-size:12px; font-weight:700; cursor:pointer;
        }
        .product-action-panel .danger { background:#fff1f2; border-color:#fecdd3; color:#be123c; }
        .product-mobile-chip-row { display:none; }
        .product-desktop-links { display:none !important; }
        .product-mobile-command { display:none; }
        .product-table-wrap { overflow:auto; }
        .product-table { width:100%; border-collapse:collapse; min-width:1180px; }
        @media (max-width: 767px) {
            .product-desktop-hero,
            .product-desktop-dashboard {
                display:none !important;
            }
            .product-mobile-command {
                position:relative;
                z-index:20;
                display:grid;
                gap:8px;
                padding:8px;
                margin-bottom:10px;
                border:1px solid #dbe3ef;
                border-radius:16px;
                background:#fff;
                box-shadow:0 8px 22px rgba(15,23,42,.04);
                pointer-events:auto;
            }
            .product-mobile-search-row {
                display:grid;
                grid-template-columns:minmax(0, 1fr);
                gap:8px;
            }
            .product-mobile-search-row input,
            .product-mobile-filter-toggle select {
                width:100%;
                min-height:40px;
                border:1px solid #cbd5e1;
                border-radius:12px;
                padding:8px 10px;
                font-size:16px;
                box-sizing:border-box;
                background:#fff;
            }
            .product-mobile-stat-strip {
                display:grid;
                grid-template-columns:repeat(4, minmax(0, 1fr));
                gap:6px;
            }
            .product-mobile-stat-strip button {
                display:grid;
                gap:2px;
                min-width:0;
                padding:7px 8px;
                border:1px solid #e2e8f0;
                border-radius:12px;
                background:#f8fafc;
                color:#0f172a;
                text-align:left;
                cursor:pointer;
            }
            .product-mobile-stat-strip span {
                color:#64748b;
                font-size:9px;
                font-weight:800;
                letter-spacing:.05em;
                overflow:hidden;
                text-overflow:ellipsis;
                text-transform:uppercase;
                white-space:nowrap;
            }
            .product-mobile-stat-strip strong {
                font-size:16px;
                line-height:1;
            }
            .product-mobile-filter-toggle {
                position:relative;
                z-index:21;
                border:1px solid #dbe3ef;
                border-radius:12px;
                background:#f8fafc;
                overflow:hidden;
                pointer-events:auto;
            }
            .product-mobile-filter-toggle summary {
                align-items:center;
                color:#334155;
                cursor:pointer;
                display:flex;
                font-size:12px;
                font-weight:800;
                justify-content:space-between;
                list-style:none;
                min-height:38px;
                padding:8px 10px;
                user-select:none;
            }
            .product-mobile-filter-toggle summary::-webkit-details-marker { display:none; }
            .product-mobile-filter-body {
                display:grid;
                gap:8px;
                padding:0 10px 10px;
            }
            .product-mobile-chip-row {
                display:flex; gap:8px; overflow-x:auto; padding:0 0 10px; margin-top:-10px;
                scrollbar-width:none;
            }
            .product-mobile-chip-row::-webkit-scrollbar { display:none; }
            .product-mobile-chip {
                flex:0 0 auto; min-height:34px; display:inline-flex; align-items:center; justify-content:center;
                padding:7px 11px; border-radius:999px; border:1px solid #cbd5e1;
                background:#fff; color:#334155; text-decoration:none; font-size:12px; font-weight:800;
                cursor:pointer;
            }
            .product-mobile-chip.is-active {
                background:#0f172a;
                border-color:#0f172a;
                color:#fff;
            }
            .product-table-wrap { overflow:visible !important; }
            .product-table { min-width:0 !important; display:block; border-collapse:separate; border-spacing:0 10px; }
            .product-table thead { display:none; }
            .product-table tbody,
            .product-table tr,
            .product-table td { display:block; width:100%; }
            .product-table tr {
                margin-bottom:10px; border:1px solid #dbe3ef; border-radius:16px; background:#fff;
                box-shadow:0 8px 22px rgba(15,23,42,.045); overflow:hidden;
            }
            .product-table td {
                display:grid; grid-template-columns:108px minmax(0, 1fr); gap:10px;
                padding:10px 12px !important; border-top:0 !important; border-bottom:1px solid #edf2f7;
                text-align:left !important; background:#fff;
            }
            .product-table td:last-child { border-bottom:none; }
            .product-table td::before {
                color:#64748b; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
            }
            .product-table td:nth-child(1)::before { content:"Product"; }
            .product-table td:nth-child(2)::before { content:"Master"; }
            .product-table td:nth-child(3)::before { content:"Type"; }
            .product-table td:nth-child(4)::before { content:"Inventory"; }
            .product-table td:nth-child(5)::before { content:"Stock Signals"; }
            .product-table td:nth-child(6)::before { content:"Pricing"; }
            .product-table td:nth-child(7)::before { content:"Actions"; }
            .product-action-panel { position:static; min-width:0; margin-top:8px; box-shadow:none; }
        }
    </style>

    <div class="rn-list-page">
    <div class="product-desktop-hero rx-page-header" style="margin-bottom:20px;">
        <div>
            <div class="rx-eyebrow">Product Master</div>
            <h1 class="rx-page-title">Product Master</h1>
            <p class="rx-page-subtitle">Use Product Master for catalog and pricing. Add quantity through sale units and rental assets, then track physical units in Asset Register.</p>
        </div>

        @if($canCreateProducts)
            <a href="{{ route('products.create') }}" class="rx-btn-soft">
                + Add Product
            </a>
        @endif
    </div>

    @if(session('success'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0; color:#166534;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff1f2; border:1px solid #fecaca; color:#991b1b;">
            {{ session('error') }}
        </div>
    @endif

    @if(($duplicateProductNameGroups ?? collect())->isNotEmpty())
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412;">
            <strong>{{ $duplicateProductNameGroups->count() }} duplicate product-name {{ $duplicateProductNameGroups->count() === 1 ? 'group is' : 'groups are' }} present in Product Master.</strong>
            Products with the same name should be differentiated by Brand and Model so imports and asset links stay unambiguous.
        </div>
    @endif

    @php
        $totals = [
            'products' => $products->sum(fn ($product) => 1),
            'sellable' => $products->sum(fn ($product) => $product->canSell() ? 1 : 0),
            'rentable' => $products->sum(fn ($product) => $product->canRent() ? 1 : 0),
            'sale_stock' => $products->sum(fn ($product) => (int) ($product->sale_stock_quantity ?? 0)),
            'rental_assets' => $products->sum(fn ($product) => (int) ($product->assets_count ?? 0)),
            'rental_available' => $products->sum(fn ($product) => (int) ($product->available_assets_count ?? 0)),
        ];
    @endphp

    <div class="product-mobile-command" aria-label="Mobile product controls">
        <div class="product-mobile-search-row">
            <input id="product-mobile-search" type="search" placeholder="Search product, brand, model, SKU, code">
        </div>

        <div class="product-mobile-stat-strip" aria-label="Product summary">
            <button type="button" data-product-filter="all"><span>Total</span><strong>{{ $totals['products'] }}</strong></button>
            <button type="button" data-product-filter="sellable"><span>Sellable</span><strong>{{ $totals['sellable'] }}</strong></button>
            <button type="button" data-product-filter="rentable"><span>Rentable</span><strong>{{ $totals['rentable'] }}</strong></button>
            <button type="button" data-product-filter="available"><span>Avail</span><strong>{{ $totals['rental_available'] }}</strong></button>
        </div>

        <div class="product-mobile-chip-row" aria-label="Product quick filters">
            <button type="button" class="product-mobile-chip is-active" data-product-filter="all">All</button>
            <button type="button" class="product-mobile-chip" data-product-filter="sellable">Sellable</button>
            <button type="button" class="product-mobile-chip" data-product-filter="rentable">Rentable</button>
            <button type="button" class="product-mobile-chip" data-product-filter="available">Available</button>
        </div>

        <details class="product-mobile-filter-toggle">
            <summary>Filter / Sort <span>Optional</span></summary>
            <div class="product-mobile-filter-body">
                <select id="product-mobile-sort" aria-label="Sort products">
                    <option value="name_asc">Name A-Z</option>
                    <option value="name_desc">Name Z-A</option>
                    <option value="sale_stock_desc">Sale stock high</option>
                    <option value="rental_available_desc">Rental available high</option>
                </select>
            </div>
        </details>
    </div>

    <div class="product-desktop-dashboard" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:22px;">
        @php
            $productDashboardCards[0]['value'] = $totals['products'];
            $productDashboardCards[1]['value'] = $totals['sellable'];
            $productDashboardCards[2]['value'] = $totals['rentable'];
            $productDashboardCards[3]['value'] = $totals['sale_stock'];
            $productDashboardCards[4]['value'] = $totals['rental_assets'];
            $productDashboardCards[5]['value'] = $totals['rental_available'];
        @endphp
        @foreach($productDashboardCards as $card)
            <a href="{{ $card['url'] }}" class="rn-summary-link" style="display:block; padding:18px; border-radius:20px; background:{{ $card['background'] }}; border:1px solid {{ $card['border'] }}; text-decoration:none; color:inherit; transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;">
                <span class="rn-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        @if($card['label'] === 'Product Master')
                            <path d="M20.5 7.5 12 3 3.5 7.5 12 12l8.5-4.5Z"/><path d="M3.5 7.5V16L12 21l8.5-5V7.5"/><path d="M12 12v9"/>
                        @elseif($card['label'] === 'Sellable')
                            <path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>
                        @elseif($card['label'] === 'Rentable')
                            <path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1Z"/>
                        @elseif($card['label'] === 'Sale Units')
                            <path d="M5 12h14"/><path d="M12 5v14"/>
                        @elseif($card['label'] === 'Rental Assets')
                            <path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>
                        @else
                            <path d="m5 13 4 4L19 7"/>
                        @endif
                    </svg>
                </span>
                <div style="font-size:11px; color:{{ $card['labelColor'] }}; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">{{ $card['label'] }}</div>
                <div style="margin-top:8px; font-size:28px; font-weight:800; color:{{ $card['valueColor'] }};">{{ $card['value'] }}</div>
                <div style="margin-top:6px; color:{{ $card['labelColor'] }}; font-size:13px;">{{ $card['subtext'] }}</div>
            </a>
        @endforeach
    </div>

    <div class="product-mobile-chip-row product-desktop-links" aria-label="Product quick filters">
        <a href="{{ route('products.index') }}" class="product-mobile-chip">Product Master</a>
        <a href="{{ route('assets.index', ['asset_stage' => 'rental_stock']) }}" class="product-mobile-chip">Rental Assets</a>
        <a href="{{ route('products.index') }}" class="product-mobile-chip">Sale Units</a>
        <a href="{{ route('assets.index', ['asset_status' => 'available']) }}" class="product-mobile-chip">Available</a>
    </div>

    <div class="rn-table-shell" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; overflow:hidden;">
        <div style="padding:20px 22px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
            <div>
            <h2 style="margin:0; font-size:22px; letter-spacing:-0.02em;">Product Master Snapshot</h2>
            <p style="margin:8px 0 0; color:#64748b;">One table for catalog setup, stock mode, and unit availability.</p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px; background:#f8fafc; color:#334155; font-size:12px; font-weight:700;">Product Master = catalog</span>
                <span style="display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:700;">Rental assets = physical rental units</span>
            </div>
        </div>

        <div class="product-table-wrap" style="overflow:auto;">
            <table class="product-table" style="width:100%; border-collapse:collapse; min-width:1180px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Product</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Master</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Type</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Inventory</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Stock Signals</th>
                        <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Pricing</th>
                        <th style="text-align:right; padding:14px 18px; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b;">Actions</th>
                    </tr>
                </thead>
                <tbody id="product-mobile-list">
                    @forelse($products as $product)
                        @php
                            $saleStock = (int) ($product->sale_stock_quantity ?? 0);
                            $canSell = $product->canSell();
                            $canRent = $product->canRent();
                            $primaryType = $product->product_type === \App\Models\Product::TYPE_RENTABLE
                                ? \App\Models\Product::TYPE_RENTABLE
                                : \App\Models\Product::TYPE_SELLABLE;
                            $assetsCount = (int) ($product->assets_count ?? 0);
                            $availableAssets = (int) ($product->available_assets_count ?? 0);
                            $rentedAssets = (int) ($product->rented_assets_count ?? 0);
                            $maintenanceAssets = (int) ($product->maintenance_assets_count ?? 0);
                            $stockModeLabel = $product->stockModeLabel();
                            [$stockModeBackground, $stockModeColor] = match ($product->stock_mode) {
                                \App\Models\Product::STOCK_MODE_TRACKED_SALE => ['#fff7ed', '#9a3412'],
                                \App\Models\Product::STOCK_MODE_TRACKED_RENTAL => ['#eff6ff', '#1d4ed8'],
                                \App\Models\Product::STOCK_MODE_TRACKED_BOTH => ['#f5f3ff', '#6d28d9'],
                                default => ['#f8fafc', '#475569'],
                            };
                            $rentalWarehouses = $product->assets->pluck('warehouse.name')->filter()->unique()->values();
                            $hasSaleStock = $saleStock > 0;
                            $hasRentalAssets = $assetsCount > 0;
                            $legacyMixed = $hasSaleStock && $hasRentalAssets;
                            $lowStock = $primaryType === \App\Models\Product::TYPE_SELLABLE && $saleStock <= 2 && $saleStock > 0;
                            $addStockUrl = $primaryType === \App\Models\Product::TYPE_SELLABLE
                                ? route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock'])
                                : route('assets.create', ['product_id' => $product->id]);
                            $assetRegisterUrl = route('assets.index', ['search' => $product->name]);
                            $convertUrl = route('products.show', $product) . ($hasSaleStock ? '#convert-stock' : '#convert-rental-stock');
                            $brandModel = trim(collect([$product->brand, $product->model_name])->filter()->implode(' '));
                            $hasDuplicateName = ($duplicateProductNameGroups ?? collect())->has(strtolower(trim((string) $product->name)));
                        @endphp
                        <tr
                            data-product-row
                            data-product-name="{{ strtolower($product->name) }}"
                            data-product-search="{{ strtolower(collect([$product->name, $product->brand, $product->model_name, $product->category, $product->sku, $product->product_code])->filter()->join(' ')) }}"
                            data-product-sellable="{{ $canSell ? '1' : '0' }}"
                            data-product-rentable="{{ $canRent ? '1' : '0' }}"
                            data-product-sale-stock="{{ $saleStock }}"
                            data-product-rental-available="{{ $availableAssets }}"
                            style="border-top:1px solid #e2e8f0; vertical-align:top;"
                        >
                            <td style="padding:18px;">
                                <div style="display:flex; flex-direction:column; gap:6px;">
                                    <a href="{{ route('products.show', $product) }}" style="font-size:16px; font-weight:800; color:#0f172a; text-decoration:none;">{{ $product->name }}</a>
                                    <div style="color:#64748b; font-size:13px;">
                                        {{ $brandModel !== '' ? $brandModel : ($product->product_code ?: ($product->sku ?: 'No code assigned')) }}
                                    </div>
                                    @if(filled($product->category))
                                        <span style="display:inline-flex; width:max-content; padding:6px 10px; border-radius:999px; background:#f8fafc; color:#475569; font-size:11px; font-weight:700; text-transform:uppercase;">{{ $product->category }}</span>
                                    @endif
                                    @if($lowStock)
                                        <span style="display:inline-flex; width:max-content; padding:6px 10px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Low sale units</span>
                                    @endif
                                    @if($legacyMixed)
                                        <span style="display:inline-flex; width:max-content; padding:6px 10px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:11px; font-weight:700; text-transform:uppercase;">Legacy mixed inventory</span>
                                    @endif
                                    @if($hasDuplicateName)
                                        <span style="display:inline-flex; width:max-content; padding:6px 10px; border-radius:999px; background:#fff7ed; color:#9a3412; font-size:11px; font-weight:700; text-transform:uppercase;">Duplicate product name</span>
                                    @endif
                                </div>
                            </td>
                            <td style="padding:18px;">
                                <div style="display:grid; gap:8px;">
                                    <div style="font-size:13px; color:#475569;"><strong>Category:</strong> {{ $product->category ?: 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>Brand:</strong> {{ $product->brand ?: 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>Model:</strong> {{ $product->model_name ?: 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>SKU:</strong> {{ $product->sku ?: 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>Code:</strong> {{ $product->product_code ?: 'N/A' }}</div>
                                </div>
                            </td>
                            <td style="padding:18px;">
                                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                    @if($canSell)
                                        <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#ecfdf5; color:#166534; font-size:11px; font-weight:700; text-transform:uppercase;">
                                            Sellable
                                        </span>
                                    @endif
                                    @if($canRent)
                                        <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:700; text-transform:uppercase;">
                                            Rentable
                                        </span>
                                    @endif
                                    <span style="display:inline-flex; padding:6px 10px; border-radius:999px; background:{{ $stockModeBackground }}; color:{{ $stockModeColor }}; font-size:11px; font-weight:700; text-transform:uppercase;">
                                        {{ $stockModeLabel }}
                                    </span>
                                </div>
                            </td>
                            <td style="padding:18px;">
                                @if($primaryType === \App\Models\Product::TYPE_SELLABLE)
                                    <div style="display:grid; gap:8px;">
                                        <div style="padding:12px 14px; border-radius:16px; background:#fff7ed; border:1px solid #fed7aa;">
                                            <div style="font-size:11px; color:#9a3412; font-weight:700; text-transform:uppercase;">Available to Sell</div>
                                            <div style="margin-top:6px; font-size:24px; font-weight:800; color:#9a3412;">{{ $saleStock }}</div>
                                        </div>
                                        <div style="font-size:12px; color:#64748b;">Active available sale units.</div>
                                    </div>
                                @else
                                    <div style="display:grid; gap:8px;">
                                        <div style="padding:12px 14px; border-radius:16px; background:#ecfdf5; border:1px solid #bbf7d0;">
                                            <div style="font-size:11px; color:#166534; font-weight:700; text-transform:uppercase;">Available to Rent</div>
                                            <div style="margin-top:6px; font-size:24px; font-weight:800; color:#166534;">{{ $availableAssets }}</div>
                                        </div>
                                        <div style="font-size:12px; color:#64748b;">Rental-ready units for dispatch.</div>
                                    </div>
                                @endif
                            </td>
                            <td style="padding:18px;">
                                @if($primaryType === \App\Models\Product::TYPE_SELLABLE)
                                    <div style="display:grid; gap:8px;">
                                        @if($hasRentalAssets)
                                            <div style="padding:12px 14px; border-radius:16px; background:#eff6ff; border:1px solid #bfdbfe;">
                                                <div style="font-size:11px; color:#1d4ed8; font-weight:700; text-transform:uppercase;">Converted to Rental</div>
                                                <div style="margin-top:6px; font-size:24px; font-weight:800; color:#1d4ed8;">{{ $assetsCount }}</div>
                                            </div>
                                            <div style="font-size:12px; color:#64748b;">{{ $availableAssets }} available to rent across {{ max($rentalWarehouses->count(), 1) }} warehouse{{ $rentalWarehouses->count() === 1 ? '' : 's' }}.</div>
                                        @else
                                            <div style="padding:12px 14px; border-radius:16px; background:#f8fafc; border:1px solid #e2e8f0;">
                                                <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase;">Rental Assets</div>
                                                <div style="margin-top:6px; font-size:24px; font-weight:800; color:#475569;">0</div>
                                            </div>
                                            <div style="font-size:12px; color:#64748b;">No rental assets linked.</div>
                                        @endif
                                    </div>
                                @else
                                    <div style="display:grid; gap:8px;">
                                        <div style="padding:12px 14px; border-radius:16px; background:#eff6ff; border:1px solid #bfdbfe;">
                                            <div style="font-size:11px; color:#1d4ed8; font-weight:700; text-transform:uppercase;">Rented Out</div>
                                            <div style="margin-top:6px; font-size:24px; font-weight:800; color:#1d4ed8;">{{ $rentedAssets }}</div>
                                        </div>
                                        <div style="font-size:12px; color:#64748b;">Maintenance {{ $maintenanceAssets }} | Warehouse {{ $rentalWarehouses->take(2)->implode(', ') ?: 'Not assigned' }}{{ $rentalWarehouses->count() > 2 ? ' +' . ($rentalWarehouses->count() - 2) : '' }}</div>
                                        @if($hasSaleStock)
                                            <div style="font-size:12px; color:#9a3412;">Also in sale units: {{ $saleStock }}</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td style="padding:18px;">
                                <div style="display:grid; gap:8px;">
                                    <div style="font-size:13px; color:#475569;"><strong>Sale:</strong> {{ $product->sale_price !== null ? $rupee . ' ' . number_format($product->sale_price, 2) : 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>Per Day:</strong> {{ $primaryType === \App\Models\Product::TYPE_RENTABLE ? $rupee . ' ' . number_format((float) $product->price_per_day, 2) : 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>15 Days:</strong> {{ $product->rental_price_15_days !== null ? $rupee . ' ' . number_format($product->rental_price_15_days, 2) : 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>30 Days:</strong> {{ $product->rental_price_30_days !== null ? $rupee . ' ' . number_format($product->rental_price_30_days, 2) : 'N/A' }}</div>
                                    <div style="font-size:13px; color:#475569;"><strong>3 Months:</strong> {{ $product->rental_price_3_months !== null ? $rupee . ' ' . number_format($product->rental_price_3_months, 2) : 'N/A' }}</div>
                                </div>
                            </td>
                            <td style="padding:18px; text-align:right;">
                                <div style="display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap;">
                                    <a href="{{ route('products.show', $product) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:9px 12px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:700; font-size:13px;">View</a>
                                    @if($canCreateAssets)
                                        <a href="{{ $addStockUrl }}" style="display:inline-flex; align-items:center; justify-content:center; padding:9px 12px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700; font-size:13px;">{{ $product->product_type === \App\Models\Product::TYPE_SELLABLE ? 'Add Sale Unit' : 'Add Rental Asset' }}</a>
                                    @endif
                                    @if($canReadAssets)
                                        <a href="{{ $assetRegisterUrl }}" style="display:inline-flex; align-items:center; justify-content:center; padding:9px 12px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700; font-size:13px;">Asset Register</a>
                                    @endif
                                    @if($canUpdateProducts)
                                        <a href="{{ $convertUrl }}" style="display:inline-flex; align-items:center; justify-content:center; padding:9px 12px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700; font-size:13px;">Convert</a>
                                    @endif
                                    <details class="product-action-menu">
                                        <summary aria-label="More actions for {{ $product->name }}">...</summary>
                                        <div class="product-action-panel">
                                            @if($canUpdateProducts)
                                                <a href="{{ route('products.edit', $product) }}" class="product-action-link">Edit Product</a>
                                            @endif
                                            @if($canUpdateProducts && $primaryType === \App\Models\Product::TYPE_SELLABLE)
                                                <a href="{{ route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']) }}" class="product-action-link">Add Sale Units</a>
                                            @endif
                                            @if($canReadAssets)
                                                <a href="{{ route('products.show', $product) }}#rental-assets" class="product-action-link">Open Asset Register</a>
                                            @endif
                                            @if($canCreateAssets)
                                                <a href="{{ route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']) }}" class="product-action-link">Add Sale Unit</a>
                                                <a href="{{ route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'rental_stock']) }}" class="product-action-link">Add Rental Asset</a>
                                            @endif
                                            @if($canUpdateProducts && $canCreateAssets && $hasSaleStock)
                                                <a href="{{ route('products.show', $product) }}#convert-stock" class="product-action-link">Convert to Rental Assets</a>
                                            @endif
                                            @if($canUpdateProducts && $hasRentalAssets)
                                                <a href="{{ route('products.show', $product) }}#convert-rental-stock" class="product-action-link">Convert to Sale Units</a>
                                            @endif
                                            @if($canDeleteProducts)
                                                <form method="POST" action="{{ route('products.destroy', $product) }}" style="margin:0;" onsubmit="return confirm('Delete this product? This will be blocked if dependencies exist.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="danger">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding:28px 18px; color:#64748b;">
                                No products match this view yet. Add a product or widen the filters to continue.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:20px;">
        {{ $products->links() }}
    </div>
    </div>
    @if($canCreateProducts)
        @include('partials.mobile-fab', ['href' => route('products.create'), 'label' => 'Add Product'])
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        const search = document.getElementById('product-mobile-search');
        const sort = document.getElementById('product-mobile-sort');
        const list = document.getElementById('product-mobile-list');
        const rows = Array.from(document.querySelectorAll('[data-product-row]'));
        const filterButtons = Array.from(document.querySelectorAll('[data-product-filter]'));
        let activeFilter = 'all';

        const matchesFilter = (row) => {
            if (activeFilter === 'sellable') return row.dataset.productSellable === '1';
            if (activeFilter === 'rentable') return row.dataset.productRentable === '1';
            if (activeFilter === 'available') return Number(row.dataset.productRentalAvailable || 0) > 0;
            return true;
        };

        const applyProductControls = () => {
            const term = (search?.value || '').trim().toLowerCase();
            rows.forEach((row) => {
                const searchMatch = term === '' || (row.dataset.productSearch || '').includes(term);
                row.style.display = searchMatch && matchesFilter(row) ? '' : 'none';
            });

            if (!list || !sort) return;

            const sorted = [...rows].sort((a, b) => {
                if (sort.value === 'name_desc') {
                    return (b.dataset.productName || '').localeCompare(a.dataset.productName || '');
                }
                if (sort.value === 'sale_stock_desc') {
                    return Number(b.dataset.productSaleStock || 0) - Number(a.dataset.productSaleStock || 0);
                }
                if (sort.value === 'rental_available_desc') {
                    return Number(b.dataset.productRentalAvailable || 0) - Number(a.dataset.productRentalAvailable || 0);
                }

                return (a.dataset.productName || '').localeCompare(b.dataset.productName || '');
            });

            sorted.forEach((row) => list.appendChild(row));
        };

        search?.addEventListener('input', applyProductControls);
        sort?.addEventListener('change', applyProductControls);
        filterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                activeFilter = button.dataset.productFilter || 'all';
                filterButtons.forEach((item) => item.classList.toggle('is-active', item.dataset.productFilter === activeFilter));
                applyProductControls();
            });
        });
    })();
</script>
@endpush
