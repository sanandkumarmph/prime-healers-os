@extends('layouts.app')

@php
    $currentUser = auth()->user();
    $canCreateProducts = $currentUser?->canAccessModule('products', 'create') ?? false;
    $canUpdateProducts = $currentUser?->canAccessModule('products', 'update') ?? false;
    $canDeleteProducts = $currentUser?->canAccessModule('products', 'delete') ?? false;
    $canReadAssets = $currentUser?->canAccessModule('assets', 'read') ?? false;
    $canCreateAssets = $currentUser?->canAccessModule('assets', 'create') ?? false;
    $rupee = html_entity_decode('&#8377;');
    $queryFor = function (array $overrides = []) use ($search, $category, $typeFilter, $stockStatus, $brand, $sort, $direction, $perPage) {
        return array_filter(array_merge([
            'search' => $search ?: null,
            'category' => $category ?: null,
            'type' => $typeFilter ?: null,
            'stock_status' => $stockStatus ?: null,
            'brand' => $brand ?: null,
            'sort' => $sort ?: null,
            'direction' => $direction ?: null,
            'per_page' => ((int) $perPage) !== 12 ? $perPage : null,
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

        return $direction === 'asc' ? 'up' : 'down';
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
        ((int) $perPage) !== 12 ? 'Rows: ' . $perPage : null,
    ])->filter()->values();
    $activeFilterCount = $activeFilterChips->count();
    $hasActiveFilters = filled($search)
        || filled($category)
        || filled($typeFilter)
        || filled($stockStatus)
        || filled($brand)
        || $sort !== $defaultSort
        || $direction !== $defaultDirection
        || ((int) $perPage) !== 12;
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
    $metricDisplay = fn ($value) => ((int) $value) === 0 ? '-' : number_format((int) $value);
    $productDashboardCards = [
        ['label' => 'Products', 'value' => null, 'url' => route('products.index'), 'tone' => 'catalog', 'subtext' => 'Catalog records'],
        ['label' => 'Available Assets', 'value' => null, 'url' => route('products.index', ['stock_status' => 'available_to_rent']), 'tone' => 'success', 'subtext' => 'Ready stock'],
        ['label' => 'Low Stock', 'value' => null, 'url' => route('products.index', ['stock_status' => 'out_of_stock']), 'tone' => 'warning', 'subtext' => 'Needs attention'],
        ['label' => 'Out of Stock', 'value' => null, 'url' => route('products.index', ['stock_status' => 'out_of_stock']), 'tone' => 'danger', 'subtext' => 'No stock'],
        ['label' => 'Under Repair', 'value' => null, 'url' => route('products.index', ['stock_status' => 'maintenance']), 'tone' => 'warning', 'subtext' => 'Under repair'],
        ['label' => 'Top Renting', 'value' => null, 'url' => route('products.index', ['sort' => 'rented_units', 'direction' => 'desc']), 'tone' => 'accent', 'subtext' => 'High demand'],
    ];
@endphp

@section('content')
    <style>
        .product-page { display:grid; gap:10px; padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px)); }
        .product-hero { margin-bottom:0 !important; padding:14px 18px !important; }
        .product-hero .rx-page-title { font-size:30px; line-height:1.02; }
        .product-hero .rx-page-subtitle { max-width:860px; font-size:13px; line-height:1.45; }
        .product-shell {
            background:#fff;
            border:1px solid var(--ph-color-border);
            border-radius:14px;
            overflow:hidden;
            box-shadow:var(--ph-shadow-soft);
        }
        .product-shell-head {
            padding:10px 14px;
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
            grid-template-columns:repeat(8, minmax(0, 1fr));
            gap:8px;
        }
        .product-kpi {
            display:grid;
            gap:4px;
            min-height:78px;
            padding:10px 11px;
            border-radius:12px;
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
        .product-kpi.product-tone-danger { background:var(--ph-color-danger-soft); border-color:rgba(179,13,35,.18); }
        .product-kpi.product-tone-accent { background:#f5f3ff; border-color:rgba(109,40,217,.16); }
        .product-kpi.product-tone-neutral { background:var(--ph-color-surface-soft); }
        .product-kpi-label {
            font-size:10px;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
            color:var(--ph-color-text-soft);
            font-family:var(--ph-font-heading);
        }
        .product-kpi-value {
            font-size:20px;
            line-height:1;
            font-weight:800;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
        }
        .product-kpi-copy {
            color:var(--ph-color-text-soft);
            font-size:10.5px;
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
        .product-kpi.product-tone-danger .product-kpi-label,
        .product-kpi.product-tone-danger .product-kpi-value,
        .product-kpi.product-tone-danger .product-kpi-copy { color:var(--ph-color-danger); }
        .product-kpi.product-tone-accent .product-kpi-label,
        .product-kpi.product-tone-accent .product-kpi-value,
        .product-kpi.product-tone-accent .product-kpi-copy { color:#6d28d9; }
        .product-quick-filters,
        .product-intelligence-grid {
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            align-items:center;
        }
        .product-quick-filters {
            padding:10px 12px;
            border:1px solid var(--ph-color-border);
            border-radius:14px;
            background:#fff;
            box-shadow:var(--ph-shadow-soft);
        }
        .product-quick-chip {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:32px;
            padding:7px 11px;
            border-radius:999px;
            border:1px solid var(--ph-color-border);
            background:var(--ph-color-surface-soft);
            color:var(--ph-color-text);
            font-size:12px;
            font-weight:800;
            text-decoration:none;
            white-space:nowrap;
        }
        .product-quick-chip.is-active,
        .product-quick-chip:hover {
            background:var(--ph-color-info-soft);
            border-color:rgba(23,119,189,.22);
            color:var(--ph-color-primary);
        }
        .product-intelligence-card {
            flex:1 1 260px;
            min-height:84px;
            padding:11px 12px;
            border:1px solid var(--ph-color-border);
            border-radius:14px;
            background:#fff;
            box-shadow:var(--ph-shadow-soft);
        }
        .product-intelligence-card h3 {
            margin:0 0 6px;
            font-size:13px;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
        }
        .product-intelligence-card p {
            margin:0;
            color:var(--ph-color-text-soft);
            font-size:11.5px;
            line-height:1.35;
        }
        .product-mini-metrics {
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            margin-top:8px;
        }
        .product-mini-metric {
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:5px 8px;
            border-radius:999px;
            background:var(--ph-color-surface-soft);
            color:var(--ph-color-text-soft);
            font-size:11px;
            font-weight:800;
        }
        .product-mini-metric strong { color:var(--ph-color-text); }
        .product-filter-card {
            padding:0 16px 14px;
        }
        .product-primary-search {
            position:sticky;
            top:0;
            z-index:15;
            padding:10px 14px;
            border-bottom:1px solid var(--ph-color-border);
            background:linear-gradient(180deg, rgba(248,250,252,.95) 0%, rgba(255,255,255,1) 100%);
            display:grid;
            gap:8px;
        }
        .product-primary-search-form {
            display:grid;
            grid-template-columns:minmax(0, 1fr) minmax(120px, 150px) auto auto;
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
        .product-primary-search-field input,
        .product-primary-search-field select {
            width:100%;
            min-width:0;
            padding:8px 11px;
            border-radius:10px;
            border:1px solid var(--ph-color-border-strong);
            background:#fff;
            color:var(--ph-color-text);
            font-size:13px;
        }
        .product-row-actions {
            justify-content:flex-end;
            flex-wrap:nowrap;
            gap:5px;
            min-width:96px;
        }
        .product-row-actions .ph-btn,
        .product-row-actions .ph-btn-secondary,
        .product-row-actions .ph-btn-soft {
            min-height:32px;
            padding:7px 11px;
            border-radius:10px;
            font-size:12px;
            line-height:1;
            white-space:nowrap;
            box-shadow:none;
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
            padding:11px 0;
            font-weight:800;
            color:var(--ph-color-text);
            font-family:var(--ph-font-heading);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:9px;
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
        .product-table-wrap { overflow-x:auto; overflow-y:visible; max-height:none; }
        .product-table {
            width:100%;
            border-collapse:collapse;
            min-width:1040px;
        }
        .product-table-head {
            background:var(--ph-color-surface-soft);
            position:sticky;
            top:0;
            z-index:10;
        }
        .product-table-head th {
            text-align:left;
            padding:9px 10px;
            font-size:10px;
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
        .product-row:hover { background:#fbfdff; }
        .product-cell { padding:9px 10px; }
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
            font-size:14px;
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
            font-size:11.5px;
        }
        .product-meta-list,
        .product-price-list,
        .product-stack,
        .product-signal-stack { display:grid; gap:5px; }
        .product-price-list strong,
        .product-meta-list strong { color:var(--ph-color-text); }
        .product-badge {
            display:inline-flex;
            width:max-content;
            padding:4px 7px;
            border-radius:999px;
            font-size:9.5px;
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
        .product-badge.is-muted { background:#f8fafc; color:#94a3b8; border-color:var(--ph-color-border); }
        .product-stock-line {
            display:grid;
            grid-template-columns:70px minmax(80px, 1fr) auto;
            gap:7px;
            align-items:center;
            font-size:11px;
            color:var(--ph-color-text-soft);
            font-weight:700;
        }
        .product-stock-line strong { color:var(--ph-color-text); }
        .product-stock-bar {
            height:6px;
            border-radius:999px;
            background:#e2e8f0;
            overflow:hidden;
        }
        .product-stock-fill {
            display:block;
            height:100%;
            min-width:4px;
            border-radius:inherit;
            background:#94a3b8;
        }
        .product-stock-fill.is-success { background:#16a34a; }
        .product-stock-fill.is-info { background:#2563eb; }
        .product-stock-fill.is-warning { background:#d97706; }
        .product-stock-fill.is-danger { background:#dc2626; }
        .product-health {
            display:grid;
            gap:6px;
            min-width:150px;
        }
        .product-signal-card {
            padding:8px 10px;
            border-radius:12px;
            border:1px solid var(--ph-color-border);
            background:#fff;
        }
        .product-signal-card .product-signal-label {
            font-size:9.5px;
            font-weight:700;
            text-transform:uppercase;
            color:var(--ph-color-text-soft);
            font-family:var(--ph-font-heading);
        }
        .product-signal-card .product-signal-value {
            margin-top:3px;
            font-size:18px;
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
            width:32px;
            height:32px;
            border-radius:10px;
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
        .product-header-actions {
            display:flex;
            align-items:center;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }
        .product-icon-filter {
            position:relative;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:40px;
            height:40px;
            border-radius:12px;
            border:1px solid var(--ph-color-border-strong);
            background:#fff;
            color:var(--ph-color-primary);
            cursor:pointer;
        }
        .product-icon-filter svg { width:18px; height:18px; }
        .product-icon-filter span {
            position:absolute;
            top:-7px;
            right:-7px;
            min-width:18px;
            height:18px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            background:#4f46e5;
            color:#fff;
            font-size:10px;
            font-weight:900;
        }
        .product-dashboard { grid-template-columns:repeat(6, minmax(0, 1fr)); }
        .product-catalog-layout {
            display:grid;
            grid-template-columns:minmax(0, 1fr) 280px;
            gap:12px;
            padding:12px;
            background:#f8fafc;
        }
        .product-catalog-main {
            min-width:0;
            border:1px solid var(--ph-color-border);
            border-radius:14px;
            overflow:hidden;
            background:#fff;
        }
        .product-summary-panel {
            position:sticky;
            top:78px;
            align-self:start;
            display:grid;
            gap:12px;
        }
        .product-side-card {
            border:1px solid var(--ph-color-border);
            border-radius:14px;
            background:#fff;
            box-shadow:var(--ph-shadow-soft);
            overflow:hidden;
        }
        .product-side-card h3 {
            margin:0;
            padding:13px 14px 10px;
            font-size:14px;
            font-family:var(--ph-font-heading);
            color:var(--ph-color-text);
        }
        .product-side-list { display:grid; padding:0 14px 12px; }
        .product-side-row {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:8px 0;
            border-top:1px solid var(--ph-color-border);
            color:var(--ph-color-text-soft);
            font-size:12px;
        }
        .product-side-row strong { color:var(--ph-color-text); font-size:13px; }
        .product-util-ring {
            width:112px;
            height:112px;
            border-radius:50%;
            margin:4px auto 10px;
            display:grid;
            place-items:center;
            background:conic-gradient(#4f46e5 var(--product-utilization, 0%), #e2e8f0 0);
        }
        .product-util-ring-inner {
            width:78px;
            height:78px;
            border-radius:50%;
            background:#fff;
            display:grid;
            place-items:center;
            text-align:center;
            font-weight:900;
            color:var(--ph-color-text);
        }
        .product-util-ring-inner span { display:block; font-size:10px; color:var(--ph-color-text-soft); font-weight:800; }
        .product-thumb {
            width:42px;
            height:42px;
            border-radius:10px;
            border:1px solid var(--ph-color-border);
            background:linear-gradient(135deg, #eef2ff, #f8fafc);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            color:#4f46e5;
            flex:0 0 auto;
        }
        .product-thumb svg { width:22px; height:22px; }
        .product-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .product-mobile-thumb { width:44px; height:44px; border-radius:12px; border:1px solid #dbe3ef; background:#eef2ff; color:#3150ff; display:grid; place-items:center; flex:0 0 44px; overflow:hidden; font-size:13px; font-weight:900; }
        .product-mobile-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .product-title-line { display:flex; align-items:flex-start; gap:10px; min-width:0; }
        .product-mobile-chip-row,
        .product-mobile-row { display:none; }
        .product-desktop-links { display:none !important; }
        .mobile-list-command { display:none; }
        @media (max-width: 1100px) {
            .product-catalog-layout { grid-template-columns:1fr; }
            .product-summary-panel { position:static; grid-template-columns:repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 1280px) {
            .product-dashboard { grid-template-columns:repeat(4, minmax(0, 1fr)); }
        }
        @media (max-width: 767px) {
            .product-desktop-hero,
            .product-desktop-dashboard { display:none !important; }
            .product-quick-filters,
            .product-intelligence-grid { display:none !important; }
            .product-shell-head,
            .product-primary-search,
            .product-filter-card {
                display:none !important;
            }
            .product-catalog-layout {
                display:block;
                padding:0;
                background:transparent;
            }
            .product-catalog-main {
                border:0;
                border-radius:0;
                background:transparent;
            }
            .product-summary-panel { display:none !important; }
            .product-shell {
                overflow:visible;
            }
            .mobile-list-command {
                display:grid;
                gap:8px;
                padding:10px;
                border:1px solid var(--ph-color-border);
                border-radius:16px;
                background:#fff;
                box-shadow:var(--ph-shadow-soft);
            }
            .mobile-search-tools { display:grid; gap:8px; }
            .mobile-command-search { display:grid; grid-template-columns:minmax(0, 1fr); gap:8px; }
            .mobile-command-search input {
                width:100%;
                min-height:40px;
                padding:10px 12px;
                border:1px solid var(--ph-color-border-strong);
                border-radius:12px;
                background:#fff;
                color:var(--ph-color-text);
                font-size:16px;
                box-sizing:border-box;
            }
            .mobile-stat-strip {
                display:grid;
                grid-template-columns:repeat(2, minmax(0, 1fr));
                gap:6px;
            }
            .mobile-stat-strip a {
                display:grid;
                gap:2px;
                min-width:0;
                padding:8px 9px;
                border:1px solid var(--ph-color-border);
                border-radius:12px;
                background:var(--ph-color-surface-soft);
                color:var(--ph-color-text);
                text-decoration:none;
            }
            .mobile-stat-strip span {
                font-size:9px;
                color:var(--ph-color-text-soft);
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:.05em;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }
            .mobile-stat-strip strong {
                font-size:clamp(12px, 4vw, 15px);
                line-height:1.15;
                min-width:0;
                overflow-wrap:anywhere;
                word-break:break-word;
            }
            .product-mobile-chip-row {
                display:flex;
                gap:8px;
                overflow-x:auto;
                padding:2px 1px 4px;
                scrollbar-width:none;
            }
            .product-mobile-chip-row::-webkit-scrollbar { display:none; }
            .product-mobile-chip {
                flex:0 0 auto;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                min-height:34px;
                padding:7px 11px;
                border-radius:999px;
                border:1px solid var(--ph-color-border-strong);
                background:#fff;
                color:var(--ph-color-text);
                text-decoration:none;
                font-size:12px;
                font-weight:800;
            }
            .product-mobile-chip.is-active {
                background:var(--ph-color-sidebar);
                color:#fff;
                border-color:var(--ph-color-sidebar);
            }
            .product-filter-state {
                align-items:flex-start;
            }
            .product-filter-grid { grid-template-columns:1fr; }
            .product-filter-field--search { grid-column:auto !important; }
            .product-table-wrap { overflow:visible !important; }
            .product-table { min-width:0 !important; display:block; border-collapse:separate; border-spacing:0; }
            .product-table thead { display:none; }
            .product-table,
            .product-table tbody,
            .product-table tr,
            .product-table td { display:block; width:100%; }
            .product-table tbody {
                display:grid;
                gap:8px;
                padding:8px;
            }
            .product-table tr.product-row { display:none !important; }
            .product-mobile-row {
                display:block !important;
                margin:0 !important;
                border:0 !important;
                border-radius:0 !important;
                background:transparent !important;
                box-shadow:none !important;
                overflow:visible !important;
            }
            .product-mobile-row > td {
                display:block !important;
                padding:0 !important;
                border:0 !important;
                background:transparent !important;
            }
            .product-mobile-row > td::before { content:none !important; }
            .product-mobile-card {
                display:grid;
                gap:7px;
                padding:10px;
                border:1px solid var(--ph-color-border);
                border-radius:14px;
                background:#fff;
                box-shadow:0 10px 24px rgba(15,23,42,.06);
            }
            .product-mobile-card-head {
                display:grid;
                grid-template-columns:minmax(0, 1fr) auto;
                gap:10px;
                align-items:start;
            }
            .product-mobile-title {
                min-width:0;
                color:var(--ph-color-text);
                font-size:14px;
                font-weight:900;
                line-height:1.18;
                text-decoration:none;
            }
            .product-mobile-sku {
                margin-top:2px;
                color:var(--ph-color-text-soft);
                font-size:11px;
                font-weight:700;
                overflow:hidden;
                text-overflow:ellipsis;
                white-space:nowrap;
            }
            .product-mobile-price {
                display:grid;
                justify-items:end;
                gap:2px;
                color:var(--ph-color-text);
                font-size:12px;
                font-weight:900;
                line-height:1.1;
                white-space:nowrap;
            }
            .product-mobile-price span {
                display:block;
                color:var(--ph-color-primary);
                font-size:9px;
                font-weight:900;
                text-transform:uppercase;
                letter-spacing:.04em;
            }
            .product-mobile-types {
                display:flex;
                gap:5px;
                flex-wrap:wrap;
            }
            .product-mobile-types .product-badge {
                padding:3px 7px;
                font-size:9px;
            }
            .product-mobile-inventory {
                color:var(--ph-color-text-soft);
                font-size:11px;
                font-weight:800;
                line-height:1.2;
            }
            .product-mobile-health-row {
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:8px;
            }
            .product-mobile-health-row .product-badge {
                padding:4px 8px;
                font-size:9px;
            }
            .product-mobile-actions {
                display:flex;
                align-items:center;
                justify-content:flex-end;
                gap:6px;
                padding-top:2px;
                border-top:1px solid var(--ph-color-border);
            }
            .product-mobile-icon-btn,
            .product-mobile-actions summary {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:36px;
                height:32px;
                border:1px solid var(--ph-color-border);
                border-radius:10px;
                background:#fff;
                color:var(--ph-color-text);
                text-decoration:none;
                font-size:0;
                cursor:pointer;
                list-style:none;
            }
            .product-mobile-actions summary::-webkit-details-marker { display:none !important; }
            .product-mobile-actions summary::marker { content:"" !important; }
            .product-mobile-actions summary::before,
            .product-mobile-actions summary::after {
                content:none !important;
                display:none !important;
            }
            .product-mobile-icon-btn svg,
            .product-mobile-actions summary svg {
                width:16px;
                height:16px;
            }
            .product-mobile-actions .product-action-menu { position:relative; }
            .product-mobile-actions .product-action-panel {
                right:0;
                left:auto;
                min-width:190px;
                z-index:30;
            }
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
                <div class="rx-eyebrow">Home / Product Master</div>
                <h1 class="rx-page-title">Product Master</h1>
                <p class="rx-page-subtitle">Manage products, pricing and inventory.</p>
            </div>

            <div class="product-header-actions">
                <a href="{{ route('products.export.csv', $queryFor()) }}" class="ph-btn-secondary">Export CSV</a>
                <span class="product-pill">Product Master = catalog</span>
                <button type="button" class="product-icon-filter" data-product-filter-toggle aria-label="Open product filters">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18"/><path d="M7 12h10"/><path d="M10 19h4"/></svg>
                    @if($activeFilterCount > 0)<span>{{ $activeFilterCount }}</span>@endif
                </button>
                @if($canCreateProducts)
                    <a href="{{ route('products.create') }}" class="ph-btn">+ Add Product</a>
                @endif
            </div>
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
            $productDashboardCards[1]['value'] = $catalogTotals['rental_available'] ?? 0;
            $productDashboardCards[2]['value'] = $catalogTotals['low_stock_products'] ?? 0;
            $productDashboardCards[3]['value'] = $catalogTotals['low_stock_products'] ?? 0;
            $productDashboardCards[4]['value'] = $catalogTotals['under_repair'] ?? 0;
            $productDashboardCards[5]['value'] = $catalogTotals['rented_assets'] ?? 0;
            $quickFilters = [
                ['label' => 'All', 'url' => route('products.index'), 'active' => ! $hasActiveFilters],
                ['label' => 'Rental', 'url' => route('products.index', $queryFor(['type' => 'rentable', 'stock_status' => null, 'page' => null])), 'active' => $typeFilter === 'rentable'],
                ['label' => 'Sale', 'url' => route('products.index', $queryFor(['type' => 'sale_only', 'stock_status' => null, 'page' => null])), 'active' => $typeFilter === 'sale_only'],
                ['label' => 'Both', 'url' => route('products.index', $queryFor(['type' => 'both', 'stock_status' => null, 'page' => null])), 'active' => $typeFilter === 'both'],
                ['label' => 'Out of Stock', 'url' => route('products.index', $queryFor(['stock_status' => 'out_of_stock', 'type' => null, 'page' => null])), 'active' => $stockStatus === 'out_of_stock'],
                ['label' => 'Low Stock', 'url' => route('products.index', $queryFor(['stock_status' => 'out_of_stock', 'type' => null, 'page' => null])), 'active' => $stockStatus === 'out_of_stock'],
                ['label' => 'Under Repair', 'url' => route('products.index', $queryFor(['stock_status' => 'maintenance', 'type' => null, 'page' => null])), 'active' => $stockStatus === 'maintenance'],
                ['label' => 'Top Renting', 'url' => route('products.index', $queryFor(['sort' => 'rented_units', 'direction' => 'desc', 'page' => null])), 'active' => $sort === 'rented_units'],
                ['label' => 'Dead Inventory', 'url' => route('products.index', $queryFor(['stock_status' => 'out_of_stock', 'page' => null])), 'active' => false],
            ];
            $mobileSortOptions = [
                ['label' => 'Newest First', 'sort' => 'created_at', 'direction' => 'desc'],
                ['label' => 'Oldest First', 'sort' => 'created_at', 'direction' => 'asc'],
                ['label' => 'Name A-Z', 'sort' => 'name', 'direction' => 'asc'],
                ['label' => 'Sale Price High-Low', 'sort' => 'sale_price', 'direction' => 'desc'],
                ['label' => 'Top Renting', 'sort' => 'rented_units', 'direction' => 'desc'],
            ];
            $currentMobileSortLabel = collect($mobileSortOptions)
                ->first(fn ($option) => $sort === $option['sort'] && $direction === $option['direction'])['label']
                ?? 'Newest First';
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

        <div class="product-quick-filters" aria-label="Product quick filters">
            @foreach($quickFilters as $filter)
                <a href="{{ $filter['url'] }}" class="product-quick-chip {{ $filter['active'] ? 'is-active' : '' }}">{{ $filter['label'] }}</a>
            @endforeach
        </div>

        <div class="product-intelligence-grid">
            <a href="{{ route('products.index', $queryFor(['stock_status' => 'maintenance', 'page' => null])) }}" class="product-intelligence-card" style="text-decoration:none;">
                <h3>Needs Attention</h3>
                <p>Repair, no-stock, and low-stock products that can block fulfilment.</p>
                <div class="product-mini-metrics">
                    <span class="product-mini-metric">Repair <strong>{{ $metricDisplay($catalogTotals['under_repair'] ?? 0) }}</strong></span>
                    <span class="product-mini-metric">Low stock <strong>{{ $metricDisplay($catalogTotals['low_stock_products'] ?? 0) }}</strong></span>
                </div>
            </a>
            <a href="{{ route('products.index', $queryFor(['sort' => 'rented_units', 'direction' => 'desc', 'page' => null])) }}" class="product-intelligence-card" style="text-decoration:none;">
                <h3>Top Renting Products</h3>
                <p>Sort by active rented units to spot high-demand rental products.</p>
                <div class="product-mini-metrics">
                    <span class="product-mini-metric">Rented <strong>{{ $metricDisplay($catalogTotals['rented_assets'] ?? 0) }}</strong></span>
                    <span class="product-mini-metric">Available <strong>{{ $metricDisplay($catalogTotals['rental_available'] ?? 0) }}</strong></span>
                </div>
            </a>
            <a href="{{ route('products.index', $queryFor(['sort' => 'sale_price', 'direction' => 'desc', 'page' => null])) }}" class="product-intelligence-card" style="text-decoration:none;">
                <h3>Sales Readiness</h3>
                <p>Available sale units and sellable products for faster stock decisions.</p>
                <div class="product-mini-metrics">
                    <span class="product-mini-metric">Sale units <strong>{{ $metricDisplay($catalogTotals['sale_stock'] ?? 0) }}</strong></span>
                    <span class="product-mini-metric">Sellable <strong>{{ $metricDisplay($catalogTotals['sellable'] ?? 0) }}</strong></span>
                </div>
            </a>
        </div>

        <div id="products-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
            <div class="mobile-filter-sheet-panel">
                <div class="mobile-filter-sheet-header">
                    <div>
                        <h3>Product Filters</h3>
                        <p>Keep stock, type, and rows close on mobile.</p>
                    </div>
                    <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="products-mobile-filters" aria-label="Close product filters">x</button>
                </div>
                <div class="mobile-filter-sheet-body">
                    <form method="GET" action="{{ route('products.index') }}" class="mobile-sheet-form">
                        <input type="hidden" name="search" value="{{ $search }}">
                        <div class="mobile-sheet-grid">
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-category-filter">Category</label>
                                <select id="mobile-product-category-filter" name="category">
                                    <option value="">All categories</option>
                                    @foreach($categoryOptions as $categoryOption)
                                        @php
                                            $categoryValue = is_object($categoryOption) ? (string) $categoryOption->id : (string) $categoryOption;
                                            $categoryLabel = is_object($categoryOption) ? $categoryOption->name : $categoryOption;
                                        @endphp
                                        <option value="{{ $categoryValue }}" @selected((string) $category === $categoryValue)>{{ $categoryLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-type-filter">Type</label>
                                <select id="mobile-product-type-filter" name="type">
                                    <option value="">All types</option>
                                    <option value="rentable" @selected($typeFilter === 'rentable')>Rentable</option>
                                    <option value="sale_only" @selected($typeFilter === 'sale_only')>Sale only</option>
                                    <option value="both" @selected($typeFilter === 'both')>Both</option>
                                    <option value="untracked" @selected($typeFilter === 'untracked')>Untracked</option>
                                </select>
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-stock-status-filter">Stock status</label>
                                <select id="mobile-product-stock-status-filter" name="stock_status">
                                    <option value="">All stock states</option>
                                    <option value="available_to_rent" @selected($stockStatus === 'available_to_rent')>Available to rent</option>
                                    <option value="rented_out" @selected($stockStatus === 'rented_out')>Rented out</option>
                                    <option value="maintenance" @selected($stockStatus === 'maintenance')>Under repair</option>
                                    <option value="out_of_stock" @selected($stockStatus === 'out_of_stock')>Low / no stock</option>
                                </select>
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-brand-filter">Brand</label>
                                <input id="mobile-product-brand-filter" type="search" name="brand" value="{{ $brand }}" placeholder="Brand name">
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-sort-filter">Sort</label>
                                <select id="mobile-product-sort-filter" name="sort">
                                    <option value="created_at" @selected($sort === 'created_at')>Created</option>
                                    <option value="name" @selected($sort === 'name')>Name</option>
                                    <option value="sale_price" @selected($sort === 'sale_price')>Sale Price</option>
                                    <option value="rented_units" @selected($sort === 'rented_units')>Rented Units</option>
                                </select>
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-direction-filter">Direction</label>
                                <select id="mobile-product-direction-filter" name="direction">
                                    <option value="desc" @selected($direction === 'desc')>Descending</option>
                                    <option value="asc" @selected($direction === 'asc')>Ascending</option>
                                </select>
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile-product-per-page">Show</label>
                                <select id="mobile-product-per-page" name="per_page">
                                    @foreach($perPageOptions as $option)
                                        <option value="{{ $option }}" @selected((int) $perPage === (int) $option)>{{ $option }} per page</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mobile-sheet-actions">
                            <button type="submit" class="ph-btn">Apply</button>
                            <a href="{{ route('products.index') }}" class="ph-btn-soft">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="mobile-list-command" aria-label="Mobile product controls">
            <div class="mobile-search-tools">
                <form method="GET" action="{{ route('products.index') }}" class="mobile-command-search">
                    @foreach(request()->except(['search', 'page']) as $key => $value)
                        @if(is_scalar($value) && $value !== '')
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <input type="search" name="search" value="{{ $search }}" placeholder="Search product, brand, model, SKU">
                </form>

                <div class="mobile-action-toolbar {{ $hasActiveFilters ? 'has-active-filters' : '' }}" aria-label="Mobile product filters and sorting">
                    <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="products-mobile-filters" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" aria-label="Open product filters">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>
                    </button>
                    <div class="mobile-sort-anchor" data-mobile-sort-root>
                        <button type="button" class="mobile-toolbar-btn" data-mobile-sort-trigger aria-label="Sort products: {{ $currentMobileSortLabel }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m7 15 5 5 5-5"/><path d="M7 9 12 4l5 5"/></svg>
                        </button>
                        <div class="mobile-sort-popover" data-mobile-sort-menu hidden>
                            @foreach($mobileSortOptions as $option)
                                <a href="{{ route('products.index', $queryFor(['sort' => $option['sort'], 'direction' => $option['direction'], 'page' => null])) }}" class="mobile-sort-option {{ $sort === $option['sort'] && $direction === $option['direction'] ? 'is-active' : '' }}">{{ $option['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="mobile-stat-strip" aria-label="Product summary">
                <a href="{{ route('products.index') }}"><span>Products</span><strong>{{ $metricDisplay($catalogTotals['products'] ?? 0) }}</strong></a>
                <a href="{{ route('products.index', ['stock_status' => 'available_to_rent']) }}"><span>Available</span><strong>{{ $metricDisplay($catalogTotals['rental_available'] ?? 0) }}</strong></a>
                <a href="{{ route('products.index', ['stock_status' => 'maintenance']) }}"><span>Repair</span><strong>{{ $metricDisplay($catalogTotals['under_repair'] ?? 0) }}</strong></a>
                <a href="{{ route('products.index', ['stock_status' => 'out_of_stock']) }}"><span>Risk</span><strong>{{ $metricDisplay($catalogTotals['low_stock_products'] ?? 0) }}</strong></a>
            </div>

            <div class="product-mobile-chip-row" aria-label="Product quick filters">
                @foreach(array_slice($quickFilters, 0, 6) as $chip)
                    <a href="{{ $chip['url'] }}" class="product-mobile-chip {{ $chip['active'] ? 'is-active' : '' }}">{{ $chip['label'] }}</a>
                @endforeach
            </div>
        </div>

        <div class="product-mobile-chip-row product-desktop-links" aria-label="Product quick links">
            <a href="{{ route('products.index') }}" class="product-mobile-chip">Product Master</a>
            <a href="{{ route('assets.index', ['asset_stage' => 'rental_stock']) }}" class="product-mobile-chip">Rental Assets</a>
            <a href="{{ route('products.index', ['stock_status' => 'available_to_rent']) }}" class="product-mobile-chip">Available</a>
        </div>

        <div class="product-shell">
            <div class="product-shell-head">
                <div>
                    <h2>Product Catalog</h2>
                    <p>Compact view of products, pricing, stock health, and latest movement.</p>
                </div>
                <div class="product-pills">
                    <span class="product-pill">{{ $products->total() }} shown</span>
                    <span class="product-pill is-info">{{ $activeViewLabel ?: 'All products' }}</span>
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
                    <div class="product-primary-search-field">
                        <label for="product-per-page">Rows</label>
                        <select id="product-per-page" name="per_page" onchange="this.form.submit()">
                            @foreach($perPageOptions as $option)
                                <option value="{{ $option }}" @selected((int) $perPage === (int) $option)>{{ $option }} / page</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="ph-btn">Search</button>
                    <a href="{{ route('products.index') }}" class="ph-btn-soft">Clear Filters</a>
                </form>
                <div class="product-filter-status">
                    <div class="product-filter-status-copy">Search is always available. Use Filters &amp; Sorting for category, stock, brand, and sort controls.</div>
                    @if($hasActiveFilters)
                        <span class="product-pill is-info">Filters Active - {{ $activeFilterCount }}</span>
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

            <details class="product-filter-card" data-filter-panel data-filter-panel-key="products-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}">
                <summary class="product-filter-summary">
                    <span>Search &amp; Filters</span>
                    <span>{{ $hasActiveFilters ? 'Refine current results' : 'Expand advanced filters' }}</span>
                </summary>
                <form method="GET" action="{{ route('products.index') }}" class="product-filter-form">
                    <input type="hidden" name="search" value="{{ $search }}">
                    <input type="hidden" name="per_page" value="{{ $perPage }}">
                    <div class="product-filter-grid">
                        <div class="product-filter-field">
                            <label for="product-category-filter">Category</label>
                            <select id="product-category-filter" name="category">
                                <option value="">All categories</option>
                                @foreach($categoryOptions as $categoryOption)
                                    @php
                                        $categoryValue = is_object($categoryOption) ? (string) $categoryOption->id : (string) $categoryOption;
                                        $categoryLabel = is_object($categoryOption) ? $categoryOption->name : $categoryOption;
                                    @endphp
                                    <option value="{{ $categoryValue }}" @selected((string) $category === $categoryValue)>{{ $categoryLabel }}</option>
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
                                        @php
                                            $brandValue = is_object($brandOption) ? (string) $brandOption->id : (string) $brandOption;
                                            $brandLabel = is_object($brandOption) ? $brandOption->name : $brandOption;
                                        @endphp
                                        <option value="{{ $brandValue }}" @selected((string) $brand === $brandValue)>{{ $brandLabel }}</option>
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

            <div class="product-catalog-layout">
                <div class="product-catalog-main">
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
                                $primaryType = $canSell && $canRent
                                    ? \App\Models\Product::TYPE_BOTH
                                    : ($canRent ? \App\Models\Product::TYPE_RENTABLE : \App\Models\Product::TYPE_SELLABLE);
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
                                $lowStock = $canSell && $saleStock <= 2 && $saleStock > 0;
                                $noInventory = ($canSell && $saleStock <= 0) && ($canRent && $assetsCount <= 0);
                                $repairRequired = $maintenanceAssets > 0;
                                $highDemand = $rentedAssets > 0 && $rentedAssets >= max($availableAssets, 1);
                                $healthLabel = $repairRequired
                                    ? 'Repair Required'
                                    : ($noInventory
                                        ? 'No Inventory'
                                        : ($lowStock
                                            ? 'Low Stock'
                                            : ($highDemand ? 'High Demand' : 'Healthy')));
                                $healthTone = $repairRequired || $noInventory
                                    ? 'danger'
                                    : ($lowStock ? 'warning' : ($highDemand ? 'info' : 'success'));
                                $barMax = max($availableAssets, $rentedAssets, $maintenanceAssets, $saleStock, 1);
                                $barWidth = fn (int $value) => min(100, max(4, (int) round(($value / $barMax) * 100)));
                                $stockNumber = fn (int $value) => number_format($value);
                                $addStockUrl = route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']);
                                $assetRegisterUrl = route('assets.index', ['search' => $product->name]);
                                $convertUrl = route('products.show', $product) . ($hasSaleStock ? '#convert-stock' : '#convert-rental-stock');
                                $brandModel = trim(collect([$product->brand, $product->model_name])->filter()->implode(' '));
                                $hasDuplicateName = ($duplicateProductNameGroups ?? collect())->has(strtolower(trim((string) $product->name)));
                                $rowNumber = method_exists($products, 'firstItem') && $products->firstItem()
                                    ? $products->firstItem() + $loop->index
                                    : $loop->iteration;
                            @endphp
                            <tr class="product-mobile-row">
                                <td colspan="9">
                                    <article class="product-mobile-card">
                                        <div class="product-mobile-card-head">
                                            <div style="display:flex; align-items:center; gap:10px; min-width:0;"><span class="product-mobile-thumb" aria-hidden="true">@if($product->product_image_url)<img src="{{ $product->product_image_url }}" alt="">@else{{ strtoupper(mb_substr($product->name, 0, 1)) }}@endif</span><div style="min-width:0;"><a href="{{ route('products.show', $product) }}" class="product-mobile-title">{{ $product->name }}</a><div class="product-mobile-sku">SKU: {{ $product->sku ?: ($product->product_code ?: 'Not set') }}</div></div></div>
                                            <div class="product-mobile-price">
                                                @if($product->sale_price !== null)
                                                    <div>{{ $rupee }}{{ number_format((float) $product->sale_price, 0) }} <span>Sale</span></div>
                                                @endif
                                                @if($canRent && $product->price_per_day !== null)
                                                    <div>{{ $rupee }}{{ number_format((float) $product->price_per_day, 0) }}/day <span>Rental</span></div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="product-mobile-types">
                                            @if($canSell)
                                                <span class="product-badge is-success">Sellable</span>
                                            @endif
                                            @if($canRent)
                                                <span class="product-badge is-info">Rentable</span>
                                            @endif
                                            <span class="product-badge is-{{ $stockModeTone }}">{{ $stockModeLabel }}</span>
                                        </div>

                                        <div class="product-mobile-inventory">
                                            Inv {{ $stockNumber(max($availableAssets, $saleStock)) }} - Rent {{ $stockNumber($rentedAssets) }} - Repair {{ $stockNumber($maintenanceAssets) }}
                                        </div>

                                        <div class="product-mobile-health-row">
                                            <span class="product-badge is-{{ $healthTone }}">{{ $healthLabel }}</span>
                                            <div class="product-mobile-actions">
                                                <a href="{{ route('products.show', $product) }}" class="product-mobile-icon-btn" aria-label="View {{ $product->name }}">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                                </a>
                                                @if($canUpdateProducts)
                                                    <a href="{{ route('products.edit', $product) }}" class="product-mobile-icon-btn" aria-label="Edit {{ $product->name }}">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                                    </a>
                                                @endif
                                                <details class="product-action-menu">
                                                    <summary aria-label="More actions for {{ $product->name }}">
                                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                                                    </summary>
                                                    <div class="product-action-panel">
                                                        <a href="{{ route('products.show', $product) }}" class="product-action-link">View Product</a>
                                                        @if($canUpdateProducts)
                                                            <a href="{{ route('products.edit', $product) }}" class="product-action-link">Edit Product</a>
                                                        @endif
                                                        @if($canReadAssets)
                                                            <a href="{{ $assetRegisterUrl }}" class="product-action-link">View Assets</a>
                                                            <a href="{{ route('products.show', $product) }}#stock-history" class="product-action-link">Stock History</a>
                                                        @endif
                                                        @if($canCreateAssets)
                                                            <a href="{{ route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']) }}" class="product-action-link">Add Sale Unit</a>
                                                            <a href="{{ route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'rental_stock']) }}" class="product-action-link">Add Rental Asset</a>
                                                        @endif
                                                    </div>
                                                </details>
                                            </div>
                                        </div>
                                    </article>
                                </td>
                            </tr>
                            <tr class="product-row">
                                <td class="product-cell product-serial-col" data-label="No.">{{ $rowNumber }}</td>
                                <td class="product-cell product-bulk-col" data-label="Select">
                                    <input type="checkbox" class="product-row-check" value="{{ $product->id }}" aria-label="Select {{ $product->name }}">
                                </td>
                                <td class="product-cell" data-label="Product">
                                    <div class="product-title-line">
                                        <span class="product-thumb" aria-hidden="true">@if($product->product_image_url)<img src="{{ $product->product_image_url }}" alt="">@else<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 7.5 12 3 3.5 7.5 12 12l8.5-4.5Z"/><path d="M3.5 7.5V16L12 21l8.5-5V7.5"/><path d="M12 12v9"/></svg>@endif</span>
                                        <div class="product-stack">
                                            <a href="{{ route('products.show', $product) }}" class="product-name">{{ $product->name }}</a>
                                            <div class="product-subtext">
                                                {{ $brandModel !== '' ? $brandModel : ($product->product_code ?: ($product->sku ?: 'No code assigned')) }}
                                            </div>
                                        @if(filled($product->category))
                                            <span class="product-badge is-neutral">{{ $product->category }}</span>
                                        @endif
                                        <span class="product-badge is-{{ $healthTone }}">{{ $healthLabel }}</span>
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
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Master">
                                    <div class="product-meta-list">
                                        @if(filled($product->brand))
                                            <div><strong>Brand:</strong> {{ $product->brand }}</div>
                                        @endif
                                        @if(filled($product->model_name))
                                            <div><strong>Model:</strong> {{ $product->model_name }}</div>
                                        @endif
                                        @if(filled($product->sku))
                                            <div><strong>SKU:</strong> {{ $product->sku }}</div>
                                        @endif
                                        @if(filled($product->product_code))
                                            <div><strong>Code:</strong> {{ $product->product_code }}</div>
                                        @endif
                                        @if(blank($product->brand) && blank($product->model_name) && blank($product->sku) && blank($product->product_code))
                                            <span class="product-badge is-muted">-</span>
                                        @endif
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
                                    <div class="product-signal-stack">
                                        <div class="product-stock-line">
                                            <span>Available</span>
                                            <span class="product-stock-bar"><span class="product-stock-fill is-success" style="width:{{ $barWidth(max($availableAssets, $saleStock)) }}%;"></span></span>
                                            <strong>{{ $stockNumber(max($availableAssets, $saleStock)) }}</strong>
                                        </div>
                                        <div class="product-stock-line">
                                            <span>Rented</span>
                                            <span class="product-stock-bar"><span class="product-stock-fill is-info" style="width:{{ $barWidth($rentedAssets) }}%;"></span></span>
                                            <strong>{{ $stockNumber($rentedAssets) }}</strong>
                                        </div>
                                        <div class="product-stock-line">
                                            <span>Repair</span>
                                            <span class="product-stock-bar"><span class="product-stock-fill is-danger" style="width:{{ $barWidth($maintenanceAssets) }}%;"></span></span>
                                            <strong>{{ $stockNumber($maintenanceAssets) }}</strong>
                                        </div>
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Stock Signals">
                                    <div class="product-health">
                                        <span class="product-badge is-{{ $healthTone }}">{{ $healthLabel }}</span>
                                        <div class="product-copy">
                                            Assets {{ $stockNumber($assetsCount) }} - Sale {{ $stockNumber($saleStock) }}
                                        </div>
                                        <div class="product-copy">
                                            {{ $rentalWarehouses->take(2)->implode(', ') ?: 'Warehouse not assigned' }}{{ $rentalWarehouses->count() > 2 ? ' +' . ($rentalWarehouses->count() - 2) : '' }}
                                        </div>
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Pricing">
                                    <div class="product-price-list">
                                        @if($product->sale_price !== null)
                                            <div><strong>Sale:</strong> {{ $rupee }} {{ number_format($product->sale_price, 2) }}</div>
                                        @endif
                                        @if($canRent && $product->price_per_day !== null)
                                            <div><strong>Per Day:</strong> {{ $rupee }} {{ number_format((float) $product->price_per_day, 2) }}</div>
                                        @endif
                                        @if($product->rental_price_30_days !== null)
                                            <div><strong>30 Days:</strong> {{ $rupee }} {{ number_format($product->rental_price_30_days, 2) }}</div>
                                        @endif
                                        @if($product->sale_price === null && (! $canRent || $product->price_per_day === null) && $product->rental_price_30_days === null)
                                            <span class="product-badge is-muted">-</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="product-cell" data-label="Actions" style="text-align:right;">
                                    <div class="product-actions product-row-actions">
                                        <a href="{{ route('products.show', $product) }}" class="ph-btn">View</a>
                                        <details class="product-action-menu">
                                            <summary aria-label="More actions for {{ $product->name }}">...</summary>
                                            <div class="product-action-panel">
                                                <a href="{{ route('products.show', $product) }}" class="product-action-link">View Product</a>
                                                @if($canUpdateProducts)
                                                    <a href="{{ route('products.edit', $product) }}" class="product-action-link">Edit Product</a>
                                                @endif
                                                @if($canUpdateProducts && $canSell)
                                                    <a href="{{ route('assets.create', ['product_id' => $product->id, 'asset_stage' => 'new_stock']) }}" class="product-action-link">Add Sale Units</a>
                                                @endif
                                                @if($canCreateAssets && $canRent)
                                                    <a href="{{ route('assets.create', ['product_id' => $product->id]) }}" class="product-action-link">Add Rental Asset</a>
                                                @endif
                                                @if($canReadAssets)
                                                    <a href="{{ $assetRegisterUrl }}" class="product-action-link">View Assets</a>
                                                @endif
                                                <a href="{{ route('products.show', $product) }}#stock-movement" class="product-action-link">Stock Movement</a>
                                                <a href="{{ route('products.show', $product) }}#stock-history" class="product-action-link">Inventory History</a>
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
                                                @if($canCreateProducts)
                                                    <a href="{{ route('products.create', ['duplicate_product_id' => $product->id]) }}" class="product-action-link">Duplicate Product</a>
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

            <div style="padding:14px 16px 16px; border-top:1px solid var(--ph-color-border); background:var(--ph-color-surface-soft);">
                {{ $products->links('vendor.pagination.prime-healers', [
                    'summaryLabel' => 'products',
                    'ariaLabel' => 'Product Master pagination',
                ]) }}
            </div>
                </div>
                @php
                    $totalAssetsForUtilization = max((int) ($catalogTotals['rental_assets'] ?? 0), 1);
                    $rentedPercent = min(100, (int) round(((int) ($catalogTotals['rented_assets'] ?? 0) / $totalAssetsForUtilization) * 100));
                @endphp
                <aside class="product-summary-panel" aria-label="Product quick summary">
                    <section class="product-side-card">
                        <h3>Quick Summary</h3>
                        <div class="product-side-list">
                            <div class="product-side-row"><span>Total Products</span><strong>{{ $metricDisplay($catalogTotals['products'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Catalog (Sale)</span><strong>{{ $metricDisplay($catalogTotals['sellable'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Rental Enabled</span><strong>{{ $metricDisplay($catalogTotals['rentable'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Both Sale + Rental</span><strong>{{ $metricDisplay($catalogTotals['both'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Total Assets</span><strong>{{ $metricDisplay($catalogTotals['rental_assets'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Available Assets</span><strong>{{ $metricDisplay($catalogTotals['rental_available'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Rented Assets</span><strong>{{ $metricDisplay($catalogTotals['rented_assets'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Under Repair</span><strong>{{ $metricDisplay($catalogTotals['under_repair'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Low Stock</span><strong>{{ $metricDisplay($catalogTotals['low_stock_products'] ?? 0) }}</strong></div>
                        </div>
                    </section>
                    <section class="product-side-card">
                        <h3>Asset Utilization</h3>
                        <div class="product-util-ring" style="--product-utilization: {{ $rentedPercent }}%;">
                            <div class="product-util-ring-inner">{{ $rentedPercent }}%<span>Rented</span></div>
                        </div>
                        <div class="product-side-list">
                            <div class="product-side-row"><span>Rented</span><strong>{{ $metricDisplay($catalogTotals['rented_assets'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Available</span><strong>{{ $metricDisplay($catalogTotals['rental_available'] ?? 0) }}</strong></div>
                            <div class="product-side-row"><span>Repair</span><strong>{{ $metricDisplay($catalogTotals['under_repair'] ?? 0) }}</strong></div>
                        </div>
                    </section>
                </aside>
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

        document.querySelectorAll('[data-product-filter-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const panel = document.querySelector('[data-filter-panel][data-filter-panel-key="products-index"]');
                if (panel) {
                    panel.open = !panel.open;
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });
    });
</script>
@endpush
