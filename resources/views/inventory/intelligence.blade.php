@php
    $summary = $report['summary'];
    $dateHeaders = $report['dates'];
    $drilldown = $report['drilldown'];
    $timeScope = $report['time_scope'] ?? ($filters['time_scope'] ?? 'monthly');
    $bucketMode = $report['bucket_mode'] ?? 'daily';
    $periodLabel = $report['period_label'] ?? 'Selected period';
@endphp

@extends('layouts.app')

@section('content')
<style>
    .inventory-intelligence-modern { display:grid; gap:12px; max-width:100%; color:#0f172a; }
    .inventory-intelligence-modern .ph-card { border:1px solid #dbe7f3 !important; border-radius:16px !important; background:#fff !important; box-shadow:0 8px 22px rgba(15,23,42,.04); }
    .ii-header { padding:14px 16px !important; }
    .ii-breadcrumb { display:flex; gap:7px; flex-wrap:wrap; color:#64748b; font-size:11px; font-weight:800; margin-bottom:6px; }
    .ii-title { margin:0; font-size:24px; line-height:1.08; }
    .ii-subtitle { margin:4px 0 0; color:#52637a; font-size:13px; }
    .ii-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .ii-button { min-height:34px; display:inline-flex; align-items:center; justify-content:center; gap:7px; border-radius:11px; border:1px solid #c9d7ea; background:#fff; color:#1f2d44; font-size:12px; font-weight:850; padding:7px 11px; text-decoration:none; cursor:pointer; }
    .ii-button-primary { background:#3150ff; border-color:#3150ff; color:#fff; box-shadow:0 10px 20px rgba(49,80,255,.18); }
    .ii-kpis { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:8px; }
    .ii-kpi { padding:11px 12px !important; min-height:88px; display:grid; gap:7px; align-content:start; }
    .ii-kpi-top { display:flex; align-items:center; gap:8px; min-width:0; }
    .ii-icon { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; background:#eef2ff; color:#2742d8; font-weight:900; font-size:11px; flex:0 0 auto; }
    .ii-icon.green { background:#e8f8ef; color:#15803d; }
    .ii-icon.amber { background:#fff7df; color:#b45309; }
    .ii-icon.pink { background:#fff0f7; color:#be185d; }
    .ii-label { color:#607189; font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; }
    .ii-value { font-size:24px; line-height:1; font-weight:900; color:#0f172a; }
    .ii-help { color:#607189; font-size:12px; line-height:1.35; }
    .ii-filter { padding:12px 14px !important; }
    .ii-filter summary { list-style:none; cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:12px; }
    .ii-filter summary::-webkit-details-marker { display:none; }
    .ii-filter-title { display:grid; gap:3px; }
    .ii-filter-title strong { font-size:16px; }
    .ii-filter-title span { color:#607189; font-size:13px; }
    .ii-filter-body { margin-top:14px; display:grid; gap:12px; }
    .ii-main-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; align-items:start; }
    .ii-panel { padding:12px !important; display:grid; gap:8px; min-width:0; align-content:start; }
    .ii-panel-head { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .ii-panel-title { margin:0; font-size:15px; font-weight:900; }
    .ii-ring-wrap { display:grid; grid-template-columns:84px 1fr; gap:10px; align-items:start; }
    .ii-ring { --score:0; width:80px; height:80px; border-radius:999px; display:grid; place-items:center; background:conic-gradient(#22c55e calc(var(--score) * 1%), #dbe5f2 0); position:relative; }
    .ii-ring:after { content:""; width:58px; height:58px; border-radius:999px; background:#fff; position:absolute; }
    .ii-ring-content { position:relative; z-index:1; text-align:center; display:grid; gap:2px; }
    .ii-ring-content strong { font-size:18px; line-height:1; }
    .ii-ring-content span { font-size:10px; color:#607189; font-weight:800; }
    .ii-list { display:grid; gap:8px; }
    .ii-row { display:flex; justify-content:space-between; gap:10px; align-items:center; border-bottom:1px solid #e8eef7; padding:5px 0; font-size:12px; }
    .ii-row:last-child { border-bottom:0; }
    .ii-row span:first-child { color:#607189; }
    .ii-alert { display:flex; align-items:center; gap:9px; border:1px solid #fee2e2; background:#fff7f7; border-radius:11px; padding:9px 10px; color:#7f1d1d; }
    .ii-alert-dot { width:8px; height:8px; border-radius:999px; background:#ef4444; flex:0 0 auto; }
    .ii-alert-text strong { display:block; color:#7f1d1d; font-size:13px; }
    .ii-alert-text span { display:block; color:#9a3412; font-size:12px; margin-top:2px; }
    .ii-muted-note { border:1px solid #dbe7f3; border-radius:12px; padding:9px 10px; background:#f8fafc; color:#52637a; font-size:12px; }
    .ii-matrix-legend { display:flex; gap:10px; flex-wrap:wrap; align-items:center; color:#607189; font-size:12px; }
    .ii-matrix-legend span { display:inline-flex; align-items:center; gap:5px; }
    .ii-matrix-legend i { width:8px; height:8px; border-radius:999px; display:inline-block; }
    .ii-filter:not([open]) { display:none; }
    .ii-filter-count { min-width:18px; height:18px; padding:0 6px; border-radius:999px; background:#3150ff; color:#fff; font-size:11px; font-weight:900; display:inline-flex; align-items:center; justify-content:center; }
    .ii-details-card { padding:0 !important; overflow:hidden; }
    .ii-details-card > summary { list-style:none; cursor:pointer; display:flex; justify-content:space-between; gap:12px; align-items:center; padding:12px 14px; }
    .ii-details-card > summary::-webkit-details-marker { display:none; }
    .ii-details-title { display:grid; gap:2px; }
    .ii-details-title strong { font-size:15px; font-weight:900; }
    .ii-details-title span { font-size:12px; color:#64748b; }
    .ii-details-body { border-top:1px solid #e8eef7; padding:12px 14px; display:grid; gap:10px; }
    .ii-matrix-grid { display:grid; grid-template-columns:minmax(0, 1fr) 330px; gap:10px; align-items:start; }
    .ii-recon-table { overflow:auto; border:1px solid #e2e8f0; border-radius:14px; }
    .ii-recon-table table { width:100%; min-width:980px; border-collapse:separate; border-spacing:0; }
    .ii-recon-table th { text-align:left; padding:10px 12px; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
    .ii-recon-table td { padding:10px 12px; border-bottom:1px solid #eef2f7; font-size:12px; color:#334155; vertical-align:top; }
    .ii-recon-table tr:last-child td { border-bottom:0; }
    .ii-severity { display:inline-flex; border-radius:999px; padding:3px 8px; font-size:11px; font-weight:900; background:#eef2ff; color:#3150ff; }
    .ii-severity.critical { background:#fee2e2; color:#b91c1c; }
    .ii-severity.warning { background:#ffedd5; color:#b45309; }
    .ii-asset-list-card { padding:14px !important; display:grid; gap:10px; }
    .ii-asset-list-card:not(.is-expanded) .ii-extra-recon-row { display:none; }
    .ii-matrix-card-head { padding:12px 14px !important; }
    .ii-matrix-card.is-fullscreen { position:fixed !important; inset:12px !important; z-index:9999 !important; display:grid; grid-template-rows:auto minmax(0, 1fr); max-height:calc(100vh - 24px); }
    .ii-matrix-card.is-fullscreen > div:last-child { min-height:0; overflow:auto !important; }
    .ii-today-head { background:#eff6ff !important; color:#1d4ed8 !important; box-shadow:inset 0 -2px 0 #3150ff; }
    .ii-today-cell { background:#f8fbff; }
    .ii-matrix-grid table th { padding-top:8px !important; padding-bottom:8px !important; font-size:11px !important; }
    .ii-matrix-grid table td { padding-top:5px !important; padding-bottom:5px !important; }
    .ii-matrix-grid table td a { min-width:48px !important; padding:5px 6px !important; border-radius:10px !important; }
    .ii-matrix-grid table td a div:first-child { font-size:13px !important; }
    .ii-matrix-card:not(.is-fullscreen) tr.ii-extra-matrix-row { display:none; }
    .ii-matrix-grid tbody td:first-child { padding-top:9px !important; padding-bottom:9px !important; }
    .ii-matrix-grid tbody td:first-child strong { font-size:13px !important; line-height:1.25 !important; }
    .ii-matrix-grid tbody td:first-child span { font-size:10px !important; }
    .ii-matrix-grid tbody td:first-child div[style*="grid-template-columns"] { font-size:10px !important; gap:4px !important; margin-top:4px !important; }
    .ii-matrix-preview-note { padding:9px 14px; border-top:1px solid #e2e8f0; color:#607189; font-size:12px; display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap; }
    .ii-mode-banner { padding:12px 14px !important; display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; }
    .ii-mode-title { display:grid; gap:3px; min-width:0; }
    .ii-mode-title strong { font-size:16px; font-weight:900; }
    .ii-mode-title span { color:#607189; font-size:12px; }
    .ii-today-grid { display:grid; grid-template-columns:minmax(0, 1fr) 330px; gap:10px; align-items:start; }
    .ii-today-preview { padding:12px !important; display:grid; gap:10px; }
    .ii-today-products { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:8px; }
    .ii-today-card { border:1px solid #e2e8f0; border-radius:12px; padding:9px 10px; display:grid; gap:5px; background:#fbfdff; min-width:0; }
    .ii-today-card.has-movement { border-color:#93c5fd; background:#eff6ff; }
    .ii-today-card-top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
    .ii-today-card strong { font-size:12px; line-height:1.25; }
    .ii-today-card small { color:#64748b; font-size:10px; }
    .ii-stock-number { font-size:18px; font-weight:900; color:#0f172a; line-height:1; }
    .ii-move-pill { display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:2px 7px; font-size:10px; font-weight:900; background:#dbeafe; color:#1d4ed8; }
    .ii-filter[open] { position:fixed; right:16px; top:84px; width:min(520px, calc(100vw - 32px)); max-height:calc(100vh - 110px); overflow:auto; z-index:80; box-shadow:0 24px 70px rgba(15,23,42,.22); }
    .ii-matrix-explorer { grid-template-columns:1fr; }
    .ii-matrix-explorer .ii-matrix-card:not(.is-fullscreen) tr.ii-extra-matrix-row { display:table-row; }
    .ii-matrix-explorer .ii-matrix-card { min-height:calc(100vh - 235px); }
    .ii-matrix-explorer .ii-matrix-card > div[style*="overflow:auto"] { max-height:calc(100vh - 330px); }
    .ii-matrix-nav { display:flex; gap:7px; flex-wrap:wrap; align-items:center; }
    .ii-matrix-explorer thead th { position:sticky; top:0; z-index:4; }
    .ii-matrix-explorer thead th:first-child { z-index:5; }
    .ii-matrix-date { min-height:34px; border:1px solid #c9d7ea; border-radius:11px; padding:6px 9px; font-size:12px; font-weight:800; color:#1f2d44; }
    @media (max-width:1200px) { .ii-kpis { grid-template-columns:repeat(3, minmax(0, 1fr)); } .ii-main-grid { grid-template-columns:1fr; } .ii-matrix-grid { grid-template-columns:1fr; } }
    @media (max-width:760px) { .inventory-intelligence-modern { gap:12px; padding-bottom:96px; } .ii-header { padding:14px !important; } .ii-title { font-size:22px; } .ii-subtitle { font-size:13px; } .ii-actions { width:100%; display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); } .ii-button { min-height:36px; padding:8px 10px; font-size:12px; } .ii-kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:9px; } .ii-kpi { padding:11px !important; min-height:86px; gap:7px; } .ii-icon { width:30px; height:30px; border-radius:10px; } .ii-value { font-size:21px; } .ii-panel { padding:13px !important; } .ii-ring-wrap { grid-template-columns:82px 1fr; } .ii-ring { width:78px; height:78px; } .ii-ring:after { width:56px; height:56px; } .ii-ring-content strong { font-size:18px; } .ii-filter[open] { position:fixed; left:10px; right:10px; top:auto; bottom:82px; width:auto; max-height:76vh; overflow:auto; z-index:80; box-shadow:0 20px 60px rgba(15,23,42,.24); } .ii-today-grid { grid-template-columns:1fr; } .ii-today-products { grid-template-columns:1fr; } .ii-matrix-explorer .ii-matrix-card { min-height:calc(100vh - 250px); } }
</style>

@php
    $reconciliation = $report['reconciliation'] ?? null;
    $stockBasis = $summary['stock_basis_reconciliation'] ?? null;
    $assetReconciliation = $report['asset_reconciliation'] ?? null;
    $rentalReconciliation = $assetReconciliation['rental_reconciliation'] ?? null;
    $reconSummary = $rentalReconciliation['reconciliation_summary'] ?? [];
    $healthScore = (int) ($rentalReconciliation['health_score'] ?? 100);
    $needsReconciliation = (int) ($reconSummary['needs_reconciliation_assets'] ?? ($rentalReconciliation['unaccounted'] ?? 0));
    $alertRows = collect($assetReconciliation['warnings'] ?? [])->merge(collect($reconciliation['warnings'] ?? []))->take(3);
    $activeFilterCount = collect([
        (($filters['mode'] ?? 'all') !== 'all') ? 1 : null,
        filled($filters['city'] ?? null) ? 1 : null,
        filled($filters['warehouse_id'] ?? null) ? 1 : null,
        filled($filters['product_id'] ?? null) ? 1 : null,
        filled($filters['category'] ?? null) ? 1 : null,
        (($filters['time_scope'] ?? 'monthly') !== 'monthly') ? 1 : null,
    ])->filter()->count();
    $isMatrixExplorer = request('view') === 'matrix';
    $dashboardUrl = route('inventory-intelligence.index', collect(request()->query())->except('view')->all());
    $matrixUrl = route('inventory-intelligence.index', array_merge(request()->query(), ['view' => 'matrix']));
    $focusedBucket = collect($dateHeaders)->first(fn ($date) => now()->toDateString() >= $date['start']->toDateString() && now()->toDateString() <= $date['end']->toDateString()) ?: collect($dateHeaders)->last();
    $focusedBucketKey = $focusedBucket['key'] ?? null;
    $focusedBucketIsToday = $focusedBucket ? (now()->toDateString() >= $focusedBucket['start']->toDateString() && now()->toDateString() <= $focusedBucket['end']->toDateString()) : false;
    $focusedBucketLabel = $focusedBucket ? (($focusedBucketIsToday ? 'Today, ' : 'Latest, ') . $focusedBucket['label']) : 'Selected date';
    $todayPreviewRows = collect($report['rows'])->map(fn ($row) => [
        'row' => $row,
        'day' => $focusedBucketKey ? ($row['daily'][$focusedBucketKey] ?? null) : null,
    ])->sortByDesc(fn ($item) => (int) ($item['day']['movement_volume'] ?? 0))->take(6);
@endphp
<div class="inventory-intelligence-modern">
    <div class="ph-card ii-header">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">
            <div style="display:grid; gap:6px; min-width:0;">
                <div class="ii-breadcrumb"><span>Home</span><span>/</span><span>Inventory</span><span>/</span><span>Inventory Intelligence</span></div>
                <h1 class="ii-title">Inventory Intelligence</h1>
                <p class="ii-subtitle">Daily closing stock matrix and movement drill-down from the immutable ledger.</p>
            </div>
            <div class="ii-actions">
                @if($canExport)
                    <a href="{{ route('inventory-intelligence.export-matrix', request()->query()) }}" class="ii-button"><span aria-hidden="true">CSV</span> Matrix</a>
                    <a href="{{ route('inventory-intelligence.export-movements', request()->query()) }}" class="ii-button"><span aria-hidden="true">CSV</span> Movement</a>
                @endif
                @if($isMatrixExplorer)
                    <a href="{{ $dashboardUrl }}" class="ii-button">Back to Dashboard</a>
                @else
                    <a href="{{ $matrixUrl }}" class="ii-button ii-button-primary">Open Matrix Explorer</a>
                @endif
                <button type="button" class="ii-button" data-inventory-filter-trigger aria-expanded="false" aria-controls="inventory-filter-panel"><span aria-hidden="true">Filter</span>@if($activeFilterCount > 0)<span class="ii-filter-count">{{ $activeFilterCount }}</span>@endif</button>
            </div>
        </div>
    </div>

    <details class="ph-card ii-filter" id="inventory-filter-panel">
        <summary aria-controls="inventory-intelligence-filter-form">
            <div class="ii-filter-title"><strong>Filters</strong><span>Time scope, stock mode, location, product, and category.</span></div>
            <span class="ii-button" data-filter-toggle-label>Close</span>
        </summary>
        <form method="GET" action="{{ route('inventory-intelligence.index') }}" class="ii-filter-body" id="inventory-intelligence-filter-form">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Time Scope</label>
                    <select name="time_scope" class="ph-input" style="width:100%;" id="inventory-time-scope">
                        <option value="monthly" @selected($timeScope === 'monthly')>Monthly</option>
                        <option value="date_range" @selected($timeScope === 'date_range')>Date Range</option>
                        <option value="all_time" @selected($timeScope === 'all_time')>All Time</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Mode</label>
                    <select name="mode" class="ph-input" style="width:100%;">
                        <option value="all" @selected(($filters['mode'] ?? 'all') === 'all')>All</option>
                        <option value="rent" @selected(($filters['mode'] ?? '') === 'rent')>Rent</option>
                        <option value="sale" @selected(($filters['mode'] ?? '') === 'sale')>Sale</option>
                    </select>
                </div>
                <div data-time-scope-field="monthly" style="{{ $timeScope === 'monthly' ? '' : 'display:none;' }}">
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Month</label>
                    <select name="month" class="ph-input" style="width:100%;">
                        @foreach(range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((int) ($filters['month'] ?? 0) === $month)>{{ \Carbon\Carbon::create(null, $month, 1)->format('F') }}</option>
                        @endforeach
                    </select>
                </div>
                <div data-time-scope-field="monthly" style="{{ $timeScope === 'monthly' ? '' : 'display:none;' }}">
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Year</label>
                    <input type="number" name="year" value="{{ $filters['year'] ?? now()->year }}" class="ph-input" style="width:100%;" min="2000" max="2100">
                </div>
                <div data-time-scope-field="date_range" style="{{ $timeScope === 'date_range' ? '' : 'display:none;' }}">
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">From Date</label>
                    <input type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" class="ph-input" style="width:100%;">
                </div>
                <div data-time-scope-field="date_range" style="{{ $timeScope === 'date_range' ? '' : 'display:none;' }}">
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">To Date</label>
                    <input type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" class="ph-input" style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">City</label>
                    <select name="city" class="ph-input" style="width:100%;">
                        <option value="">All cities</option>
                        @foreach($report['filters']['cities'] as $city)
                            <option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Warehouse</label>
                    <select name="warehouse_id" class="ph-input" style="width:100%;">
                        <option value="">All warehouses</option>
                        @foreach($report['filters']['warehouses'] as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) ($filters['warehouse_id'] ?? 0) === (int) $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Product</label>
                    <select name="product_id" class="ph-input" style="width:100%;">
                        <option value="">All products</option>
                        @foreach($report['filters']['products'] as $product)
                            <option value="{{ $product->id }}" @selected((int) ($filters['product_id'] ?? 0) === (int) $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Category</label>
                    <select name="category" class="ph-input" style="width:100%;">
                        <option value="">All categories</option>
                        @foreach($report['filters']['categories'] as $category)
                            @php($categoryValue = is_object($category) ? (string) $category->id : (string) $category)
                            @php($categoryLabel = is_object($category) ? $category->name : $category)
                            <option value="{{ $categoryValue }}" @selected((string)($filters['category'] ?? '') === $categoryValue)>{{ $categoryLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="ii-actions">
                <button type="submit" class="ii-button ii-button-primary">Apply Filters</button>
                <a href="{{ route('inventory-intelligence.index') }}" class="ii-button">Reset</a>
            </div>
        </form>
    </details>

    <section class="ii-kpis" aria-label="Inventory summary">
        <article class="ph-card ii-kpi" title="Closing available stock for the selected period.">
            <div class="ii-kpi-top"><span class="ii-icon">CS</span><span class="ii-label">Closing Stock</span></div>
            <div class="ii-value">{{ $summary['closing_stock'] }}</div>
            <div class="ii-help">Total available</div>
        </article>
        <article class="ph-card ii-kpi" title="Opening stock reconstructed for the selected period.">
            <div class="ii-kpi-top"><span class="ii-icon green">OS</span><span class="ii-label">Opening Stock</span></div>
            <div class="ii-value">{{ $summary['opening_stock'] }}</div>
            <div class="ii-help">Reconstructed</div>
        </article>
        <article class="ph-card ii-kpi" title="Ledger movements plus fallback operational records.">
            <div class="ii-kpi-top"><span class="ii-icon">MV</span><span class="ii-label">Total Movements</span></div>
            <div class="ii-value">{{ ($reconciliation['ledger_movement_count'] ?? 0) + ($reconciliation['fallback_movement_count'] ?? 0) }}</div>
            <div class="ii-help">{{ $periodLabel }}</div>
        </article>
        <article class="ph-card ii-kpi" title="Rental assets currently in use as a percentage.">
            <div class="ii-kpi-top"><span class="ii-icon amber">%</span><span class="ii-label">Rental Utilization</span></div>
            <div class="ii-value">{{ number_format((float) $summary['rental_utilization_pct'], 1) }}%</div>
            <div class="ii-help">Assets in use</div>
        </article>
        <article class="ph-card ii-kpi" title="Sale product quantity consumed from stock.">
            <div class="ii-kpi-top"><span class="ii-icon pink">SO</span><span class="ii-label">Sale Units Out</span></div>
            <div class="ii-value">{{ $summary['total_sale_out'] }}</div>
            <div class="ii-help">Units consumed</div>
        </article>
        <article class="ph-card ii-kpi" title="Products below configured stock threshold.">
            <div class="ii-kpi-top"><span class="ii-icon amber">LS</span><span class="ii-label">Low Stock Products</span></div>
            <div class="ii-value">{{ $summary['low_stock_products'] }}</div>
            <div class="ii-help">Needs attention</div>
        </article>
    </section>

    @if($isMatrixExplorer)
        <section class="ph-card ii-mode-banner">
            <div class="ii-mode-title">
                <strong>Daily Closing Stock Matrix</strong>
                <span>{{ $focusedBucketIsToday ? 'Today is highlighted and centered.' : 'Selected range does not include today; latest available bucket is highlighted.' }}</span>
            </div>
            <div class="ii-matrix-nav">
                <a href="{{ $dashboardUrl }}" class="ii-button">Back to Dashboard</a>
                <button type="button" class="ii-button" data-matrix-prev>Previous date</button>
                <span class="ii-button" style="pointer-events:none;">{{ $focusedBucketLabel }}</span>
                <input type="date" class="ii-matrix-date" value="{{ $focusedBucket ? $focusedBucket['start']->toDateString() : now()->toDateString() }}" data-matrix-date aria-label="Select matrix date">
                <button type="button" class="ii-button" data-matrix-next>Next date</button>
                @if($canExport)
                    <a href="{{ route('inventory-intelligence.export-matrix', request()->query()) }}" class="ii-button">Export Matrix CSV</a>
                @endif
            </div>
        </section>
    <section class="ii-matrix-grid ii-matrix-explorer">
        <div class="ph-card ii-matrix-card" style="padding:0; border:1px solid #dbe7f3; border-radius:18px; background:#ffffff; overflow:hidden;">
        <div class="ii-matrix-card-head" style="padding:14px 16px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
            <div style="display:grid; gap:4px; min-width:0;">
                <strong style="font-size:15px;">Daily Closing Stock Matrix</strong>
                <span style="font-size:12px; color:#64748b;">{{ $bucketMode === 'daily' ? 'Daily' : ($bucketMode === 'weekly' ? 'Weekly' : 'Monthly') }} overview. Today is highlighted.</span>
                <div class="ii-matrix-legend"><span><i style="background:#22c55e;"></i> Increase</span><span><i style="background:#ef4444;"></i> Decrease</span><span><i style="background:#94a3b8;"></i> No change</span></div>
            </div>
            <button type="button" class="ii-button" data-matrix-fullscreen data-matrix-label="Expand">Expand</button>
        </div>
        <div style="overflow:auto;">
            <table style="width:100%; border-collapse:separate; border-spacing:0; min-width:1200px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="text-align:left; padding:10px 12px; font-size:11px; text-transform:uppercase; color:#64748b; position:sticky; left:0; background:#f8fafc; z-index:3; min-width:230px; border-right:1px solid #e2e8f0;">Product</th>
                        @foreach($dateHeaders as $date)
                            @php($isTodayBucket = $focusedBucketKey && $date['key'] === $focusedBucketKey)
                            <th @if($isTodayBucket) data-today-column @endif class="{{ $isTodayBucket ? 'ii-today-head' : '' }}" style="text-align:center; padding:12px 8px; font-size:12px; text-transform:uppercase; color:#64748b; white-space:nowrap; min-width:76px;">{{ $isTodayBucket ? ($focusedBucketIsToday ? 'Today ' : 'Latest ') : '' }}{{ $date['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @forelse($report['rows'] as $row)
                    <tr class="{{ $loop->iteration > 3 ? 'ii-extra-matrix-row' : '' }}">
                        <td style="padding:10px 12px; vertical-align:top; position:sticky; left:0; background:#ffffff; min-width:230px; z-index:2; border-top:1px solid #e2e8f0; border-right:1px solid #e2e8f0;">
                            <div style="display:grid; gap:4px;">
                                <strong>{{ $row['product']->name }}</strong>
                                <span style="font-size:12px; color:#64748b;">{{ $row['product']->category ?: 'Uncategorized' }}@if($row['product']->model_name) - {{ $row['product']->model_name }}@endif</span>
                                <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:6px; margin-top:6px; font-size:11px; color:#64748b;">
                                    <span>Open: <strong style="color:#0f172a;">{{ $row['monthly_summary']['opening_stock'] }}</strong></span>
                                    <span>Close: <strong style="color:#0f172a;">{{ $row['monthly_summary']['closing_stock'] }}</strong></span>
                                    <span>Out: <strong style="color:#0f172a;">{{ $row['monthly_summary']['rental_out'] + $row['monthly_summary']['sale_out'] }}</strong></span>
                                    <span>Util: <strong style="color:#0f172a;">{{ number_format((float) $row['monthly_summary']['utilization_pct'], 1) }}%</strong></span>
                                </div>
                            </div>
                        </td>
                        @foreach($dateHeaders as $date)
                            @php($day = $row['daily'][$date['key']] ?? null)
                            @php($hadMovement = (int) ($day['movement_volume'] ?? 0) > 0)
                            @php($isTodayBucket = $focusedBucketKey && $date['key'] === $focusedBucketKey)
                            <td class="{{ $isTodayBucket ? 'ii-today-cell' : '' }}" style="padding:8px 6px; vertical-align:top; border-top:1px solid #e2e8f0;">
                                <a href="{{ route('inventory-intelligence.index', array_merge(request()->query(), ['drill_product_id' => $row['product']->id, 'drill_bucket_start' => $date['start']->toDateString(), 'drill_bucket_end' => $date['end']->toDateString(), 'drill_bucket_label' => $date['label']])) }}"
                                   style="display:grid; gap:4px; min-width:64px; border:1px solid {{ $hadMovement ? '#93c5fd' : '#e2e8f0' }}; border-radius:14px; background:{{ $hadMovement ? '#eff6ff' : '#f8fafc' }}; padding:8px 8px; text-decoration:none; color:#0f172a; text-align:center;">
                                    <div style="font-size:16px; font-weight:800; line-height:1;">{{ $day['closing_stock'] ?? 0 }}</div>
                                    <div style="font-size:10px; color:#64748b; line-height:1.1;">{{ (int) ($day['net_change'] ?? 0) >= 0 ? '+' : '' }}{{ $day['net_change'] ?? 0 }}</div>
                                    @if($hadMovement)
                                        <span style="justify-self:center; display:inline-flex; align-items:center; border-radius:999px; padding:2px 6px; font-size:10px; font-weight:700; background:#dbeafe; color:#1d4ed8;">Move</span>
                                    @endif
                                </a>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 1 + $dateHeaders->count() }}" style="padding:28px 16px; color:#64748b;">No stock movements matched the selected filters yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(collect($report['rows'])->count() > 3)
            <div class="ii-matrix-preview-note">
                <span>Showing all {{ collect($report['rows'])->count() }} products in Matrix Explorer.</span>
                <button type="button" class="ii-button" data-matrix-fullscreen data-matrix-label="Fullscreen">Fullscreen</button>
            </div>
        @endif
    </div>
    </section>
    @else
        <section class="ii-today-grid">
            <article class="ph-card ii-today-preview">
                <div class="ii-panel-head">
                    <div class="ii-mode-title">
                        <strong>Today's Daily Closing Preview</strong>
                        <span>{{ $focusedBucketIsToday ? 'Live focus for today.' : 'Today is outside this scope; showing latest available date.' }}</span>
                    </div>
                    <span class="ii-button" style="pointer-events:none;">{{ $focusedBucketLabel }}</span>
               </div>
                <div class="ii-today-products">
                    @forelse($todayPreviewRows as $item)
                        @php($row = $item['row'])
                        @php($day = $item['day'])
                        @php($movementVolume = (int) ($day['movement_volume'] ?? 0))
                        <a class="ii-today-card {{ $movementVolume > 0 ? 'has-movement' : '' }}" href="{{ $focusedBucket ? route('inventory-intelligence.index', array_merge(request()->query(), ['view' => 'matrix', 'drill_product_id' => $row['product']->id, 'drill_bucket_start' => $focusedBucket['start']->toDateString(), 'drill_bucket_end' => $focusedBucket['end']->toDateString(), 'drill_bucket_label' => $focusedBucket['label']])) : $matrixUrl }}" style="text-decoration:none; color:inherit;">
                            <div class="ii-today-card-top">
                                <div style="display:grid; gap:2px; min-width:0;">
                                    <strong>{{ $row['product']->name }}</strong>
                                    <small>{{ $row['product']->category ?: 'Uncategorized' }}</small>
                                </div>
                                <div class="ii-stock-number">{{ $day['closing_stock'] ?? 0 }}</div>
                            </div>
                            <div style="display:flex; justify-content:space-between; gap:8px; align-items:center;">
                                <small>{{ (int) ($day['net_change'] ?? 0) >= 0 ? '+' : '' }}{{ $day['net_change'] ?? 0 }} net change</small>
                                @if($movementVolume > 0)<span class="ii-move-pill">{{ $movementVolume }} move</span>@endif
                            </div>
                        </a>
                    @empty
                        <div class="ii-muted-note">No stock rows matched the selected filters.</div>
                    @endforelse
                </div>
                <div class="ii-panel-head" style="border-top:1px solid #e8eef7; padding-top:8px;">
                    <span class="ii-help">Top products for the focused date are shown first.</span>
                    <a href="{{ $matrixUrl }}" class="ii-button ii-button-primary">Open Full Matrix</a>
                </div>
            </article>
            <article class="ph-card ii-panel">
                <div class="ii-panel-head">
                    <h2 class="ii-panel-title">Top High Movement Products</h2>
                    <span class="ii-label">{{ $summary['high_movement_products']->count() }} products</span>
                </div>
                <div class="ii-list">
                    @forelse($summary['high_movement_products']->take(5) as $productName)
                        <div class="ii-row"><span>{{ $productName }}</span><strong>High</strong></div>
                    @empty
                        <div class="ii-muted-note">No high movement products for the selected scope.</div>
                    @endforelse
                </div>
            </article>
        </section>
    <section class="ii-main-grid">
        <article class="ph-card ii-panel">
            <div class="ii-panel-head"><h2 class="ii-panel-title">Reconciliation Health</h2><span class="ii-label">{{ $periodLabel }}</span></div>
            <div class="ii-ring-wrap">
                <div class="ii-ring" style="--score: {{ max(0, min(100, $healthScore)) }};"><div class="ii-ring-content"><strong>{{ $healthScore }}%</strong><span>Reconciled</span></div></div>
                <div class="ii-list">
                    <div class="ii-row"><span>Assets need reconciliation</span><strong>{{ $needsReconciliation }}</strong></div>
                    <div class="ii-row"><span>Ledger movements</span><strong>{{ $reconciliation['ledger_movement_count'] ?? 0 }}</strong></div>
                    <div class="ii-row"><span>Fallback records</span><strong>{{ $reconciliation['fallback_movement_count'] ?? 0 }}</strong></div>
                    <div class="ii-row"><span>Quantity warnings</span><strong>{{ collect($reconciliation['warnings'] ?? [])->count() }}</strong></div>
                </div>
            </div>
        </article>
        <article class="ph-card ii-panel">
            <div class="ii-panel-head"><h2 class="ii-panel-title">Stock Overview</h2><span class="ii-label">Live buckets</span></div>
            <div class="ii-list">
                <div class="ii-row"><span>Total Rental Assets</span><strong>{{ $rentalReconciliation['total_rental_assets'] ?? ($stockBasis['rental_assets_total'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Active Rented</span><strong>{{ $rentalReconciliation['active_rented'] ?? ($stockBasis['rental_unavailable_breakdown']['rented'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Maintenance</span><strong>{{ $rentalReconciliation['maintenance'] ?? ($stockBasis['rental_unavailable_breakdown']['maintenance'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Awaiting Verification</span><strong>{{ $rentalReconciliation['awaiting_verification'] ?? ($stockBasis['rental_unavailable_breakdown']['awaiting_verification'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Reserved / Assigned</span><strong>{{ $rentalReconciliation['reserved'] ?? ($stockBasis['rental_unavailable_breakdown']['reserved'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Transfer / In Transit</span><strong>{{ $rentalReconciliation['transfer_in_progress'] ?? ($stockBasis['rental_unavailable_breakdown']['transfer_in_progress'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Available Now</span><strong>{{ $rentalReconciliation['available'] ?? ($stockBasis['rental_assets_available_now'] ?? 0) }}</strong></div>
                <div class="ii-row"><span>Needs Reconciliation</span><strong>{{ $needsReconciliation }}</strong></div>
            </div>
        </article>
        <article class="ph-card ii-panel">
            <div class="ii-panel-head"><h2 class="ii-panel-title">Alerts</h2><span class="ii-label">{{ $alertRows->count() }}</span></div>
            <div class="ii-list">
                @forelse($alertRows as $warning)
                    <div class="ii-alert"><span class="ii-alert-dot"></span><div class="ii-alert-text"><strong>{{ $warning['product'] ?? 'Inventory warning' }}</strong><span>{{ $warning['message'] ?? 'Reconciliation warning detected.' }}</span></div></div>
                @empty
                    <div class="ii-muted-note">No inventory alerts for the current filters.</div>
                @endforelse
                @if($alertRows->count() > 0)
                    <a class="ii-button" href="#asset-reconciliation-section">View all alerts</a>
                @endif
            </div>
        </article>
    </section>
    @if($rentalReconciliation && collect($rentalReconciliation['unclassified_assets'] ?? [])->isNotEmpty())
        <section class="ph-card ii-asset-list-card" id="asset-reconciliation-section">
            <div class="ii-panel-head">
                <div style="display:grid; gap:3px;">
                    <strong style="font-size:15px;">Needs Reconciliation Asset List</strong>
                    <span class="ii-help">Assets that need operational review before the stock position can be trusted.</span>
                </div>
                @if($canExport ?? false)
                    <a href="{{ route('inventory-intelligence.export-reconciliation', request()->query()) }}" class="ii-button">Export CSV</a>
                @endif
            </div>
            @if(collect($rentalReconciliation['unclassified_assets'] ?? [])->count() > 4)
                <div style="display:flex; justify-content:flex-end;"><button type="button" class="ii-button" data-expand-reconciliation>View all assets</button></div>
            @endif
            @if($canReconcile ?? false)
                <form method="POST" action="{{ route('inventory-intelligence.bulk-resolve', request()->query()) }}" style="display:grid; gap:10px;">
                    @csrf
                    <div style="display:grid; grid-template-columns:minmax(150px, .7fr) minmax(180px, 1fr) minmax(220px, 1.4fr); gap:8px; align-items:center;">
                        <select name="action" class="ph-input" style="min-height:36px; border:1px solid #cbd5e1; border-radius:10px; padding:7px 9px;">
                            <option value="mark_available">Bulk mark available</option>
                            <option value="send_to_review">Bulk send to review</option>
                            <option value="classify_maintenance">Bulk classify maintenance</option>
                            <option value="mark_retired">Bulk mark retired/scrap</option>
                        </select>
                        <input type="text" name="reason" placeholder="Reason" class="ph-input" style="min-height:36px; border:1px solid #cbd5e1; border-radius:10px; padding:7px 9px;">
                        <input type="text" name="remarks" placeholder="Remarks" class="ph-input" style="min-height:36px; border:1px solid #cbd5e1; border-radius:10px; padding:7px 9px;">
                    </div>
                    <div style="font-size:11px; color:#64748b;">Superadmin only. Corrections update asset status and create correction stock movements with audit trail.</div>
            @endif
            <div class="ii-recon-table">
                <table><thead><tr><th>Asset</th><th>Product</th><th>Severity</th><th>Recommended Action</th><th>Aging</th><th>Status</th><th>Warehouse</th><th>Linked Rental</th><th>Last Movement</th></tr></thead><tbody>
                    @foreach(($rentalReconciliation['unclassified_assets'] ?? []) as $asset)
                        <tr class="{{ $loop->iteration > 4 ? 'ii-extra-recon-row' : '' }}">
                            <td>@if($canReconcile ?? false)<label style="display:inline-flex; align-items:center; gap:8px; font-weight:800; color:#0f172a;"><input type="checkbox" name="asset_ids[]" value="{{ $asset['asset_id'] }}">#{{ $asset['asset_id'] }}</label>@else<strong>#{{ $asset['asset_id'] }}</strong>@endif<div style="font-size:11px; color:#64748b; margin-top:3px;">{{ $asset['serial_number'] ?: ($asset['barcode_value'] ?: '-') }}</div></td>
                            <td><strong style="color:#0f172a;">{{ $asset['product'] }}</strong><div style="font-size:11px; color:#64748b; margin-top:3px;">{{ $asset['condition_status'] ?: '-' }}</div></td>
                            <td><span class="ii-severity {{ ($asset['severity'] ?? '') === 'Critical' ? 'critical' : ((($asset['severity'] ?? '') === 'Warning') ? 'warning' : '') }}">{{ $asset['severity'] ?? 'Info' }}</span></td>
                            <td>{{ $asset['recommended_action'] ?? 'Investigate Manually' }}</td><td>{{ $asset['unclassified_for_days'] ?? '-' }} day(s)</td><td>{{ $asset['asset_status'] ?: '-' }}</td><td>{{ $asset['warehouse'] ?: '-' }}</td><td>{{ $asset['linked_rental_reference'] ?: ($asset['linked_rental_id'] ? 'Rental #' . $asset['linked_rental_id'] : '-') }}</td><td>{{ $asset['last_stock_movement'] ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody></table>
            </div>
            @if(collect($rentalReconciliation['unclassified_assets'] ?? [])->count() > 4)
                <div style="display:flex; justify-content:flex-end;"><button type="button" class="ii-button" data-expand-reconciliation>View all assets</button></div>
            @endif
            @if($canReconcile ?? false)
                    <button type="submit" class="ph-button">Apply Bulk Resolution</button>
                </form>
            @endif
        </section>
    @endif
    @php($reconciliation = $report['reconciliation'] ?? null)
    @if($reconciliation)
        <details class="ph-card ii-details-card">
            <summary>
                <span class="ii-details-title"><strong>Reconciliation Details</strong><span>Ledger fallback, missing records, and warnings.</span></span>
                <span class="ii-label">{{ collect($reconciliation['warnings'] ?? [])->count() }} warnings</span>
            </summary>
            <div class="ii-details-body">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px;">
                    <div class="ii-muted-note"><span class="ii-label">Ledger Movements</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $reconciliation['ledger_movement_count'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Fallback Records</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $reconciliation['fallback_movement_count'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Missing Ledger</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $reconciliation['missing_ledger_records_detected'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Quantity Warnings</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ collect($reconciliation['warnings'] ?? [])->count() }}</strong></div>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
                    <div class="ii-muted-note"><strong>Ledger Quantities</strong><div style="margin-top:6px; display:grid; gap:3px;">Sale Out: {{ $reconciliation['ledger_quantities']['sale_out'] ?? 0 }}<br>Rental Out: {{ $reconciliation['ledger_quantities']['rental_out'] ?? 0 }}<br>Returns: {{ $reconciliation['ledger_quantities']['returns'] ?? 0 }}</div></div>
                    <div class="ii-muted-note"><strong>Fallback Quantities</strong><div style="margin-top:6px; display:grid; gap:3px;">Sale Out: {{ $reconciliation['fallback_quantities']['sale_out'] ?? 0 }}<br>Rental Out: {{ $reconciliation['fallback_quantities']['rental_out'] ?? 0 }}<br>Returns: {{ $reconciliation['fallback_quantities']['returns'] ?? 0 }}</div></div>
                    <div class="ii-muted-note"><strong>Unlinked Records</strong><div style="margin-top:6px; display:grid; gap:3px;">Sales: {{ $reconciliation['unlinked_sales'] ?? 0 }}<br>Rentals: {{ $reconciliation['unlinked_rentals'] ?? 0 }}<br>Deliveries: {{ $reconciliation['unlinked_deliveries'] ?? 0 }}</div></div>
                </div>
                @if(collect($reconciliation['warnings'] ?? [])->isNotEmpty())
                    <div style="display:grid; gap:8px;">
                        <strong style="font-size:14px;">Warnings</strong>
                        @foreach(($reconciliation['warnings'] ?? []) as $warning)
                            <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:14px; padding:10px 12px; font-size:12px;">
                                {{ $warning['message'] ?? 'Reconciliation warning detected.' }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    @endif
    @php($stockBasis = $summary['stock_basis_reconciliation'] ?? null)
    @if($stockBasis)
        <details class="ph-card ii-details-card">
            <summary>
                <span class="ii-details-title"><strong>Stock Basis</strong><span>Opening stock formula and rental unavailable breakdown.</span></span>
                <span class="ii-label">Diagnostic</span>
            </summary>
            <div class="ii-details-body">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px;">
                    <div class="ii-muted-note"><span class="ii-label">Sale Available</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $stockBasis['sale_units_available_now'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Sale Units Sold</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $stockBasis['sale_units_sold_in_period'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Rental Assets</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $stockBasis['rental_assets_total'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Rental Available</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $stockBasis['rental_assets_available_now'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Rental Unavailable</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $stockBasis['rental_unavailable_now'] }}</strong></div>
                    <div class="ii-muted-note"><span class="ii-label">Current Available</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:20px;">{{ $stockBasis['current_available_stock'] }}</strong></div>
                </div>
                @if(($stockBasis['rental_unavailable_unexplained'] ?? 0) > 0)
                    <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:14px; padding:10px 12px; font-size:12px;">
                        {{ $stockBasis['rental_unavailable_unexplained'] }} rental asset(s) are still unaccounted for. The asset-state buckets do not fully explain why they are unavailable.
                    </div>
                @endif
            </div>
        </details>
    @endif
    @php($assetReconciliation = $report['asset_reconciliation'] ?? null)
    @if($assetReconciliation)
        <details class="ph-card ii-details-card" id="asset-state-diagnostics">
            <summary>
                <span class="ii-details-title"><strong>Asset State</strong><span>State distribution, warehouse reconciliation, and exceptions.</span></span>
                <span class="ii-label">{{ $needsReconciliation }} needs review</span>
            </summary>
            <div class="ii-details-body">
                @php($rentalReconciliation = $assetReconciliation['rental_reconciliation'] ?? null)
                @if($rentalReconciliation)
                    @php($reconSummary = $rentalReconciliation['reconciliation_summary'] ?? [])
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px;">
                        <div class="ii-muted-note"><span class="ii-label">Health</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:18px;">{{ $rentalReconciliation['health_score'] ?? 100 }}%</strong></div>
                        <div class="ii-muted-note"><span class="ii-label">Fully Reconciled</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:18px;">{{ $reconSummary['fully_reconciled_assets'] ?? 0 }}</strong></div>
                        <div class="ii-muted-note"><span class="ii-label">Needs Review</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:18px;">{{ $reconSummary['needs_reconciliation_assets'] ?? 0 }}</strong></div>
                        <div class="ii-muted-note"><span class="ii-label">Missing Ledger</span><strong style="display:block; margin-top:4px; color:#0f172a; font-size:18px;">{{ $report['reconciliation']['missing_ledger_records_detected'] ?? 0 }}</strong></div>
                    </div>
                @endif
                @if(collect($assetReconciliation['warnings'] ?? [])->isNotEmpty())
                    <div style="display:grid; gap:8px;">
                        <strong style="font-size:14px;">Asset Reconciliation Warnings</strong>
                        @foreach(($assetReconciliation['warnings'] ?? []) as $warning)
                            <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:12px; padding:9px 10px; font-size:12px;">{{ $warning['message'] ?? 'Asset reconciliation warning detected.' }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    @endif
    @endif
    @if($drilldown)
        <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; display:grid; gap:14px;">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                <div style="display:grid; gap:4px;">
                    <strong style="font-size:15px;">Movement Drill-down</strong>
                    <span style="font-size:13px; color:#64748b;">{{ $drilldown['product']->name }} - {{ $drilldown['label'] ?? $drilldown['date']->format('d M Y') }}</span>
                </div>
                <a href="{{ route('inventory-intelligence.index', collect(request()->query())->except(['drill_product_id', 'drill_date', 'drill_bucket_start', 'drill_bucket_end', 'drill_bucket_label'])->all()) }}" class="ph-button ph-button-secondary">Clear Drill-down</a>
            </div>

            <div style="display:grid; gap:12px;">
                @php($drillMovements = $drilldown['movements'])
                @if($drillMovements->count() > 0)
                    @foreach($drillMovements as $movement)
                        <div style="border:1px solid #e2e8f0; border-radius:18px; padding:16px; display:grid; gap:12px;">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                                <div style="display:grid; gap:4px;">
                                    <strong style="font-size:14px;">{{ strtoupper(str_replace('_', ' ', $movement->movement_type)) }}</strong>
                                    <span style="font-size:12px; color:#64748b;">{{ optional($movement->movement_at)->format('d M Y h:i A') }} - Qty {{ $movement->quantity }}</span>
                                </div>
                                <span style="font-size:12px; color:#64748b;">{{ $movement->performedBy?->name ?: 'System' }}</span>
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; font-size:13px; color:#334155;">
                                <div><strong>From - To:</strong> {{ $movement->from_status ?: '-' }} - {{ $movement->to_status ?: '-' }}</div>
                                <div><strong>Warehouse:</strong> {{ $movement->fromWarehouse?->name ?: '-' }} - {{ $movement->toWarehouse?->name ?: '-' }}</div>
                                <div><strong>Rental / Sale:</strong> {{ $movement->rental ? 'Rental #' . $movement->rental->id : ($movement->rental_id ? 'Rental #' . $movement->rental_id : '-') }} / {{ $movement->sale?->sale_number ?: ($movement->sale_id ? 'Sale #' . $movement->sale_id : '-') }}</div>
                                <div><strong>Delivery:</strong> {{ $movement->delivery_id ?: '-' }}</div>
                                <div><strong>Customer:</strong> {{ $movement->rental?->customer?->name ?: $movement->sale?->customer?->name ?: $movement->rental?->customer_name ?: '-' }}</div>
                                <div><strong>Asset:</strong> {{ $movement->asset?->serial_number ?: $movement->asset?->barcode_value ?: '-' }}</div>
                                <div style="grid-column:1 / -1;"><strong>Notes:</strong> {{ $movement->notes ?: '-' }}</div>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div style="border:1px dashed #cbd5e1; border-radius:18px; padding:16px; color:#64748b;">No movements found for that product and date under the current location filters.</div>
                @endif
            </div>
        </div>
    @endif
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const scopeSelect = document.getElementById('inventory-time-scope');
    const form = document.getElementById('inventory-intelligence-filter-form');

    const matrixCard = document.querySelector('.ii-matrix-card');
    const matrixScroller = matrixCard ? matrixCard.querySelector('div[style*="overflow:auto"]') : null;
    const matrixFullscreenButtons = Array.from(document.querySelectorAll('[data-matrix-fullscreen]'));
    const todayColumn = document.querySelector('[data-today-column]');

    if (matrixScroller && todayColumn) {
        window.requestAnimationFrame(function () {
            const targetLeft = Math.max(0, todayColumn.offsetLeft - (matrixScroller.clientWidth / 2) + (todayColumn.clientWidth / 2));
            matrixScroller.scrollLeft = targetLeft;
        });
    }

    if (matrixFullscreenButtons.length && matrixCard) {
        const setMatrixFullscreen = function (open) {
            matrixCard.classList.toggle('is-fullscreen', open);
            document.body.style.overflow = open ? 'hidden' : '';
            matrixFullscreenButtons.forEach(function (button) {
                button.textContent = open ? 'Close' : (button.dataset.matrixLabel || 'Expand');
            });
        };

        matrixFullscreenButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setMatrixFullscreen(!matrixCard.classList.contains('is-fullscreen'));
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && matrixCard.classList.contains('is-fullscreen')) {
                setMatrixFullscreen(false);
            }
        });
    }
    const matrixPrev = document.querySelector('[data-matrix-prev]');
    const matrixNext = document.querySelector('[data-matrix-next]');
    const expandReconciliation = document.querySelector('[data-expand-reconciliation]');
    const matrixDate = document.querySelector('[data-matrix-date]');

    if (matrixDate) {
        matrixDate.addEventListener('change', function () {
            if (!matrixDate.value) return;
            const url = new URL(window.location.href);
            url.searchParams.set('view', 'matrix');
            url.searchParams.set('time_scope', 'date_range');
            url.searchParams.set('from_date', matrixDate.value);
            url.searchParams.set('to_date', matrixDate.value);
            url.searchParams.delete('month');
            url.searchParams.delete('year');
            window.location.href = url.toString();
        });
    }

    if (matrixScroller && matrixPrev) {
        matrixPrev.addEventListener('click', function () {
            matrixScroller.scrollBy({ left: -140, behavior: 'smooth' });
        });
    }

    if (matrixScroller && matrixNext) {
        matrixNext.addEventListener('click', function () {
            matrixScroller.scrollBy({ left: 140, behavior: 'smooth' });
        });
    }

    if (expandReconciliation) {
        expandReconciliation.addEventListener('click', function () {
            const card = expandReconciliation.closest('.ii-asset-list-card');
            if (!card) return;
            const open = !card.classList.contains('is-expanded');
            card.classList.toggle('is-expanded', open);
            expandReconciliation.textContent = open ? 'Show fewer assets' : 'View all assets';
        });
    }
    const filterPanel = document.getElementById('inventory-filter-panel');
    const filterToggleLabel = document.querySelector('[data-filter-toggle-label]');

    const filterTrigger = document.querySelector('[data-inventory-filter-trigger]');
    if (filterPanel) {
        filterPanel.open = false;
    }

    const syncFilterLabel = () => {
        if (filterPanel && filterToggleLabel) {
            filterToggleLabel.textContent = filterPanel.open ? 'Close' : 'Filters';
        }
        if (filterTrigger && filterPanel) {
            filterTrigger.setAttribute('aria-expanded', filterPanel.open ? 'true' : 'false');
        }
    };

    if (filterTrigger && filterPanel) {
        filterTrigger.addEventListener('click', function () {
            filterPanel.open = !filterPanel.open;
            syncFilterLabel();
            if (filterPanel.open) {
                filterPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    if (filterPanel) {
        filterPanel.addEventListener('toggle', syncFilterLabel);
        syncFilterLabel();
    }
    if (!scopeSelect || !form) {
        return;
    }

    const monthlyFields = Array.from(form.querySelectorAll('[data-time-scope-field="monthly"]'));
    const rangeFields = Array.from(form.querySelectorAll('[data-time-scope-field="date_range"]'));

    const setDisabled = (container, disabled) => {
        container.querySelectorAll('input, select').forEach((field) => {
            field.disabled = disabled;
        });
    };

    const applyScope = () => {
        const scope = scopeSelect.value;
        const showMonthly = scope === 'monthly';
        const showRange = scope === 'date_range';

        monthlyFields.forEach((container) => {
            container.style.display = showMonthly ? '' : 'none';
            setDisabled(container, !showMonthly);
        });

        rangeFields.forEach((container) => {
            container.style.display = showRange ? '' : 'none';
            setDisabled(container, !showRange);
        });
    };

    scopeSelect.addEventListener('change', applyScope);
    applyScope();
});
</script>
@endsection
