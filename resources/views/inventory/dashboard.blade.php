@extends('layouts.app')

@section('content')
    @php
        $stockRows = collect($productStockRows ?? []);
        $sumStock = fn (string $key) => (int) $stockRows->sum(fn ($row) => (int) ($row->{$key} ?? 0));
        $totalRentalAssets = (int) ($dashboard['total_assets'] ?? $sumStock('effective_rental_assets_total_count'));
        $rentalAvailable = (int) ($dashboard['available_assets'] ?? $sumStock('effective_rental_available_count'));
        $rentedOut = $sumStock('rental_out_count');
        $awaitingVerification = $sumStock('awaiting_verification_count');
        $underRepair = (int) ($dashboard['maintenance_assets'] ?? $sumStock('under_repair_count'));
        $saleUnits = $sumStock('effective_sale_units_total_count');
        $availableToSell = (int) ($dashboard['sale_stock'] ?? $sumStock('effective_sale_available_count'));
        $saleReserved = $sumStock('sale_reserved_count');
        $soldUnits = $sumStock('sold_units_count');
        $retiredUnits = $sumStock('retired_rental_count') + $sumStock('retired_sale_count');
        $lowStockRows = $stockRows->filter(fn ($row) => collect($row->stock_signals ?? [])->contains(fn ($signal) => in_array($signal['tone'] ?? null, ['warning', 'danger'], true)));
        $attentionCount = $underRepair + $awaitingVerification + $lowStockRows->count();
        $rentalDenominator = max($rentalAvailable + $rentedOut + $awaitingVerification, 1);
        $salesDenominator = max($availableToSell + $soldUnits + $saleReserved, 1);
        $metricDisplay = fn ($value) => ((int) $value) === 0 ? '–' : number_format((int) $value);
    @endphp
    <style>
        .inventory-page {
            display:grid;
            gap:10px;
        }
        .inventory-hero {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:10px;
            margin-bottom:0;
            flex-wrap:wrap;
        }
        .inventory-actions {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }
        .inventory-hero .inventory-eyebrow {
            display:inline-flex;
            padding:4px 9px;
            border-radius:999px;
            background:#ccfbf1;
            color:#0f766e;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.08em;
        }
        .inventory-hero h1 {
            margin:7px 0 4px !important;
            font-size:30px !important;
            line-height:1.05;
            letter-spacing:-.03em;
        }
        .inventory-hero p {
            max-width:880px;
            margin:0 !important;
            color:#64748b;
            font-size:14px;
            line-height:1.4;
        }
        .inventory-actions a {
            min-height:36px !important;
            padding:0 12px !important;
            border-radius:11px !important;
            font-size:13px;
        }
        .inventory-panel {
            background:#ffffff;
            border:1px solid #e2e8f0;
            border-radius:16px;
            overflow:hidden;
        }
        .inventory-panel-head {
            padding:12px 14px;
            border-bottom:1px solid #e2e8f0;
        }
        .inventory-panel-copy {
            margin:8px 0 0;
            color:#64748b;
            font-size:13px;
            line-height:1.5;
        }
        .inventory-scan {
            padding:10px 12px;
            border-radius:16px;
            background:#ffffff;
            border:1px solid #e2e8f0;
        }
        .inventory-scan-head {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:12px;
            margin-bottom:8px;
        }
        .inventory-scan-head h2 {
            margin:0;
            font-size:17px;
            line-height:1.15;
        }
        .inventory-scan-head .inventory-panel-copy {
            margin-top:3px;
            font-size:11.5px;
        }
        #inventoryLookupForm {
            display:grid !important;
            grid-template-columns:minmax(220px, 1fr) auto auto;
            gap:8px !important;
            align-items:center;
        }
        #inventoryLookupInput {
            min-width:0 !important;
            min-height:38px;
            padding:8px 11px !important;
            border-radius:11px !important;
            font-size:13px !important;
        }
        #inventoryLookupCameraButton,
        #inventoryLookupForm button[type="submit"] {
            min-height:38px !important;
            padding:0 12px !important;
            border-radius:11px !important;
            font-size:13px;
        }
        .inventory-cards {
            display:grid;
            grid-template-columns:repeat(8, minmax(0, 1fr));
            gap:8px;
        }
        .inventory-stat-card {
            display:block;
            padding:10px 11px;
            border-radius:14px;
            border:1px solid #e2e8f0;
            background:#ffffff;
            text-decoration:none;
            color:inherit;
            transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .inventory-layout {
            display:grid;
            grid-template-columns:minmax(0, 1.2fr) minmax(0, 0.8fr);
            gap:10px;
        }
        .inventory-quick-actions {
            display:grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:8px;
        }
        .inventory-quick-card {
            display:grid;
            gap:2px;
            padding:8px 10px;
            border-radius:14px;
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
            padding:7px 9px;
            border-top:1px solid #e2e8f0;
            text-align:left;
            vertical-align:middle;
            font-size:11.5px;
        }
        .inventory-stock-table tbody tr {
            border-left:4px solid #16a34a;
        }
        .inventory-stock-table tbody tr.is-attention {
            border-left-color:#dc2626;
            background:#fff7f7;
        }
        .inventory-stock-table tbody tr.is-warning {
            border-left-color:#f59e0b;
            background:#fffbeb;
        }
        .inventory-stock-table tbody tr.is-inactive {
            border-left-color:#94a3b8;
        }
        .inventory-stock-table tbody tr > td:first-child {
            border-left:4px solid #16a34a;
        }
        .inventory-stock-table tbody tr.is-attention > td:first-child {
            border-left-color:#dc2626;
        }
        .inventory-stock-table tbody tr.is-warning > td:first-child {
            border-left-color:#f59e0b;
        }
        .inventory-stock-table tbody tr.is-inactive > td:first-child {
            border-left-color:#94a3b8;
        }
        .inventory-product-name {
            font-size:13px;
            font-weight:800;
            color:#0f172a;
            line-height:1.2;
        }
        .inventory-product-meta {
            margin-top:2px;
            color:#64748b;
            font-size:10.5px;
            line-height:1.25;
        }
        .inventory-type-badge,
        .inventory-signal-badge {
            display:inline-flex;
            align-items:center;
            min-height:24px;
            padding:3px 7px;
            border-radius:999px;
            font-size:9.5px;
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
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:22px;
            font-size:13px;
            font-weight:800;
            color:#0f172a;
        }
        .inventory-stock-number.is-zero {
            color:#94a3b8;
            font-weight:700;
        }
        .inventory-stock-number.is-green { color:#15803d; }
        .inventory-stock-number.is-blue { color:#1d4ed8; }
        .inventory-stock-number.is-amber { color:#b45309; }
        .inventory-stock-number.is-red { color:#b91c1c; }
        .inventory-stock-number.is-grey { color:#64748b; }
        .inventory-stock-caption {
            display:block;
            margin-top:1px;
            color:#64748b;
            font-size:9.5px;
            line-height:1.15;
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
            padding:6px 8px;
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
        .inventory-kpi-label {
            font-size:10px;
            color:#64748b;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.04em;
            line-height:1.2;
        }
        .inventory-kpi-value {
            margin-top:4px;
            font-size:22px;
            font-weight:900;
            line-height:1;
        }
        .inventory-kpi-card {
            position:relative;
            overflow:hidden;
        }
        .inventory-kpi-card::before {
            content:"";
            position:absolute;
            inset:0 auto 0 0;
            width:4px;
            background:#94a3b8;
        }
        .inventory-kpi-card.tone-green::before { background:#16a34a; }
        .inventory-kpi-card.tone-blue::before { background:#2563eb; }
        .inventory-kpi-card.tone-amber::before { background:#f59e0b; }
        .inventory-kpi-card.tone-red::before { background:#dc2626; }
        .inventory-kpi-card.tone-grey::before { background:#94a3b8; }
        .inventory-decision-grid {
            display:grid;
            grid-template-columns:1.1fr .95fr .95fr;
            gap:8px;
        }
        .inventory-decision-card {
            padding:10px;
            border:1px solid #e2e8f0;
            border-radius:14px;
            background:#fff;
            display:grid;
            gap:7px;
        }
        .inventory-decision-card h3 {
            margin:0;
            font-size:13px;
            color:#0f172a;
        }
        .inventory-decision-card p {
            margin:0;
            color:#64748b;
            font-size:11px;
            line-height:1.3;
        }
        .inventory-bar {
            height:10px;
            display:flex;
            overflow:hidden;
            border-radius:999px;
            background:#e2e8f0;
        }
        .inventory-bar span { min-width:2px; }
        .inventory-bar .green { background:#16a34a; }
        .inventory-bar .blue { background:#2563eb; }
        .inventory-bar .amber { background:#f59e0b; }
        .inventory-bar .red { background:#dc2626; }
        .inventory-readiness-values {
            display:grid;
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:5px;
        }
        .inventory-readiness-values span {
            display:grid;
            gap:2px;
            padding:5px 7px;
            border:1px solid #e2e8f0;
            border-radius:10px;
            background:#f8fafc;
            color:#64748b;
            font-size:9.5px;
            font-weight:800;
            text-transform:uppercase;
        }
        .inventory-readiness-values strong {
            color:#0f172a;
            font-size:14px;
            line-height:1;
        }
        .inventory-stat-group {
            display:grid;
            grid-template-columns:repeat(4, minmax(38px, 1fr));
            gap:4px;
        }
        .inventory-stat-chip {
            min-width:0;
            padding:5px 6px;
            border-radius:10px;
            background:#f8fafc;
            border:1px solid #e2e8f0;
            text-align:center;
            text-decoration:none;
        }
        .inventory-stat-chip span {
            display:block;
            color:#64748b;
            font-size:9px;
            font-weight:800;
            text-transform:uppercase;
            line-height:1.1;
        }
        .inventory-stat-chip strong {
            display:block;
            margin-top:2px;
            font-size:13px;
            line-height:1;
        }
        .inventory-stat-chip.is-green strong { color:#15803d; }
        .inventory-stat-chip.is-blue strong { color:#1d4ed8; }
        .inventory-stat-chip.is-amber strong { color:#b45309; }
        .inventory-stat-chip.is-red strong { color:#b91c1c; }
        .inventory-stat-chip.is-grey strong,
        .inventory-stat-chip.is-zero strong,
        .inventory-stat-chip strong.is-zero { color:#94a3b8; }
        .inventory-row-click {
            color:inherit;
            text-decoration:none;
        }
        .inventory-row-click:hover .inventory-product-name {
            color:#1d4ed8;
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
        @media (max-width: 1280px) {
            .inventory-cards {
                grid-template-columns:repeat(4, minmax(0, 1fr));
            }
            .inventory-decision-grid {
                grid-template-columns:1fr;
            }
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
            .inventory-decision-grid {
                grid-template-columns:1fr;
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
            <div class="inventory-eyebrow">Inventory Overview</div>
            <h1>Inventory Overview</h1>
            <p>Product catalog, physical stock, serials, barcodes, warehouses, and workflow status.</p>
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
        <div class="inventory-scan-head">
            <div>
                <h2>Quick Scan / Search</h2>
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
            <div style="font-size:12px; color:#64748b; line-height:1.25;">Fresh unit for sale stock.</div>
        </a>
        <a href="{{ route('assets.create', ['asset_stage' => 'rental_stock']) }}" class="inventory-quick-card" style="background:#eff6ff; border-color:#bfdbfe;">
            <div style="font-size:11px; color:#1d4ed8; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Add Stock</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Add Rental Asset</div>
            <div style="font-size:12px; color:#64748b; line-height:1.25;">Rental-ready physical unit.</div>
        </a>
        <a href="{{ route('assets.pending-verification') }}" class="inventory-quick-card" style="background:#fef3c7; border-color:#fde68a;">
            <div style="font-size:11px; color:#b45309; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Operations Queue</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Return Verification</div>
            <div style="font-size:12px; color:#64748b; line-height:1.25;">Returned units awaiting check.</div>
        </a>
        <a href="{{ route('assets.index') }}" class="inventory-quick-card">
            <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.08em;">Workspace</div>
            <div style="font-size:18px; font-weight:800; color:#0f172a;">Asset Register</div>
            <div style="font-size:12px; color:#64748b; line-height:1.25;">Serials, barcodes, status.</div>
        </a>
    </div>

    <div class="inventory-cards">
        @php
            $cards = [
                ['label' => 'Total Rental Assets', 'value' => $totalRentalAssets, 'tone' => 'blue', 'url' => route('assets.index', ['asset_stage' => 'rental_stock'])],
                ['label' => 'Rental Available', 'value' => $rentalAvailable, 'tone' => 'green', 'url' => route('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'available'])],
                ['label' => 'Rented Out', 'value' => $rentedOut, 'tone' => 'blue', 'url' => route('assets.index', ['asset_stage' => 'rental_stock'])],
                ['label' => 'Awaiting Verification', 'value' => $awaitingVerification, 'tone' => 'amber', 'url' => route('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'awaiting_verification'])],
                ['label' => 'Under Repair', 'value' => $underRepair, 'tone' => 'red', 'url' => route('assets.index', ['asset_stage' => 'rental_stock', 'asset_status' => 'maintenance'])],
                ['label' => 'Sale Units', 'value' => $saleUnits, 'tone' => 'grey', 'url' => route('assets.index', ['asset_stage' => 'new_stock'])],
                ['label' => 'Available to Sell', 'value' => $availableToSell, 'tone' => 'green', 'url' => route('assets.index', ['asset_stage' => 'new_stock', 'asset_status' => 'available_for_sale'])],
                ['label' => 'Low Stock / Risk', 'value' => $lowStockRows->count(), 'tone' => 'red', 'url' => route('inventory.dashboard', ['stock_view' => 'low_stock'])],
            ];
        @endphp
        @foreach($cards as $card)
            <a href="{{ $card['url'] }}" class="inventory-stat-card inventory-kpi-card tone-{{ $card['tone'] }}">
                <div class="inventory-kpi-label">{{ $card['label'] }}</div>
                <div class="inventory-kpi-value">{{ $metricDisplay($card['value']) }}</div>
            </a>
        @endforeach
    </div>

    <div class="inventory-decision-grid">
        <div class="inventory-decision-card" style="border-color:{{ $attentionCount > 0 ? '#fecaca' : '#bbf7d0' }};">
            <h3>Needs Attention</h3>
            <p>{{ $attentionCount > 0 ? 'Prioritize exceptions before new dispatch.' : 'No immediate stock exceptions detected.' }}</p>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <span class="inventory-signal-badge is-danger">Repair {{ $metricDisplay($underRepair) }}</span>
                <span class="inventory-signal-badge is-warning">Verification {{ $metricDisplay($awaitingVerification) }}</span>
                <span class="inventory-signal-badge is-danger">Low stock {{ $metricDisplay($lowStockRows->count()) }}</span>
            </div>
        </div>
        <div class="inventory-decision-card">
            <h3>Rental Readiness</h3>
            <div class="inventory-bar" aria-label="Rental utilization">
                <span class="green" style="width:{{ round(($rentalAvailable / $rentalDenominator) * 100, 2) }}%;"></span>
                <span class="blue" style="width:{{ round(($rentedOut / $rentalDenominator) * 100, 2) }}%;"></span>
                <span class="amber" style="width:{{ round(($awaitingVerification / $rentalDenominator) * 100, 2) }}%;"></span>
            </div>
            <div class="inventory-readiness-values">
                <span>Available <strong style="color:#15803d;">{{ $metricDisplay($rentalAvailable) }}</strong></span>
                <span>Rented <strong style="color:#1d4ed8;">{{ $metricDisplay($rentedOut) }}</strong></span>
                <span>Verify <strong style="color:#b45309;">{{ $metricDisplay($awaitingVerification) }}</strong></span>
            </div>
        </div>
        <div class="inventory-decision-card">
            <h3>Sales Readiness</h3>
            <div class="inventory-bar" aria-label="Sales stock readiness">
                <span class="green" style="width:{{ round(($availableToSell / $salesDenominator) * 100, 2) }}%;"></span>
                <span class="blue" style="width:{{ round(($soldUnits / $salesDenominator) * 100, 2) }}%;"></span>
                <span class="amber" style="width:{{ round(($saleReserved / $salesDenominator) * 100, 2) }}%;"></span>
            </div>
            <div class="inventory-readiness-values">
                <span>Available <strong style="color:#15803d;">{{ $metricDisplay($availableToSell) }}</strong></span>
                <span>Sold <strong style="color:#1d4ed8;">{{ $metricDisplay($soldUnits) }}</strong></span>
                <span>Reserved <strong style="color:#b45309;">{{ $metricDisplay($saleReserved) }}</strong></span>
            </div>
        </div>
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
                <a href="{{ route('assets.index', ['asset_status' => 'maintenance']) }}" class="inventory-filter-chip">Under Repair</a>
                <a href="{{ route('assets.index', ['asset_status' => 'available']) }}" class="inventory-filter-chip">Available</a>
                <a href="{{ $stockViewUrl('low_stock') }}" class="inventory-filter-chip">Attention First</a>
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
                            <th>Rental</th>
                            <th>Sale</th>
                            <th>Exceptions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($productStockRows as $productRow)
                            @php
                                $rowSignals = collect($productRow->stock_signals ?? []);
                                $hasDanger = $rowSignals->contains(fn ($signal) => ($signal['tone'] ?? null) === 'danger') || (int) $productRow->under_repair_count > 0;
                                $hasWarning = !$hasDanger && ($rowSignals->contains(fn ($signal) => ($signal['tone'] ?? null) === 'warning') || (int) $productRow->awaiting_verification_count > 0 || (int) $productRow->sale_reserved_count > 0);
                                $inactive = !$hasDanger && !$hasWarning && ((int) $productRow->effective_rental_assets_total_count + (int) $productRow->effective_sale_units_total_count === 0);
                                $stockClass = fn ($value, $tone) => 'inventory-stock-number ' . (((int) $value) === 0 ? 'is-zero' : 'is-' . $tone);
                                $stockText = fn ($value) => ((int) $value) === 0 ? '–' : number_format((int) $value);
                            @endphp
                            <tr class="{{ $hasDanger ? 'is-attention' : ($hasWarning ? 'is-warning' : ($inactive ? 'is-inactive' : '')) }}">
                                <td>
                                    <a href="{{ route('products.show', $productRow) }}" class="inventory-row-click">
                                        <div class="inventory-product-name">{{ $productRow->name }}</div>
                                        <div class="inventory-product-meta">
                                            {{ collect([$productRow->brand, $productRow->model_name])->filter()->join(' | ') ?: 'Brand / model not set' }}
                                        </div>
                                        <div style="display:flex; gap:4px; flex-wrap:wrap; margin-top:5px;">
                                            @if(filled($productRow->category))
                                                <span class="inventory-type-badge is-category">{{ $productRow->category }}</span>
                                            @endif
                                            @if($productRow->tracksRentalStock())
                                                <span class="inventory-type-badge is-rental">Rental</span>
                                            @endif
                                            @if($productRow->tracksSaleStock())
                                                <span class="inventory-type-badge is-sale">Sale</span>
                                            @endif
                                            @if($productRow->usesUntrackedStock())
                                                <span class="inventory-type-badge is-category">Untracked</span>
                                            @endif
                                        </div>
                                    </a>
                                </td>
                                <td>
                                    <div class="inventory-stat-group">
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock']) }}" class="inventory-stat-chip"><span>Assets</span><strong class="{{ ((int) $productRow->effective_rental_assets_total_count) === 0 ? 'is-zero' : '' }}">{{ $stockText($productRow->effective_rental_assets_total_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'available']) }}" class="inventory-stat-chip is-green"><span>Avail</span><strong>{{ $stockText($productRow->effective_rental_available_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock']) }}" class="inventory-stat-chip is-blue"><span>Rented</span><strong>{{ $stockText($productRow->rental_out_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'reserved']) }}" class="inventory-stat-chip is-amber"><span>Reserved</span><strong>{{ $stockText($productRow->reserved_rental_count ?? 0) }}</strong></a>
                                    </div>
                                </td>
                                <td>
                                    <div class="inventory-stat-group">
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock']) }}" class="inventory-stat-chip"><span>Units</span><strong>{{ $stockText($productRow->effective_sale_units_total_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'available_for_sale']) }}" class="inventory-stat-chip is-green"><span>Avail</span><strong>{{ $stockText($productRow->effective_sale_available_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'reserved_for_sale']) }}" class="inventory-stat-chip is-amber"><span>Reserved</span><strong>{{ $stockText($productRow->sale_reserved_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'new_stock', 'asset_status' => 'sold']) }}" class="inventory-stat-chip is-blue"><span>Sold</span><strong>{{ $stockText($productRow->sold_units_count) }}</strong></a>
                                    </div>
                                </td>
                                <td>
                                    <div class="inventory-stat-group">
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'awaiting_verification']) }}" class="inventory-stat-chip is-amber"><span>Verify</span><strong>{{ $stockText($productRow->awaiting_verification_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_stage' => 'rental_stock', 'asset_status' => 'maintenance']) }}" class="inventory-stat-chip is-red"><span>Repair</span><strong>{{ $stockText($productRow->under_repair_count) }}</strong></a>
                                        <a href="{{ $productAssetUrl($productRow, ['asset_status' => 'retired']) }}" class="inventory-stat-chip is-grey"><span>Retired</span><strong>{{ $stockText((int) $productRow->retired_rental_count + (int) $productRow->retired_sale_count) }}</strong></a>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                        @forelse($rowSignals as $signal)
                                            <span class="inventory-signal-badge {{ $signalClass($signal['tone'] ?? null) }}">{{ $signal['label'] }}</span>
                                        @empty
                                            <span class="inventory-signal-badge is-success">Healthy</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td>
                                    <div class="inventory-actions-cell">
                                        <a href="{{ route('products.show', $productRow) }}">View Product</a>
                                        <a href="{{ route('assets.index', ['product_id' => $productRow->id]) }}">View Assets</a>
                                        <a href="{{ route('inventory.dashboard', ['stock_view' => 'low_stock']) }}">Reconcile</a>
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
