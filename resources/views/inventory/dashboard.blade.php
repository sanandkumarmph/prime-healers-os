@extends('layouts.app')

@section('content')
    <style>
        .inventory-page {
            display:grid;
            gap:18px;
        }
        .inventory-hero {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            margin-bottom:6px;
            flex-wrap:wrap;
        }
        .inventory-actions {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .inventory-panel {
            background:#ffffff;
            border:1px solid #e2e8f0;
            border-radius:22px;
            overflow:hidden;
        }
        .inventory-panel-head {
            padding:20px 22px;
            border-bottom:1px solid #e2e8f0;
        }
        .inventory-panel-copy {
            margin:8px 0 0;
            color:#64748b;
            font-size:13px;
            line-height:1.5;
        }
        .inventory-scan {
            padding:20px 22px;
            border-radius:22px;
            background:#ffffff;
            border:1px solid #e2e8f0;
        }
        .inventory-cards {
            display:grid;
            grid-template-columns:repeat(6, minmax(0, 1fr));
            gap:16px;
        }
        .inventory-stat-card {
            display:block;
            padding:20px;
            border-radius:20px;
            border:1px solid #e2e8f0;
            background:#ffffff;
            text-decoration:none;
            color:inherit;
            transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .inventory-layout {
            display:grid;
            grid-template-columns:minmax(0, 1.2fr) minmax(0, 0.8fr);
            gap:18px;
        }
        .inventory-quick-actions {
            display:grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:14px;
        }
        .inventory-quick-card {
            display:grid;
            gap:6px;
            padding:18px;
            border-radius:20px;
            border:1px solid #e2e8f0;
            background:#ffffff;
            text-decoration:none;
            color:inherit;
        }
        .inventory-warehouse-list {
            padding:18px 20px;
            display:grid;
            gap:14px;
        }
        .inventory-filter-chips {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .inventory-filter-chip {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:36px;
            padding:8px 12px;
            border-radius:999px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#334155;
            text-decoration:none;
            font-size:12px;
            font-weight:700;
        }
        .inventory-filter-chip.is-active {
            border-color:#bfdbfe;
            background:#eff6ff;
            color:#1d4ed8;
        }
        .inventory-stock-table-wrap {
            overflow:auto;
        }
        .inventory-stock-table {
            width:100%;
            border-collapse:collapse;
        }
        .inventory-stock-table th {
            position:sticky;
            top:0;
            z-index:2;
            background:#f8fafc;
        }
        .inventory-stock-table th,
        .inventory-stock-table td {
            padding:14px 16px;
            border-top:1px solid #e2e8f0;
            text-align:left;
            vertical-align:top;
            font-size:13px;
        }
        .inventory-product-name {
            font-size:15px;
            font-weight:800;
            color:#0f172a;
        }
        .inventory-product-meta {
            margin-top:4px;
            color:#64748b;
            font-size:12px;
            line-height:1.45;
        }
        .inventory-type-badge,
        .inventory-signal-badge {
            display:inline-flex;
            align-items:center;
            min-height:24px;
            padding:4px 8px;
            border-radius:999px;
            font-size:11px;
            font-weight:700;
            line-height:1;
            white-space:nowrap;
        }
        .inventory-type-badge.is-rental {
            background:#eff6ff;
            color:#1d4ed8;
        }
        .inventory-type-badge.is-sale {
            background:#fff7ed;
            color:#c2410c;
        }
        .inventory-type-badge.is-category {
            background:#f8fafc;
            color:#475569;
        }
        .inventory-signal-badge.is-warning {
            background:#fef3c7;
            color:#b45309;
        }
        .inventory-signal-badge.is-danger {
            background:#fee2e2;
            color:#b91c1c;
        }
        .inventory-signal-badge.is-info {
            background:#dbeafe;
            color:#1d4ed8;
        }
        .inventory-signal-badge.is-success {
            background:#dcfce7;
            color:#166534;
        }
        .inventory-stock-number {
            display:block;
            font-size:18px;
            font-weight:800;
            color:#0f172a;
        }
        .inventory-stock-caption {
            display:block;
            margin-top:4px;
            color:#64748b;
            font-size:11px;
            line-height:1.35;
        }
        .inventory-actions-cell {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }
        .inventory-actions-cell a {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:34px;
            padding:8px 10px;
            border-radius:10px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#0f172a;
            text-decoration:none;
            font-size:12px;
            font-weight:700;
        }
        .inventory-stock-link {
            display:block;
            color:inherit;
            text-decoration:none;
        }
        .inventory-stock-link:hover .inventory-stock-number,
        .inventory-stock-link:hover .inventory-stock-caption {
            color:#1d4ed8;
        }
        .inventory-product-cards {
            display:none;
        }
        .inventory-product-card {
            display:grid;
            gap:12px;
            padding:14px;
            border-top:1px solid #e2e8f0;
        }
        .inventory-product-card:first-child {
            border-top:none;
        }
        .inventory-product-card-grid {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:10px;
        }
        .inventory-mini-group {
            padding:12px;
            border-radius:14px;
            border:1px solid #e2e8f0;
            background:#f8fafc;
        }
        .inventory-mini-group h3 {
            margin:0 0 8px;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:#64748b;
        }
        .inventory-mini-stats {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .inventory-mini-stat strong {
            display:block;
            font-size:17px;
            color:#0f172a;
        }
        .inventory-mini-stat span {
            display:block;
            margin-top:2px;
            font-size:11px;
            color:#64748b;
        }
        @media (max-width: 767px) {
            .inventory-page {
                gap:14px;
            }
            .inventory-hero h1 {
                margin:8px 0 4px !important;
                font-size:26px !important;
            }
            .inventory-hero p,
            .inventory-panel-copy {
                font-size:12px !important;
                line-height:1.45 !important;
            }
            .inventory-actions {
                width:100%;
                display:grid;
                grid-template-columns:1fr;
            }
            .inventory-actions a {
                width:100%;
            }
            .inventory-scan,
            .inventory-panel {
                border-radius:16px;
            }
            .inventory-scan,
            .inventory-panel-head,
            .inventory-warehouse-list {
                padding:14px;
            }
            .inventory-cards {
                grid-template-columns:repeat(2, minmax(0, 1fr));
                gap:10px;
            }
            .inventory-stat-card {
                padding:12px;
                border-radius:14px;
            }
            .inventory-stat-card div:first-child {
                font-size:10px !important;
                line-height:1.35;
            }
            .inventory-stat-card div:last-child {
                margin-top:6px !important;
                font-size:22px !important;
            }
            .inventory-layout {
                grid-template-columns:1fr;
                gap:14px;
            }
            .inventory-quick-actions {
                grid-template-columns:repeat(2, minmax(0, 1fr));
                gap:10px;
            }
            .inventory-quick-card {
                padding:12px;
                border-radius:14px;
            }
            .inventory-mobile-hide {
                display:none !important;
            }
            .inventory-stock-table-wrap {
                display:none;
            }
            .inventory-product-cards {
                display:grid;
            }
            .inventory-filter-chips {
                gap:8px;
            }
            .inventory-filter-chip {
                min-height:34px;
                padding:7px 10px;
                font-size:11px;
            }
            #inventoryLookupForm {
                display:grid !important;
                grid-template-columns:1fr;
            }
            #inventoryLookupInput,
            #inventoryLookupCameraButton,
            #inventoryLookupForm button[type="submit"] {
                width:100%;
                min-width:0 !important;
            }
            table th,
            table td {
                padding:12px 10px !important;
                font-size:12px !important;
            }
        }
    </style>

    <div class="inventory-page">
    <div class="inventory-hero">
        <div>
            <div style="display:inline-flex; padding:6px 10px; border-radius:999px; background:#ecfeff; color:#0f766e; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">Inventory Overview</div>
            <h1 style="margin:12px 0 8px; font-size:34px; letter-spacing:-0.03em;">Inventory Overview</h1>
            <p style="margin:0; color:#64748b;">Product Master stores catalog and pricing. Add Stock creates physical units. Asset Register tracks serials, barcodes, warehouses, and workflow status.</p>
        </div>

        <div class="inventory-actions">
            <a href="{{ route('assets.create', ['asset_stage' => 'new_stock']) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; background:#0f766e; color:#ffffff; text-decoration:none; font-weight:700;">+ Add Sale Unit</a>
            <a href="{{ route('assets.create', ['asset_stage' => 'rental_stock']) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">+ Add Rental Asset</a>
            <a href="{{ route('warehouses.create') }}" style="display:inline-flex; align-items:center; justify-content:center; padding:11px 16px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:600;">+ Add Warehouse</a>
        </div>
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

    <div class="inventory-scan">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px;">
            <div>
                <h2 style="margin:0; font-size:22px;">Quick Scan / Search</h2>
                <p class="inventory-panel-copy">Scan or search serial / barcode.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('assets.scan-lookup') }}" id="inventoryLookupForm" style="display:flex; gap:12px; flex-wrap:wrap;">
            <input type="text" name="lookup" id="inventoryLookupInput" placeholder="Scan barcode / enter serial number" autofocus autocomplete="off" spellcheck="false"
                   style="flex:1; min-width:280px; padding:14px 16px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; font-size:15px;">
            <input type="file" id="inventoryLookupCameraInput" accept="image/*" capture="environment" style="display:none;">
            <button type="button" id="inventoryLookupCameraButton" style="display:inline-flex; align-items:center; justify-content:center; padding:0 18px; min-height:48px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-weight:700; cursor:pointer;">
                Open Camera
            </button>
            <button type="submit" style="display:inline-flex; align-items:center; justify-content:center; padding:0 18px; min-height:48px; border:none; border-radius:14px; background:#0f172a; color:#ffffff; font-weight:700; cursor:pointer;">
                Search
            </button>
        </form>
    </div>

    <div class="inventory-quick-actions">
        <a href="{{ route('assets.create', ['asset_stage' => 'new_stock']) }}" class="inventory-quick-card" style="background:#fff7ed; border-color:#fed7aa;">
            <div style="font-size:11px; color:#9a3412; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Add Stock</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Add Sale Unit</div>
            <div style="font-size:13px; color:#64748b; line-height:1.5;">Create one fresh physical unit for sale stock.</div>
        </a>
        <a href="{{ route('assets.create', ['asset_stage' => 'rental_stock']) }}" class="inventory-quick-card" style="background:#eff6ff; border-color:#bfdbfe;">
            <div style="font-size:11px; color:#1d4ed8; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Add Stock</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Add Rental Asset</div>
            <div style="font-size:13px; color:#64748b; line-height:1.5;">Create one rental-ready physical unit for dispatch and return workflows.</div>
        </a>
        <a href="{{ route('assets.pending-verification') }}" class="inventory-quick-card" style="background:#fef3c7; border-color:#fde68a;">
            <div style="font-size:11px; color:#b45309; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Operations Queue</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Return Verification</div>
            <div style="font-size:13px; color:#64748b; line-height:1.5;">Review returned rental units before they go back into service.</div>
        </a>
        <a href="{{ route('assets.index') }}" class="inventory-quick-card">
            <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Workspace</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Asset Register</div>
            <div style="font-size:13px; color:#64748b; line-height:1.5;">Open serials, barcodes, warehouse locations, and unit status.</div>
        </a>
    </div>

    <div class="inventory-cards">
        @php
            $cards = [
                ['label' => 'Product Master', 'value' => $dashboard['total_products'], 'color' => '#0f172a', 'url' => route('products.index')],
                ['label' => 'Sellable Products', 'value' => $dashboard['sellable_products'], 'color' => '#166534', 'url' => route('products.index')],
                ['label' => 'Rentable Products', 'value' => $dashboard['rentable_products'], 'color' => '#1d4ed8', 'url' => route('products.index')],
                ['label' => 'Sale Units', 'value' => $dashboard['sale_stock'], 'color' => '#9a3412', 'url' => route('products.index')],
                ['label' => 'Rental Assets', 'value' => $dashboard['total_assets'], 'color' => '#6d28d9', 'url' => route('assets.index', ['asset_stage' => 'rental_stock'])],
                ['label' => 'Rental Available', 'value' => $dashboard['available_assets'], 'color' => '#166534', 'url' => route('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'available'])],
                ['label' => 'Maintenance', 'value' => $dashboard['maintenance_assets'], 'color' => '#c2410c', 'url' => route('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'maintenance'])],
                ['label' => 'Warehouses', 'value' => $dashboard['warehouse_count'], 'color' => '#6d28d9', 'url' => route('warehouses.index')],
            ];
        @endphp
        @foreach($cards as $card)
            <a href="{{ $card['url'] }}" class="inventory-stat-card">
                <div style="font-size:12px; color:#64748b; font-weight:700; text-transform:uppercase;">{{ $card['label'] }}</div>
                <div style="margin-top:10px; font-size:30px; font-weight:700; color:{{ $card['color'] }};">{{ $card['value'] }}</div>
            </a>
        @endforeach
    </div>

    @php
        $stockViewUrl = function (?string $view = null) {
            $query = request()->query();

            if ($view === null || $view === 'all') {
                unset($query['stock_view']);
            } else {
                $query['stock_view'] = $view;
            }

            return route('inventory.dashboard', $query);
        };
        $signalClass = fn ($tone) => match ($tone) {
            'warning' => 'is-warning',
            'danger' => 'is-danger',
            'info' => 'is-info',
            'success' => 'is-success',
            default => '',
        };
        $productAssetUrl = function ($product, array $filters = []) {
            return route('assets.index', array_filter(array_merge([
                'product_id' => $product->id,
            ], $filters), fn ($value) => $value !== null && $value !== ''));
        };
    @endphp

    <div class="inventory-panel">
        <div class="inventory-panel-head" style="display:grid; gap:14px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0; font-size:22px;">Product Stock Position</h2>
                    <p class="inventory-panel-copy">Combined rental and sales visibility by product. Tracked products use Asset Register counts, while untracked products use the opening quantity from Product Master.</p>
                </div>
            </div>
            <div class="inventory-filter-chips">
                <a href="{{ $stockViewUrl('all') }}" class="inventory-filter-chip {{ ($stockView ?? 'all') === 'all' ? 'is-active' : '' }}">All Products</a>
                <a href="{{ $stockViewUrl('rental_active') }}" class="inventory-filter-chip {{ ($stockView ?? '') === 'rental_active' ? 'is-active' : '' }}">Rental Active</a>
                <a href="{{ $stockViewUrl('sales_active') }}" class="inventory-filter-chip {{ ($stockView ?? '') === 'sales_active' ? 'is-active' : '' }}">Sales Active</a>
                <a href="{{ $stockViewUrl('low_stock') }}" class="inventory-filter-chip {{ ($stockView ?? '') === 'low_stock' ? 'is-active' : '' }}">Low Stock</a>
                <a href="{{ $stockViewUrl('awaiting_verification') }}" class="inventory-filter-chip {{ ($stockView ?? '') === 'awaiting_verification' ? 'is-active' : '' }}">Awaiting Verification</a>
            </div>
        </div>

        @if(($productStockRows ?? collect())->isEmpty())
            <div style="padding:18px 22px; color:#64748b;">No products match the current stock view.</div>
        @else
            <div class="inventory-stock-table-wrap">
                <table class="inventory-stock-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Rental Assets</th>
                            <th>Rental Available</th>
                            <th>Rented Out</th>
                            <th>Awaiting Verification</th>
                            <th>Under Repair</th>
                            <th>Sale Units</th>
                            <th>Available to Sell</th>
                            <th>Reserved for Sale</th>
                            <th>Sold Units</th>
                            <th>Retired</th>
                            <th>Signals</th>
                            <th>Quick Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($productStockRows as $productRow)
                            <tr>
                                <td>
                                    <div class="inventory-product-name">{{ $productRow->name }}</div>
                                    <div class="inventory-product-meta">
                                        {{ collect([$productRow->brand, $productRow->model_name])->filter()->join(' | ') ?: 'Brand / model not set' }}
                                    </div>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                                        @if(filled($productRow->category))
                                            <span class="inventory-type-badge is-category">{{ $productRow->category }}</span>
                                        @endif
                                        @if($productRow->tracksRentalStock())
                                            <span class="inventory-type-badge is-rental">Rental Asset</span>
                                        @endif
                                        @if($productRow->tracksSaleStock())
                                            <span class="inventory-type-badge is-sale">Sale Unit</span>
                                        @endif
                                        @if($productRow->usesUntrackedStock())
                                            <span class="inventory-type-badge is-category">Untracked Opening Stock</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->effective_rental_assets_total_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'available']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->effective_rental_available_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->rental_out_count }}</span>
                                        <span class="inventory-stock-caption">Reserved or with customer</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'awaiting_verification']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->awaiting_verification_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'maintenance']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->under_repair_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->effective_sale_units_total_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'available_for_sale']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->effective_sale_available_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'reserved_for_sale']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->sale_reserved_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'sold']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->sold_units_count }}</span>
                                    </a>
                                </td>
                                <td>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_status' => 'retired']) }}" class="inventory-stock-link">
                                        <span class="inventory-stock-number">{{ (int) $productRow->retired_rental_count + (int) $productRow->retired_sale_count }}</span>
                                        <span class="inventory-stock-caption">Rental + sale retired</span>
                                    </a>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        @forelse($productRow->stock_signals as $signal)
                                            <span class="inventory-signal-badge {{ $signalClass($signal['tone'] ?? null) }}">{{ $signal['label'] }}</span>
                                        @empty
                                            <span class="inventory-stock-caption" style="margin-top:0;">Stable</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="inventory-actions-cell">
                                        <a href="{{ route('products.show', $productRow) }}">View Product</a>
                                        <a href="{{ route('assets.index', ['product_id' => $productRow->id]) }}">View Assets</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="inventory-product-cards">
                @foreach($productStockRows as $productRow)
                    <div class="inventory-product-card">
                        <div>
                            <div class="inventory-product-name">{{ $productRow->name }}</div>
                            <div class="inventory-product-meta">
                                {{ collect([$productRow->brand, $productRow->model_name])->filter()->join(' | ') ?: 'Brand / model not set' }}
                            </div>
                            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                                @if(filled($productRow->category))
                                    <span class="inventory-type-badge is-category">{{ $productRow->category }}</span>
                                @endif
                                @if($productRow->tracksRentalStock())
                                    <span class="inventory-type-badge is-rental">Rental Asset</span>
                                @endif
                                @if($productRow->tracksSaleStock())
                                    <span class="inventory-type-badge is-sale">Sale Unit</span>
                                @endif
                                @if($productRow->usesUntrackedStock())
                                    <span class="inventory-type-badge is-category">Untracked Opening Stock</span>
                                @endif
                            </div>
                        </div>

                        <div class="inventory-product-card-grid">
                            <div class="inventory-mini-group">
                                <h3>Rental</h3>
                                <div class="inventory-mini-stats">
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->effective_rental_assets_total_count }}</strong><span>Total</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'available']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->effective_rental_available_count }}</strong><span>Avail</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->rental_out_count }}</strong><span>Out</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'awaiting_verification']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->awaiting_verification_count }}</strong><span>Verify</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'maintenance']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->under_repair_count }}</strong><span>Repair</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'retired']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->retired_rental_count }}</strong><span>Retired</span></a>
                                </div>
                            </div>
                            <div class="inventory-mini-group">
                                <h3>Sales</h3>
                                <div class="inventory-mini-stats">
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->effective_sale_units_total_count }}</strong><span>Total</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'available_for_sale']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->effective_sale_available_count }}</strong><span>Avail</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'reserved_for_sale']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->sale_reserved_count }}</strong><span>Reserved</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'sold']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->sold_units_count }}</strong><span>Sold</span></a>
                                    <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'retired']) }}" class="inventory-mini-stat inventory-stock-link"><strong>{{ (int) $productRow->retired_sale_count }}</strong><span>Retired</span></a>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            @forelse($productRow->stock_signals as $signal)
                                <span class="inventory-signal-badge {{ $signalClass($signal['tone'] ?? null) }}">{{ $signal['label'] }}</span>
                            @empty
                                <span class="inventory-signal-badge is-info">Stable</span>
                            @endforelse
                        </div>

                        <div class="inventory-actions-cell">
                            <a href="{{ route('products.show', $productRow) }}">View Product</a>
                            <a href="{{ route('assets.index', ['product_id' => $productRow->id]) }}">View Assets</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="inventory-layout">
        <div class="inventory-panel">
            <div class="inventory-panel-head">
                <h2 style="margin:0; font-size:22px;">Recent Asset Movements</h2>
                <p class="inventory-panel-copy">Latest inward, transfer, and service updates.</p>
            </div>

            <div class="inventory-mobile-hide" style="overflow:auto;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; color:#64748b;">Asset</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; color:#64748b;">Type</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; color:#64748b;">From</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; color:#64748b;">To</th>
                            <th style="text-align:left; padding:14px 18px; font-size:12px; text-transform:uppercase; color:#64748b;">By</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($recentMovements as $movement)
                        <tr style="border-top:1px solid #e2e8f0;">
                            <td style="padding:16px 18px;">
                                <div style="font-weight:700;">{{ optional($movement->asset)->serial_number ?: 'N/A' }}</div>
                                <div style="margin-top:4px; color:#64748b; font-size:13px;">{{ optional(optional($movement->asset)->product)->name ?: 'Asset' }}</div>
                            </td>
                            <td style="padding:16px 18px; text-transform:capitalize;">{{ $movement->movement_type }}</td>
                            <td style="padding:16px 18px;">{{ optional($movement->fromWarehouse)->name ?: 'N/A' }}</td>
                            <td style="padding:16px 18px;">{{ optional($movement->toWarehouse)->name ?: 'N/A' }}</td>
                            <td style="padding:16px 18px;">{{ optional($movement->movedBy)->name ?: 'System' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding:22px 18px; color:#64748b;">No movement history available yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:grid; gap:18px;">
            <div class="inventory-panel">
                <div class="inventory-panel-head">
                    <h2 style="margin:0; font-size:22px;">Warehouse Snapshot</h2>
                <p class="inventory-panel-copy">Availability by warehouse.</p>
                </div>
                <div class="inventory-warehouse-list">
                    @forelse($warehouseSummaries as $warehouse)
                        <div style="padding:16px; border-radius:18px; border:1px solid #e2e8f0; background:#f8fafc;">
                            <div style="display:flex; justify-content:space-between; gap:12px;">
                                <div>
                                    <div style="font-size:18px; font-weight:700;">{{ $warehouse->name }}</div>
                                    <div style="margin-top:4px; color:#64748b; font-size:13px;">{{ $warehouse->code ?: 'No code' }}</div>
                                </div>
                                <a href="{{ route('warehouses.show', $warehouse) }}" style="color:#0f766e; text-decoration:none; font-weight:700;">View</a>
                            </div>
                            <div style="display:flex; gap:14px; margin-top:12px; color:#475569; font-size:13px;">
                                <span>Rental: <strong>{{ $warehouse->total_assets_count }}</strong></span>
                                <span>Available: <strong>{{ $warehouse->available_assets_count }}</strong></span>
                                <span>Rented: <strong>{{ $warehouse->rented_assets_count }}</strong></span>
                            </div>
                        </div>
                    @empty
                        <div style="padding:16px; border-radius:18px; border:1px dashed #cbd5e1; color:#64748b;">
                            No warehouses configured yet.
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
    const lookupInput = document.getElementById('inventoryLookupInput');
    const lookupForm = document.getElementById('inventoryLookupForm');
    const lookupCameraInput = document.getElementById('inventoryLookupCameraInput');
    const lookupCameraButton = document.getElementById('inventoryLookupCameraButton');

    if (!lookupInput || !lookupForm) {
        return;
    }

    lookupInput.focus();

    lookupInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            lookupForm.submit();
        }
    });

    async function decodeBarcodeFromFile(file) {
        if (!('BarcodeDetector' in window)) {
            alert('Camera capture is available, but barcode decoding is not supported on this browser. Please use Chrome on mobile or type the barcode manually.');
            return;
        }

        try {
            const detector = new BarcodeDetector({
                formats: ['code_128', 'code_39', 'codabar', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code']
            });
            const bitmap = await createImageBitmap(file);
            const barcodes = await detector.detect(bitmap);

            if (!barcodes.length) {
                alert('No barcode was detected in that image. Please try again with a clearer image.');
                return;
            }

            lookupInput.value = barcodes[0].rawValue || '';
            lookupForm.submit();
        } catch (error) {
            alert('Unable to decode barcode from the captured image. Please try again or type it manually.');
        }
    }

    if (lookupCameraButton && lookupCameraInput) {
        lookupCameraButton.addEventListener('click', function () {
            lookupCameraInput.click();
        });

        lookupCameraInput.addEventListener('change', function (event) {
            const file = event.target.files && event.target.files[0];

            if (file) {
                decodeBarcodeFromFile(file);
            }
        });
    }
});
</script>
@endpush
