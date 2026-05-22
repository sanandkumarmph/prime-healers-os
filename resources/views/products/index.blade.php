@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canCreateProducts = $currentUser?->canAccessModule('products', 'create') ?? false;
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
    $canDeleteProducts = $currentUser?->canAccessModule('products', 'delete') ?? false;
    $canReadAssets = $currentUser?->canAccessModule('assets', 'read') ?? false;
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $rupee = html_entity_decode('&#8377;');
    $queryFor = function (array $overrides = []) use ($search, $category, $typeFilter, $stockStatus, $brand, $sort, $direction) {
        return array_filter(array_merge([
            'search' => $search ?: null,
            'category' => $category ?: null,
            'type' => $typeFilter ?: null,
            'stock_status' => $stockStatus ?: null,
            'brand' => $brand ?: null,
            'sort' => $sort ?: null,
            'direction' => $direction ?: null,
        ], $overrides), fn ($value) => $value !== null && $value !== '');
    };
    $sortDirectionFor = function (string $field) use ($sort, $direction) {
        if ($sort === $field) {
            return $direction === 'asc' ? 'desc' : 'asc';
        }

        return $field === 'created_at' ? 'desc' : 'asc';
    };
    $sortUrl = function (string $field) use ($queryFor, $sortDirectionFor) {
        return route('products.index', $queryFor([
            'sort' => $field,
            'direction' => $sortDirectionFor($field),
            'page' => null,
        ]));
    };
    $sortIndicator = function (string $field) use ($sort, $direction) {
        if ($sort !== $field) {
            return '';
        }

        return $direction === 'asc' ? '↑' : '↓';
    };
    $defaultSort = 'created_at';
    $defaultDirection = 'desc';
    $activeFilterChips = collect([
        filled($search) ? 'Search: ' . $search : null,
        filled($category) ? 'Category: ' . $category : null,
        filled($typeFilter) ? 'Type: ' . match ($typeFilter) {
            'rentable' => 'Rentable',
            'sale_only' => 'Sale only',
            'both' => 'Both',
            'untracked' => 'Untracked',
            default => ucfirst(str_replace('_', ' ', $typeFilter)),
        } : null,
        filled($stockStatus) ? 'Stock: ' . ucfirst(str_replace('_', ' ', $stockStatus)) : null,
        filled($brand) ? 'Brand: ' . $brand : null,
        $sort !== $defaultSort ? 'Sort: ' . ucfirst(str_replace('_', ' ', $sort)) : null,
        $direction !== $defaultDirection ? 'Direction: ' . ucfirst($direction) : null,
    ])->filter()->values();
    $activeFilterCount = $activeFilterChips->count();
    $hasActiveFilters = filled($search)
        || filled($category)
        || filled($typeFilter)
        || filled($stockStatus)
        || filled($brand)
        || $sort !== $defaultSort
        || $direction !== $defaultDirection;
    $activeViewLabel = null;

    if (filled($typeFilter)) {
        $activeViewLabel = match ($typeFilter) {
            'rentable' => 'Rentable',
            'sale_only' => 'Sale only',
            'both' => 'Both sellable and rentable',
            'untracked' => 'Untracked',
            default => ucfirst(str_replace('_', ' ', $typeFilter)),
        };
    } elseif (filled($stockStatus)) {
        $activeViewLabel = match ($stockStatus) {
            'available_to_rent' => 'Rental available',
            'rented_out' => 'Rented out',
            'maintenance' => 'Maintenance',
            'out_of_stock' => 'Out of stock',
            default => ucfirst(str_replace('_', ' ', $stockStatus)),
        };
    } elseif (filled($category)) {
        $activeViewLabel = $category;
    } elseif (filled($brand)) {
        $activeViewLabel = $brand;
    } elseif (filled($search)) {
        $activeViewLabel = 'Search results';
    }
    $productDashboardCards = [
        ['label' => 'Product Master', 'value' => null, 'url' => route('products.index'), 'tone' => 'catalog', 'subtext' => 'Catalog and stock-mode view'],
        ['label' => 'Sellable', 'value' => null, 'url' => route('products.index', ['type' => 'sale_only']), 'tone' => 'success', 'subtext' => 'Sale-unit products'],
        ['label' => 'Rentable', 'value' => null, 'url' => route('products.index', ['type' => 'rentable']), 'tone' => 'info', 'subtext' => 'Asset-tracked products'],
        ['label' => 'Sale Units', 'value' => null, 'url' => route('products.index', ['sort' => 'sale_price']), 'tone' => 'warning', 'subtext' => 'Available to sell'],
        ['label' => 'Rental Assets', 'value' => null, 'url' => route('assets.index', ['asset_stage' => 'rental_stock']), 'tone' => 'accent', 'subtext' => 'Tracked units'],
        ['label' => 'Rental Available', 'value' => null, 'url' => route('products.index', ['stock_status' => 'available_to_rent']), 'tone' => 'neutral', 'subtext' => 'Ready for dispatch'],
    ];
@endphp

@section('content')
    <style>
        .product-page { display:grid; gap:12px; }
        .product-hero { margin-bottom:0 !important; }
        .product-shell {
            background:#fff;
            border:1px solid var(--ph-color-border);
            border-radius:18px;
            overflow:hidden;
            box-shadow:var(--ph-shadow-soft);
        }
        .product-shell-head {
            padding:14px 16px;
            border-bottom:1px solid var(--ph-color-border);
            display:flex;
            justify-content:space-between;
            gap:10px;
            align-items:center;
            flex-wrap:wrap;
        }
        .product-shell-head h2 {
            margin:0;
            font-size:18px;
            letter-spacing:-0.02em;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
        }
        .product-shell-head p {
            margin:5px 0 0;
            color:var(--ph-color-text-soft);
            font-size:11.5px;
        }
        .product-pills,
        .product-actions,
        .product-inline-badges,
        .product-sort-stack,
        .product-filter-actions {
            display:flex;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
        }
        .product-pill {
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            border:1px solid var(--ph-color-border);
            background:var(--ph-color-surface-soft);
            color:var(--ph-color-text);
        }
        .product-pill.is-info {
            background:var(--ph-color-info-soft);
            border-color:rgba(23,119,189,.18);
            color:var(--ph-color-primary);
        }
        .product-dashboard {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
            gap:10px;
        }
        .product-kpi {
            display:grid;
            gap:6px;
            padding:12px;
            border-radius:16px;
            border:1px solid var(--ph-color-border);
            text-decoration:none;
            color:inherit;
            background:#fff;
            box-shadow:var(--ph-shadow-soft);
            transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .product-kpi:hover {
            transform:translateY(-1px);
            box-shadow:var(--ph-shadow-card);
        }
        .product-kpi.product-tone-success { background:var(--ph-color-success-soft); border-color:rgba(14,159,75,.18); }
        .product-kpi.product-tone-info { background:var(--ph-color-info-soft); border-color:rgba(23,119,189,.18); }
        .product-kpi.product-tone-warning { background:var(--ph-color-warning-soft); border-color:rgba(183,121,31,.18); }
        .product-kpi.product-tone-accent { background:#f5f3ff; border-color:rgba(109,40,217,.16); }
        .product-kpi.product-tone-neutral { background:var(--ph-color-surface-soft); }
        .product-kpi-label {
            font-size:11px;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
            color:var(--ph-color-text-soft);
            font-family:var(--ph-font-heading);
        }
        .product-kpi-value {
            font-size:22px;
            line-height:1;
            font-weight:800;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
        }
        .product-kpi-copy {
            color:var(--ph-color-text-soft);
            font-size:11px;
        }
        .product-kpi.product-tone-success .product-kpi-label,
        .product-kpi.product-tone-success .product-kpi-value,
        .product-kpi.product-tone-success .product-kpi-copy { color:var(--ph-color-success); }
        .product-kpi.product-tone-info .product-kpi-label,
        .product-kpi.product-tone-info .product-kpi-value,
        .product-kpi.product-tone-info .product-kpi-copy { color:var(--ph-color-primary); }
        .product-kpi.product-tone-warning .product-kpi-label,
        .product-kpi.product-tone-warning .product-kpi-value,
        .product-kpi.product-tone-warning .product-kpi-copy { color:var(--ph-color-warning); }
        .product-kpi.product-tone-accent .product-kpi-label,
        .product-kpi.product-tone-accent .product-kpi-value,
        .product-kpi.product-tone-accent .product-kpi-copy { color:#6d28d9; }
        .product-filter-card {
            padding:0 16px 14px;
        }
        .product-primary-search {
            padding:14px 16px;
            border-bottom:1px solid var(--ph-color-border);
            background:linear-gradient(180deg, rgba(248,250,252,.95) 0%, rgba(255,255,255,1) 100%);
            display:grid;
            gap:10px;
        }
        .product-primary-search-form {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto auto;
            gap:8px;
            align-items:end;
        }
        .product-primary-search-field {
            display:grid;
            gap:5px;
            min-width:0;
        }
        .product-primary-search-field label {
            font-size:10px;
            font-weight:700;
            color:var(--ph-color-text-soft);
            text-transform:uppercase;
            letter-spacing:.04em;
            font-family:var(--ph-font-heading);
        }
        .product-primary-search-field input {
            width:100%;
            min-width:0;
            padding:10px 12px;
            border-radius:12px;
            border:1px solid var(--ph-color-border-strong);
            background:#fff;
            color:var(--ph-color-text);
            font-size:13px;
        }
        .product-filter-status {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            flex-wrap:wrap;
        }
        .product-filter-status-copy {
            color:var(--ph-color-text-soft);
            font-size:11.5px;
        }
        .product-filter-state {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            flex-wrap:wrap;
        }
        .product-filter-chip-row {
            display:flex;
            flex-wrap:wrap;
            gap:6px;
        }
        .product-filter-chip {
            display:inline-flex;
            align-items:center;
            gap:6px;
            min-height:30px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid var(--ph-color-border);
            background:#fff;
            color:var(--ph-color-text);
            font-size:12px;
            font-weight:700;
        }
        .product-filter-summary {
            cursor:pointer;
            list-style:none;
            padding:16px 0;
            font-weight:800;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        }
        .product-filter-summary::-webkit-details-marker { display:none; }
        .product-filter-summary span {
            color:var(--ph-color-text-soft);
            font-size:12px;
            font-weight:700;
        }
        .product-filter-form {
            display:grid;
            gap:12px;
        }
        .product-filter-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
            gap:10px;
            align-items:end;
        }
        .product-filter-field {
            display:grid;
            gap:6px;
        }
        .product-filter-field label {
            font-size:11px;
            font-weight:700;
            color:var(--ph-color-text-soft);
            text-transform:uppercase;
            letter-spacing:.04em;
            font-family:var(--ph-font-heading);
        }
        .product-filter-field input,
        .product-filter-field select {
            width:100%;
            padding:10px 12px;
            border-radius:12px;
            border:1px solid var(--ph-color-border-strong);
            background:#fff;
            color:var(--ph-color-text);
            font-size:14px;
        }
        .product-selected-count {
            color:var(--ph-color-text);
            font-size:13px;
            font-weight:700;
            font-family:var(--ph-font-heading);
        }
        .product-table-wrap { overflow:auto; }
        .product-table {
            width:100%;
            border-collapse:collapse;
            min-width:1320px;
        }
        .product-table-head {
            background:var(--ph-color-surface-soft);
        }
        .product-table-head th {
            text-align:left;
            padding:14px 18px;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.08em;
            color:var(--ph-color-text-soft);
            font-family:var(--ph-font-heading);
        }
        .product-sort-link {
            display:inline-flex;
            align-items:center;
            gap:6px;
            color:inherit;
            text-decoration:none;
        }
        .product-sort-link.is-active { color:var(--ph-color-primary); }
        .product-sort-indicator { font-size:12px; color:var(--ph-color-primary); }
        .product-row {
            border-top:1px solid var(--ph-color-border);
            vertical-align:top;
        }
        .product-cell { padding:18px; }
        .product-bulk-col {
            width:44px;
            text-align:center !important;
        }
        .product-serial-col {
            width:60px;
            text-align:center !important;
            color:var(--ph-color-text-soft);
            font-weight:700;
        }
        .product-bulk-col input[type="checkbox"] {
            width:16px;
            height:16px;
            cursor:pointer;
        }
        .product-name {
            font-size:16px;
            font-weight:800;
            color:var(--ph-color-text);
            text-decoration:none;
            font-family:var(--ph-font-heading);
        }
        .product-subtext,
        .product-meta-list,
        .product-copy,
        .product-price-list {
            color:var(--ph-color-text-soft);
            font-size:13px;
        }
        .product-meta-list,
        .product-price-list,
        .product-stack,
        .product-signal-stack { display:grid; gap:8px; }
        .product-price-list strong,
        .product-meta-list strong { color:var(--ph-color-text); }
        .product-badge {
            display:inline-flex;
            width:max-content;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            border:1px solid transparent;
        }
        .product-badge.is-neutral { background:var(--ph-color-surface-soft); color:var(--ph-color-text-soft); border-color:var(--ph-color-border); }
        .product-badge.is-success { background:var(--ph-color-success-soft); color:var(--ph-color-success); border-color:rgba(14,159,75,.18); }
        .product-badge.is-info { background:var(--ph-color-info-soft); color:var(--ph-color-primary); border-color:rgba(23,119,189,.18); }
        .product-badge.is-warning { background:var(--ph-color-warning-soft); color:var(--ph-color-warning); border-color:rgba(183,121,31,.18); }
        .product-badge.is-accent { background:#f5f3ff; color:#6d28d9; border-color:rgba(109,40,217,.16); }
        .product-badge.is-danger { background:var(--ph-color-danger-soft); color:var(--ph-color-danger); border-color:rgba(179,13,35,.18); }
        .product-signal-card {
            padding:12px 14px;
            border-radius:16px;
            border:1px solid var(--ph-color-border);
            background:#fff;
        }
        .product-signal-card .product-signal-label {
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            color:var(--ph-color-text-soft);
            font-family:var(--ph-font-heading);
        }
        .product-signal-card .product-signal-value {
            margin-top:6px;
            font-size:24px;
            font-weight:800;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
        }
        .product-signal-card.is-success { background:var(--ph-color-success-soft); border-color:rgba(14,159,75,.18); }
        .product-signal-card.is-success .product-signal-label,
        .product-signal-card.is-success .product-signal-value { color:var(--ph-color-success); }
        .product-signal-card.is-info { background:var(--ph-color-info-soft); border-color:rgba(23,119,189,.18); }
        .product-signal-card.is-info .product-signal-label,
        .product-signal-card.is-info .product-signal-value { color:var(--ph-color-primary); }
        .product-signal-card.is-warning { background:var(--ph-color-warning-soft); border-color:rgba(183,121,31,.18); }
        .product-signal-card.is-warning .product-signal-label,
        .product-signal-card.is-warning .product-signal-value { color:var(--ph-color-warning); }
        .product-signal-card.is-neutral { background:var(--ph-color-surface-soft); }
        .product-action-menu { position:relative; display:inline-block; }
        .product-action-menu summary {
            list-style:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:38px;
            height:38px;
            border-radius:12px;
            border:1px solid var(--ph-color-border);
            background:#fff;
            color:var(--ph-color-text);
            cursor:pointer;
            font-weight:900;
            box-shadow:var(--ph-shadow-soft);
        }
        .product-action-menu summary::-webkit-details-marker { display:none; }
        .product-action-menu[open] summary {
            background:var(--ph-color-info-soft);
            color:var(--ph-color-primary);
            border-color:rgba(23,119,189,.18);
        }
        .product-action-panel {
            position:absolute;
            right:48px;
            top:0;
            z-index:40;
            min-width:188px;
            display:grid;
            gap:6px;
            padding:8px;
            border:1px solid var(--ph-color-border);
            border-radius:14px;
            background:#fff;
            box-shadow:var(--ph-shadow-float);
        }
        .product-action-link,
        .product-action-panel button {
            display:flex;
            align-items:center;
            justify-content:flex-start;
            min-height:36px;
            padding:8px 10px;
            border-radius:10px;
            border:1px solid var(--ph-color-border);
            background:#fff;
            color:var(--ph-color-text);
            text-decoration:none;
            font-size:12px;
            font-weight:700;
            cursor:pointer;
        }
        .product-action-panel .danger {
            background:var(--ph-color-danger-soft);
            border-color:rgba(179,13,35,.18);
            color:var(--ph-color-danger);
        }
        .product-empty {
            padding:28px 18px;
            color:var(--ph-color-text-soft);
            text-align:center;
        }
        .product-mobile-chip-row { display:none; }
        .product-desktop-links { display:none !important; }
        @media (max-width: 767px) {
            .product-desktop-hero,
            .product-desktop-dashboard { display:none !important; }
            .product-primary-search {
                padding:16px;
            }
            .product-primary-search-form {
                grid-template-columns:1fr;
            }
            .product-primary-search-form .ph-btn,
            .product-primary-search-form .ph-btn-soft {
                width:100%;
                justify-content:center;
            }
            .product-filter-state {
                align-items:flex-start;
            }
            .product-filter-card { padding:0 16px 16px; }
            .product-filter-grid { grid-template-columns:1fr; }
            .product-filter-field--search { grid-column:auto !important; }
            .product-table-wrap { overflow:visible !important; }
            .product-table { min-width:0 !important; display:block; border-collapse:separate; border-spacing:0 10px; }
            .product-table thead { display:none; }
            .product-table,
            .product-table tbody,
            .product-table tr,
            .product-table td { display:block; width:100%; }
            .product-table tr {
                margin-bottom:10px;
                border:1px solid var(--ph-color-border);
                border-radius:16px;
                background:#fff;
                box-shadow:var(--ph-shadow-soft);
                overflow:hidden;
            }
            .product-table td {
                display:grid;
                grid-template-columns:108px minmax(0, 1fr);
                gap:10px;
                padding:10px 12px !important;
                border-top:0 !important;
                border-bottom:1px solid var(--ph-color-border);
                text-align:left !important;
                background:#fff;
            }
            .product-table td:last-child { border-bottom:none; }
            .product-table td::before {
                content:attr(data-label);
                color:var(--ph-color-text-soft);
                font-size:10px;
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:.06em;
            }
            .product-table td.product-bulk-col {
                display:flex;
                align-items:center;
                justify-content:space-between;
            }
            .product-table td.product-serial-col {
                display:grid;
                grid-template-columns:108px minmax(0, 1fr);
                text-align:left !important;
            }
            .product-action-panel { position:static; min-width:0; margin-top:8px; box-shadow:none; }
        }
    </style>

    <div class="rn-list-page product-page">
        <div class="product-desktop-hero product-hero rx-page-header">
            <div>
                <div class="rx-eyebrow">Product Master</div>
                <h1 class="rx-page-title">Product Master</h1>
                <p class="rx-page-subtitle">Use Product Master for catalog and pricing. Add quantity through sale units and rental assets, then track physical units in Asset Register.</p>
            </div>

            @if($canCreateProducts)
                <a href="{{ route('products.create') }}" class="rx-btn-soft">+ Add Product</a>
            @endif
        </div>

        @if(session('success'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:var(--ph-color-success-soft); border:1px solid rgba(14,159,75,.18); color:var(--ph-color-success);">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:var(--ph-color-danger-soft); border:1px solid rgba(179,13,35,.18); color:var(--ph-color-danger);">
                {{ session('error') }}
            </div>
        @endif

        @if(($duplicateProductNameGroups ?? collect())->isNotEmpty())
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:var(--ph-color-warning-soft); border:1px solid rgba(183,121,31,.18); color:var(--ph-color-warning);">
                <strong>{{ $duplicateProductNameGroups->count() }} duplicate product-name {{ $duplicateProductNameGroups->count() === 1 ? 'group is' : 'groups are' }} present in Product Master.</strong>
                Products with the same name should be differentiated by Brand and Model so imports and asset links stay unambiguous.
            </div>
        @endif

        @php
            $productDashboardCards[0]['value'] = $catalogTotals['products'] ?? 0;
            $productDashboardCards[1]['value'] = $catalogTotals['sellable'] ?? 0;
            $productDashboardCards[2]['value'] = $catalogTotals['rentable'] ?? 0;
            $productDashboardCards[3]['value'] = $catalogTotals['sale_stock'] ?? 0;
            $productDashboardCards[4]['value'] = $catalogTotals['rental_assets'] ?? 0;
            $productDashboardCards[5]['value'] = $catalogTotals['rental_available'] ?? 0;
        @endphp

        <div class="product-desktop-dashboard product-dashboard">
            @foreach($productDashboardCards as $card)
                <a href="{{ $card['url'] }}" class="product-kpi product-tone-{{ $card['tone'] }}">
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
                    <div class="product-kpi-label">{{ $card['label'] }}</div>
                    <div class="product-kpi-value">{{ $card['value'] }}</div>
                    <div class="product-kpi-copy">{{ $card['subtext'] }}</div>
                </a>
            @endforeach
        </div>

        <div class="product-mobile-chip-row product-desktop-links" aria-label="Product quick links">
            <a href="{{ route('products.index') }}" class="product-mobile-chip">Product Master</a>
            <a href="{{ route('assets.index', ['asset_stage' => 'rental_stock']) }}" class="product-mobile-chip">Rental Assets</a>
            <a href="{{ route('products.index', ['stock_status' => 'available_to_rent']) }}" class="product-mobile-chip">Available</a>
        </div>

        <div class="product-shell">
            <div class="product-shell-head">
                <div>
                    <h2>Healthcare Equipment Catalog</h2>
                    <p>One operations table for catalog setup, stock mode, equipment availability, and unit readiness.</p>
                </div>
            <div class="product-pills">
                <a href="{{ route('products.export.csv', $queryFor()) }}" class="ph-btn-secondary">Export CSV</a>
                <span class="product-pill">Product Master = catalog</span>
                <span class="product-pill is-info">Rental assets = physical rental units</span>
            </div>
            </div>

            <div class="product-primary-search">
                <form method="GET" action="{{ route('products.index') }}" class="product-primary-search-form">
                    <input type="hidden" name="category" value="{{ $category }}">
                    <input type="hidden" name="type" value="{{ $typeFilter }}">
                    <input type="hidden" name="stock_status" value="{{ $stockStatus }}">
                    <input type="hidden" name="brand" value="{{ $brand }}">
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                    <div class="product-primary-search-field">
                        <label for="product-primary-search">Search products</label>
                        <input
                            id="product-primary-search"
                            type="search"
                            name="search"
                            value="{{ $search }}"
                            placeholder="Search product name, brand, model, SKU, or product code">
                    </div>
                    <button type="submit" class="ph-btn">Search</button>
                    <a href="{{ route('products.index') }}" class="ph-btn-soft">Clear Filters</a>
                </form>
                <div class="product-filter-status">
                    <div class="product-filter-status-copy">Search is always available. Use Filters &amp; Sorting for category, stock, brand, and sort controls.</div>
                    @if($hasActiveFilters)
                        <span class="product-pill is-info">Filters Active · {{ $activeFilterCount }}</span>
                    @endif
                </div>
                @if($hasActiveFilters)
                    <div class="product-filter-state">
                        <div class="product-filter-status-copy">
                            Showing: <strong style="color:var(--ph-color-text);">{{ $activeViewLabel ?: 'Filtered results' }}</strong>
                        </div>
                        <a href="{{ route('products.index') }}" class="ph-btn-soft">Clear Filters</a>
                    </div>
                    <div class="product-filter-chip-row">
                        @foreach($activeFilterChips as $chip)
                            <span class="product-filter-chip">{{ $chip }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <details class="product-filter-card" data-filter-panel data-filter-panel-key="products-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" @if($hasActiveFilters) open @endif>
                <summary class="product-filter-summary">
                    <span>Search &amp; Filters</span>
                    <span>{{ $hasActiveFilters ? 'Refine current results' : 'Expand advanced filters' }}</span>
                </summary>
                <form method="GET" action="{{ route('products.index') }}" class="product-filter-form">
                    <input type="hidden" name="search" value="{{ $search }}">
                    <div class="product-filter-grid">
                        <div class="product-filter-field">
                            <label for="product-category-filter">Category</label>
                            <select id="product-category-filter" name="category">
                                <option value="">All categories</option>
                                @foreach($categoryOptions as $categoryOption)
                                    <option value="{{ $categoryOption }}" @selected($category === $categoryOption)>{{ $categoryOption }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="product-filter-field">
                            <label for="product-type-filter">Type</label>
                            <select id="product-type-filter" name="type">
                                <option value="">All types</option>
                                <option value="rentable" @selected($typeFilter === 'rentable')>Rentable</option>
                                <option value="sale_only" @selected($typeFilter === 'sale_only')>Sale only</option>
                                <option value="both" @selected($typeFilter === 'both')>Both</option>
                                <option value="untracked" @selected($typeFilter === 'untracked')>Untracked</option>
                            </select>
                        </div>
                        <div class="product-filter-field">
                            <label for="product-stock-status-filter">Stock Status</label>
                            <select id="product-stock-status-filter" name="stock_status">
                                <option value="">All stock states</option>
                                <option value="available_to_rent" @selected($stockStatus === 'available_to_rent')>Available to rent</option>
                                <option value="rented_out" @selected($stockStatus === 'rented_out')>Rented out</option>
                                <option value="maintenance" @selected($stockStatus === 'maintenance')>Maintenance</option>
                                <option value="out_of_stock" @selected($stockStatus === 'out_of_stock')>Out of stock</option>
                            </select>
                        </div>
                        @if($brandOptions->isNotEmpty())
                            <div class="product-filter-field">
                                <label for="product-brand-filter">Brand</label>
                                <select id="product-brand-filter" name="brand">
                                    <option value="">All brands</option>
                                    @foreach($brandOptions as $brandOption)
                                        <option value="{{ $brandOption }}" @selected($brand === $brandOption)>{{ $brandOption }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="product-filter-field">
                            <label for="product-sort-select">Sort</label>
                            <select id="product-sort-select" name="sort">
                                <option value="created_at" @selected($sort === 'created_at')>Created date</option>
                                <option value="name" @selected($sort === 'name')>Product name</option>
                                <option value="category" @selected($sort === 'category')>Category</option>
                                <option value="brand" @selected($sort === 'brand')>Brand</option>
                                <option value="model" @selected($sort === 'model')>Model</option>
                                <option value="available_units" @selected($sort === 'available_units')>Available rental units</option>
                                <option value="rented_units" @selected($sort === 'rented_units')>Rented units</option>
                                <option value="sale_price" @selected($sort === 'sale_price')>Sale price</option>
                                <option value="daily_rent" @selected($sort === 'daily_rent')>Daily rental price</option>
                            </select>
                        </div>
                        <div class="product-filter-field">
                            <label for="product-direction-select">Direction</label>
                            <select id="product-direction-select" name="direction">
                                <option value="desc" @selected($direction === 'desc')>Descending</option>
                                <option value="asc" @selected($direction === 'asc')>Ascending</option>
                            </select>
                        </div>
                    </div>
                    <div class="product-filter-actions">
                        <div class="product-selected-count"><span id="productSelectedCount">0</span> selected</div>
                        <div class="product-actions">
                            <a href="{{ route('products.export.csv', $queryFor()) }}" class="ph-btn-secondary">Export CSV</a>
                            <button type="submit" class="ph-btn">Apply Filters</button>
                            <a href="{{ route('products.index') }}" class="ph-btn-soft" data-filter-clear="products-index">Clear Filters</a>
                        </div>
                    </div>
                </form>
            </details>

            <div class="product-table-wrap">
                <table class="product-table">
                    <thead class="product-table-head">
                        <tr>
                            <th class="product-serial-col">#</th>
                            <th class="product-bulk-col">
                                <input type="checkbox" id="productSelectAll" aria-label="Select all visible products">
                            </th>
                            <th>
                                <a href="{{ $sortUrl('name') }}" class="product-sort-link {{ $sort === 'name' ? 'is-active' : '' }}">
                                    <span>Product</span>
                                    @if($sortIndicator('name') !== '')
                                        <span class="product-sort-indicator">{{ $sortIndicator('name') }}</span>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <span class="product-sort-stack">
                                    <a href="{{ $sortUrl('category') }}" class="product-sort-link {{ $sort === 'category' ? 'is-active' : '' }}">
                                        <span>Category</span>
                                        @if($sortIndicator('category') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('category') }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ $sortUrl('brand') }}" class="product-sort-link {{ $sort === 'brand' ? 'is-active' : '' }}">
                                        <span>Brand</span>
                                        @if($sortIndicator('brand') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('brand') }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ $sortUrl('model') }}" class="product-sort-link {{ $sort === 'model' ? 'is-active' : '' }}">
                                        <span>Model</span>
                                        @if($sortIndicator('model') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('model') }}</span>
                                        @endif
                                    </a>
                                </span>
                            </th>
                            <th>Type</th>
                            <th>
                                <span class="product-sort-stack">
                                    <a href="{{ $sortUrl('available_units') }}" class="product-sort-link {{ $sort === 'available_units' ? 'is-active' : '' }}">
                                        <span>Available</span>
                                        @if($sortIndicator('available_units') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('available_units') }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ $sortUrl('rented_units') }}" class="product-sort-link {{ $sort === 'rented_units' ? 'is-active' : '' }}">
                                        <span>Rented</span>
                                        @if($sortIndicator('rented_units') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('rented_units') }}</span>
                                        @endif
                                    </a>
                                </span>
                            </th>
                            <th>Stock Signals</th>
                            <th>
                                <span class="product-sort-stack">
                                    <a href="{{ $sortUrl('sale_price') }}" class="product-sort-link {{ $sort === 'sale_price' ? 'is-active' : '' }}">
                                        <span>Sale</span>
                                        @if($sortIndicator('sale_price') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('sale_price') }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ $sortUrl('daily_rent') }}" class="product-sort-link {{ $sort === 'daily_rent' ? 'is-active' : '' }}">
                                        <span>Daily Rent</span>
                                        @if($sortIndicator('daily_rent') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('daily_rent') }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ $sortUrl('created_at') }}" class="product-sort-link {{ $sort === 'created_at' ? 'is-active' : '' }}">
                                        <span>Created</span>
                                        @if($sortIndicator('created_at') !== '')
                                            <span class="product-sort-indicator">{{ $sortIndicator('created_at') }}</span>
                                        @endif
                                    </a>
                                </span>
                            </th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
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
                                $stockModeTone = match ($product->stock_mode) {
                                    \App\Models\Product::STOCK_MODE_TRACKED_SALE => 'warning',
                                    \App\Models\Product::STOCK_MODE_TRACKED_RENTAL => 'info',
                                    \App\Models\Product::STOCK_MODE_TRACKED_BOTH => 'accent',
                                    default => 'neutral',
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
                                $rowNumber = method_exists($products, 'firstItem') && $products->firstItem()
                                    ? $products->firstItem() + $loop->index
                                    : $loop->iteration;
                            @endphp
                            <tr class="product-row">
                                <td class="product-cell product-serial-col" data-label="No.">{{ $rowNumber }}</td>
                                <td class="product-cell product-bulk-col" data-label="Select">
                                    <input type="checkbox" class="product-row-check" value="{{ $product->id }}" aria-label="Select {{ $product->name }}">
                                </td>
                                <td class="product-cell" data-label="Product">
                                    <div class="product-stack">
                                        <a href="{{ route('products.show', $product) }}" class="product-name">{{ $product->name }}</a>
                                        <div class="product-subtext">
                                            {{ $brandModel !== '' ? $brandModel : ($product->product_code ?: ($product->sku ?: 'No code assigned')) }}
                                        </div>
                                        @if(filled($product->category))
                                            <span class="product-badge is-neutral">{{ $product->category }}</span>
                                        @endif
                                        @if($lowStock)
                                            <span class="product-badge is-warning">Low sale units</span>
                                        @endif
                                        @if($legacyMixed)
                                            <span class="product-badge is-warning">Legacy mixed inventory</span>
                                        @endif
                                        @if($hasDuplicateName)
                                            <span class="product-badge is-danger">Duplicate product name</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Master">
                                    <div class="product-meta-list">
                                        <div><strong>Category:</strong> {{ $product->category ?: 'N/A' }}</div>
                                        <div><strong>Brand:</strong> {{ $product->brand ?: 'N/A' }}</div>
                                        <div><strong>Model:</strong> {{ $product->model_name ?: 'N/A' }}</div>
                                        <div><strong>SKU:</strong> {{ $product->sku ?: 'N/A' }}</div>
                                        <div><strong>Code:</strong> {{ $product->product_code ?: 'N/A' }}</div>
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Type">
                                    <div class="product-inline-badges">
                                        @if($canSell)
                                            <span class="product-badge is-success">Sellable</span>
                                        @endif
                                        @if($canRent)
                                            <span class="product-badge is-info">Rentable</span>
                                        @endif
                                        <span class="product-badge is-{{ $stockModeTone }}">{{ $stockModeLabel }}</span>
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Inventory">
                                    @if($primaryType === \App\Models\Product::TYPE_SELLABLE)
                                        <div class="product-signal-stack">
                                            <div class="product-signal-card is-warning">
                                                <div class="product-signal-label">Available to Sell</div>
                                                <div class="product-signal-value">{{ $saleStock }}</div>
                                            </div>
                                            <div class="product-copy">Active available sale units.</div>
                                        </div>
                                    @else
                                        <div class="product-signal-stack">
                                            <div class="product-signal-card is-success">
                                                <div class="product-signal-label">Available to Rent</div>
                                                <div class="product-signal-value">{{ $availableAssets }}</div>
                                            </div>
                                            <div class="product-copy">Rental-ready units for dispatch.</div>
                                        </div>
                                    @endif
                                </td>
                                <td class="product-cell" data-label="Stock Signals">
                                    @if($primaryType === \App\Models\Product::TYPE_SELLABLE)
                                        <div class="product-signal-stack">
                                            @if($hasRentalAssets)
                                                <div class="product-signal-card is-info">
                                                    <div class="product-signal-label">Converted to Rental</div>
                                                    <div class="product-signal-value">{{ $assetsCount }}</div>
                                                </div>
                                                <div class="product-copy">{{ $availableAssets }} available to rent across {{ max($rentalWarehouses->count(), 1) }} warehouse{{ $rentalWarehouses->count() === 1 ? '' : 's' }}.</div>
                                            @else
                                                <div class="product-signal-card is-neutral">
                                                    <div class="product-signal-label">Rental Assets</div>
                                                    <div class="product-signal-value">0</div>
                                                </div>
                                                <div class="product-copy">No rental assets linked.</div>
                                            @endif
                                        </div>
                                    @else
                                        <div class="product-signal-stack">
                                            <div class="product-signal-card is-info">
                                                <div class="product-signal-label">Rented Out</div>
                                                <div class="product-signal-value">{{ $rentedAssets }}</div>
                                            </div>
                                            <div class="product-copy">Maintenance {{ $maintenanceAssets }} | Warehouse {{ $rentalWarehouses->take(2)->implode(', ') ?: 'Not assigned' }}{{ $rentalWarehouses->count() > 2 ? ' +' . ($rentalWarehouses->count() - 2) : '' }}</div>
                                            @if($hasSaleStock)
                                                <div class="product-copy" style="color:var(--ph-color-warning);">Also in sale units: {{ $saleStock }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="product-cell" data-label="Pricing">
                                    <div class="product-price-list">
                                        <div><strong>Sale:</strong> {{ $product->sale_price !== null ? $rupee . ' ' . number_format($product->sale_price, 2) : 'N/A' }}</div>
                                        <div><strong>Per Day:</strong> {{ $primaryType === \App\Models\Product::TYPE_RENTABLE ? $rupee . ' ' . number_format((float) $product->price_per_day, 2) : 'N/A' }}</div>
                                        <div><strong>15 Days:</strong> {{ $product->rental_price_15_days !== null ? $rupee . ' ' . number_format($product->rental_price_15_days, 2) : 'N/A' }}</div>
                                        <div><strong>30 Days:</strong> {{ $product->rental_price_30_days !== null ? $rupee . ' ' . number_format($product->rental_price_30_days, 2) : 'N/A' }}</div>
                                        <div><strong>3 Months:</strong> {{ $product->rental_price_3_months !== null ? $rupee . ' ' . number_format($product->rental_price_3_months, 2) : 'N/A' }}</div>
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Actions" style="text-align:right;">
                                    <div class="product-actions">
                                        <a href="{{ route('products.show', $product) }}" class="ph-btn">View</a>
                                        @if($canCreateAssets)
                                            <a href="{{ $addStockUrl }}" class="ph-btn-secondary">{{ $product->product_type === \App\Models\Product::TYPE_SELLABLE ? 'Add Sale Unit' : 'Add Rental Asset' }}</a>
                                        @endif
                                        @if($canReadAssets)
                                            <a href="{{ $assetRegisterUrl }}" class="ph-btn-soft">Asset Register</a>
                                        @endif
                                        @if($canUpdateProducts)
                                            <a href="{{ $convertUrl }}" class="ph-btn-secondary">Convert</a>
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
                                <td colspan="9" class="product-empty">
                                    @if($hasActiveFilters)
                                        <div>No products found for the selected filters.</div>
                                        <div style="margin-top:12px;">
                                            <a href="{{ route('products.index') }}" class="ph-btn-soft">Clear Filters</a>
                                        </div>
                                    @else
                                        No products match this view yet. Add a product or widen the filters to continue.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="padding:18px 22px 22px; border-top:1px solid var(--ph-color-border); background:var(--ph-color-surface-soft);">
                {{ $products->links('vendor.pagination.prime-healers', [
                    'summaryLabel' => 'results',
                    'ariaLabel' => 'Product Master pagination',
                ]) }}
            </div>
        </div>
    </div>

    @if($canCreateProducts)
        @include('partials.mobile-fab', ['href' => route('products.create'), 'label' => 'Add Product'])
    @endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const selectAll = document.getElementById('productSelectAll');
        const rowChecks = Array.from(document.querySelectorAll('.product-row-check'));
        const countEl = document.getElementById('productSelectedCount');

        const updateSelectedCount = () => {
            const selected = rowChecks.filter((checkbox) => checkbox.checked).length;

            if (countEl) {
                countEl.textContent = selected;
            }

            if (selectAll) {
                selectAll.checked = rowChecks.length > 0 && selected === rowChecks.length;
                selectAll.indeterminate = selected > 0 && selected < rowChecks.length;
            }
        };

        selectAll?.addEventListener('change', () => {
            rowChecks.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });

            updateSelectedCount();
        });

        rowChecks.forEach((checkbox) => checkbox.addEventListener('change', updateSelectedCount));
        updateSelectedCount();
    });
</script>
@endpush
