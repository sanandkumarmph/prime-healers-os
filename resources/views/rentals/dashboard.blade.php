@extends('layouts.app')

@section('content')
@php
    $currency = fn ($value) => "\u{20B9}" . number_format((float) $value, 2);
    $baseFilters = collect(request()->query())->filter(fn ($value) => filled($value))->all();

    $rentalIndexUrl = function (array $overrides = []) use ($baseFilters) {
        $query = array_merge($baseFilters, $overrides);

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return route('rentals.index', $query);
    };

    $salesIndexUrl = route('sales.index');
    $productsIndexUrl = route('products.index');
    $inventoryUrl = route('inventory.dashboard');

    $statusBadge = function (?string $status) {
        return match ($status) {
            'active', 'completed' => 'background:#dcfce7;color:#166534;',
            'returned' => 'background:#dbeafe;color:#1d4ed8;',
            'pending', 'assigned' => 'background:#e2e8f0;color:#334155;',
            'in_progress' => 'background:#fef3c7;color:#b45309;',
            'warning' => 'background:#fffbeb;color:#a16207;',
            'info' => 'background:#eff6ff;color:#1d4ed8;',
            'overdue' => 'background:#fee2e2;color:#b91c1c;',
            default => 'background:#f1f5f9;color:#475569;',
        };
    };

    $metricCard = function (string $label, $value, string $href, string $tone = 'default', ?string $helper = null) {
        $tones = [
            'default' => ['background' => '#ffffff', 'border' => '#dbe3ef', 'accent' => '#0f172a'],
            'success' => ['background' => '#f0fdf4', 'border' => '#bbf7d0', 'accent' => '#166534'],
            'warning' => ['background' => '#fffbeb', 'border' => '#fde68a', 'accent' => '#a16207'],
            'danger' => ['background' => '#fff1f2', 'border' => '#fecdd3', 'accent' => '#b91c1c'],
            'info' => ['background' => '#eff6ff', 'border' => '#bfdbfe', 'accent' => '#1d4ed8'],
        ];
        $iconMap = [
            'Total Rentals' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18"/><path d="M8 14h4"/><path d="M8 18h8"/>',
            'Active Rentals' => '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M8 14h8"/><path d="m9.5 18 2 2 4-5"/>',
            'Delivered Rentals' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><path d="m8 13 2 2 4-4"/>',
            'Pending Delivery' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.5"/><circle cx="18" cy="18" r="1.5"/>',
            'Pending Pickup' => '<path d="M20 6v12H4"/><path d="m8 10-4 4 4 4"/><path d="M12 14h8"/>',
            'Ending Soon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'Overdue' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/>',
            'Returned Rentals' => '<path d="M4 12a8 8 0 1 0 2.3-5.7"/><path d="M4 4v5h5"/><path d="m9.5 12.5 2 2 4-4"/>',
            'Pending Delivery Assignments' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><path d="M18 8v4"/><path d="M16 10h4"/>',
            'Out For Delivery' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><path d="m7 14 2 2 4-4"/>',
            'Pending Pickup Assignments' => '<path d="M20 6v12H4"/><path d="m8 10-4 4 4 4"/><path d="M12 14h8"/>',
            'Out For Pickup' => '<path d="M20 6v12H4"/><path d="m8 10-4 4 4 4"/><path d="m13 17 5-5"/>',
            'Returns Due Today' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M3 10h18"/><path d="M12 14h.01"/><path d="M12 18h.01"/>',
            'Maintenance Alerts' => '<path d="m14.7 6.3 3 3"/><path d="M8.4 12.6 5.2 9.4a2 2 0 0 1 0-2.8l1.4-1.4a2 2 0 0 1 2.8 0l3.2 3.2"/><path d="m13 8 5.8 5.8a2 2 0 0 1 0 2.8l-1.2 1.2a2 2 0 0 1-2.8 0L9 12"/>',
            'Total Sales' => '<path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="19" r="1.5"/><circle cx="18" cy="19" r="1.5"/>',
            "Today\'s Sales" => '<path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><path d="m12 9 1.2 2.4L16 12l-2 1.8.8 2.7-2.8-1.5-2.8 1.5.8-2.7L8 12l2.8-.6z"/>',
            'Paid Sales' => '<path d="M12 3v18"/><path d="M17 7.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5 2.2 3.5 5 3.5 5 1.6 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5"/>',
            'Pending Sales' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'Sales Value' => '<path d="M12 3v18"/><path d="M17 7.5c0-1.9-2.2-3.5-5-3.5s-5 1.6-5 3.5 2.2 3.5 5 3.5 5 1.6 5 3.5-2.2 3.5-5 3.5-5-1.6-5-3.5"/>',
            'Available Stock Signal' => '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
            'Inventory Dashboard' => '<path d="M4 13h6V4H4v9Z"/><path d="M14 20h6V4h-6v16Z"/><path d="M4 20h6v-3H4v3Z"/>',
            'Rental Export' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
        ];

        $theme = $tones[$tone] ?? $tones['default'];
        $helperText = $helper ?? 'Open detailed view';
        $iconPaths = $iconMap[$label] ?? '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>';

        return sprintf(
            '<a href="%s" class="dash-metric-card" style="background:%s;border-color:%s;color:inherit;text-decoration:none;"><span class="dash-metric-icon" style="color:%s;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg></span><span class="dash-metric-label">%s</span><strong class="dash-metric-value" style="color:%s;">%s</strong><span class="dash-metric-helper">%s</span></a>',
            e($href),
            $theme['background'],
            $theme['border'],
            $theme['accent'],
            $iconPaths,
            e($label),
            $theme['accent'],
            e((string) $value),
            e($helperText),
        );
    };
@endphp

<style>
    .ops-dashboard-shell { display:grid; gap:16px; padding:18px 22px 28px; }
    .ops-dashboard-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; }
    .ops-dashboard-header h1 { margin:0; font-size:28px; line-height:1.08; color:#0f172a; }
    .ops-dashboard-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:760px; }
    .ops-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .ops-btn, .ops-btn-secondary, .ops-btn-success {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:36px; padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;
        text-decoration:none; border:1px solid transparent; cursor:pointer;
    }
    .ops-btn { background:#0f172a; color:#fff; }
    .ops-btn-secondary { background:#fff; color:#334155; border-color:#cbd5e1; }
    .ops-btn-success { background:#16a34a; color:#fff; }
    .ops-panel { background:#fff; border:1px solid #dbe3ef; border-radius:14px; box-shadow:0 8px 24px rgba(15,23,42,0.04); }
    .ops-panel-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:14px 16px; border-bottom:1px solid #e2e8f0; }
    .ops-panel-head h2 { margin:0; font-size:16px; color:#0f172a; }
    .ops-panel-head p { margin:0; color:#64748b; font-size:12px; }
    .ops-panel-body { padding:14px 16px; }
    .ops-section-label { margin:0; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.08em; }
    .ops-filter-grid { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:10px; }
    .ops-field { display:grid; gap:5px; }
    .ops-field label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .ops-input, .ops-select {
        width:100%; min-height:38px; padding:8px 11px; border:1px solid #cbd5e1; border-radius:10px;
        font-size:13px; color:#0f172a; background:#fff;
    }
    .ops-filter-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:12px; }
    .ops-metric-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .ops-metric-card {
        display:flex; flex-direction:column; justify-content:space-between; gap:8px;
        min-height:104px; padding:13px 14px; border:1px solid; border-radius:14px;
        transition:transform .15s ease, box-shadow .15s ease;
        box-shadow:0 8px 24px rgba(15,23,42,0.03);
    }
    .ops-metric-card:hover { transform:translateY(-1px); box-shadow:0 12px 28px rgba(15,23,42,0.07); }
    .ops-metric-label { display:block; max-width:calc(100% - 44px); font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; line-height:1.35; overflow-wrap:anywhere; text-wrap:balance; }
    .ops-metric-value { max-width:100%; font-size:24px; line-height:1.08; overflow-wrap:anywhere; }
    .ops-metric-helper { font-size:12px; color:#64748b; line-height:1.5; overflow-wrap:anywhere; }
    .dash-metric-card {
        position:relative;
        overflow:hidden;
    }
    .dash-metric-icon {
        position:absolute;
        top:14px;
        right:14px;
        width:38px;
        height:38px;
        border-radius:14px;
        display:grid;
        place-items:center;
        background:rgba(255,255,255,.72);
        border:1px solid rgba(255,255,255,.85);
        box-shadow:0 10px 24px rgba(15,23,42,.06);
    }
    .dash-metric-icon svg { width:18px; height:18px; }
    .ops-two-col { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:16px; }
    .ops-three-col { display:grid; grid-template-columns:1.15fr 1fr 1fr; gap:16px; align-items:start; }
    .ops-ops-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .ops-list { display:grid; gap:10px; }
    .ops-list-item { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fcfdff; display:grid; gap:8px; }
    .ops-list-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; flex-wrap:wrap; }
    .ops-list-title { margin:0; font-size:14px; color:#0f172a; }
    .ops-list-subtitle { margin:4px 0 0; font-size:12px; color:#64748b; }
    .ops-badge { display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .ops-breakdown-list { display:grid; gap:8px; }
    .ops-breakdown-row {
        display:flex; justify-content:space-between; align-items:center; gap:10px;
        border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; background:#fff;
    }
    .ops-breakdown-row a { color:#0f172a; text-decoration:none; font-weight:600; }
    .ops-breakdown-row small { color:#64748b; display:block; margin-top:3px; }
    .ops-mini-meta { display:flex; gap:12px; flex-wrap:wrap; color:#64748b; font-size:12px; }
    .ops-mini-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .ops-mini-actions .ops-btn,
    .ops-mini-actions .ops-btn-secondary,
    .ops-mini-actions .ops-btn-success { min-height:32px; padding:6px 10px; font-size:12px; }
    .ops-date-table { width:100%; border-collapse:collapse; }
    .ops-date-table th, .ops-date-table td { padding:10px 8px; border-bottom:1px solid #e2e8f0; text-align:left; font-size:13px; }
    .ops-date-table th { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
    .ops-empty { color:#64748b; font-size:13px; }
    @media (max-width: 1200px) {
        .ops-filter-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
        .ops-metric-grid, .ops-ops-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .ops-three-col { grid-template-columns:1fr; }
    }
    @media (max-width: 860px) {
        .ops-two-col, .ops-metric-grid, .ops-ops-grid { grid-template-columns:1fr; }
        .ops-filter-grid { grid-template-columns:1fr 1fr; }
    }
    @media (max-width: 640px) {
        .ops-dashboard-shell { padding:14px; }
        .ops-filter-grid { grid-template-columns:1fr; }
        .ops-filter-actions, .ops-header-actions, .ops-mini-actions { flex-direction:column; align-items:stretch; }
        .ops-metric-label { font-size:11px; }
        .ops-metric-value { font-size:22px; }
        .dash-metric-icon { width:34px; height:34px; }
        .dash-metric-icon svg { width:16px; height:16px; }
        .ops-filter-panel .ops-panel-head {
            border-bottom: 0;
            padding: 12px 14px;
        }
        .ops-filter-panel[open] .ops-panel-head {
            border-bottom: 1px solid #e2e8f0;
        }
        .ops-filter-panel .ops-panel-head h2 {
            font-size: 15px;
        }
        .ops-filter-panel .ops-panel-head p {
            font-size: 12px;
        }
        .ops-filter-summary-meta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ops-filter-summary-meta::after {
            content: "Expand";
        }
        .ops-filter-panel[open] .ops-filter-summary-meta::after {
            content: "Collapse";
        }
        .ops-filter-panel .ops-panel-body {
            padding-top: 12px;
        }
    }
</style>

<div class="container ops-dashboard-shell">
    <div class="ops-dashboard-header">
        <div>
            <div class="rx-eyebrow">Operations Control</div>
            <h1>Operations Dashboard</h1>
            <p>Compact daily view for rentals, dispatch, sales visibility, stock readiness, and action-first follow-up.</p>
        </div>

        <div class="ops-header-actions">
            <a href="{{ route('dashboard.export.csv', $baseFilters) }}" class="ops-btn-secondary">Export CSV</a>
            <a href="{{ route('rentals.create') }}" class="ops-btn">+ New Rental</a>
        </div>
    </div>

    @if(session('success'))
        <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 14px;border-radius:12px;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:12px 14px;border-radius:12px;">
            {{ session('error') }}
        </div>
    @endif

    <details class="ops-panel ops-filter-panel">
        <summary class="ops-panel-head" style="cursor:pointer; list-style:none;">
            <div>
                        <h2>Filters</h2>
                        <p>Keep dashboard links and drilldowns aligned to one operational view.</p>
            </div>
            <span class="ops-filter-summary-meta" aria-hidden="true"></span>
        </summary>
        <div class="ops-panel-body">
            <form method="GET" action="{{ route('dashboard') }}">
                <div class="ops-filter-grid">
                    <div class="ops-field">
                        <label for="city">City</label>
                        <select id="city" class="ops-select" name="city">
                            <option value="">All Cities</option>
                            @foreach($cities as $cityOption)
                                <option value="{{ $cityOption }}" @selected($city === $cityOption)>{{ $cityOption }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ops-field">
                        <label for="vendor_id">Vendor</label>
                        <select id="vendor_id" class="ops-select" name="vendor_id">
                            <option value="">All Vendors / Staff</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) $vendorId === (string) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ops-field">
                        <label for="dispatch_warehouse_id">Warehouse</label>
                        <select id="dispatch_warehouse_id" class="ops-select" name="dispatch_warehouse_id">
                            <option value="">All Warehouses</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) $warehouseId === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ops-field">
                        <label for="from_date">From Date</label>
                        <input id="from_date" class="ops-input" type="date" name="from_date" value="{{ $fromDate }}">
                    </div>

                    <div class="ops-field">
                        <label for="to_date">To Date</label>
                        <input id="to_date" class="ops-input" type="date" name="to_date" value="{{ $toDate }}">
                    </div>

                    <div class="ops-field">
                        <label for="search">Search</label>
                        <input id="search" class="ops-input" type="text" name="search" value="{{ $search }}" placeholder="Customer, phone, product, asset">
                    </div>
                </div>

                <div class="ops-filter-actions">
                    <button type="submit" class="ops-btn">Apply Filters</button>
                    <a href="{{ route('dashboard') }}" class="ops-btn-secondary">Reset</a>
                    <a href="{{ route('dashboard.export.csv', $baseFilters) }}" class="ops-btn-secondary">Export Current View</a>
                </div>
            </form>
        </div>
    </details>

    <div style="display:grid; gap:10px;">
        <h2 class="ops-section-label">Summary Cards</h2>
        <div class="ops-metric-grid">
            {!! $metricCard('Total Rentals', $totalRentals, $rentalIndexUrl(), 'default', 'Current filtered rental set') !!}
            {!! $metricCard('Active Rentals', $activeRentals, $rentalIndexUrl(['status' => 'active', 'filter' => null]), 'success', 'Live rental lifecycle') !!}
            {!! $metricCard('Delivered Rentals', $deliveredRentals, $rentalIndexUrl(['status' => 'delivered', 'filter' => null]), 'info', 'Delivery completed') !!}
            {!! $metricCard('Pending Delivery', $pendingDeliveryCount, $rentalIndexUrl(['filter' => 'pending_delivery', 'status' => null]), 'warning', 'Awaiting dispatch action') !!}
            {!! $metricCard('Pending Pickup', $pendingPickupCount, route('deliveries.index', ['board' => 'pending_pickup']), 'warning', 'Awaiting pickup action') !!}
            {!! $metricCard('Ending Soon', $endingSoonCount, $rentalIndexUrl(['filter' => 'ending_soon', 'status' => null]), 'warning', 'Due within the alert window') !!}
            {!! $metricCard('Overdue', $overdueCount, $rentalIndexUrl(['filter' => 'overdue', 'status' => null]), 'danger', 'Delivered, active, and overdue') !!}
            {!! $metricCard('Returned Rentals', $returnedRentals, $rentalIndexUrl(['status' => 'returned', 'filter' => null]), 'default', 'Closed rentals') !!}
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="ops-section-label">Delivery & Pickup Operations</h2>
        <div class="ops-ops-grid">
            {!! $metricCard('Pending Delivery Assignments', $pendingDeliveryCount, $rentalIndexUrl(['filter' => 'pending_delivery', 'status' => null]), 'warning', 'Delivery not started') !!}
            {!! $metricCard('Out For Delivery', $outForDeliveryCount, $rentalIndexUrl(['filter' => 'out_for_delivery', 'status' => null]), 'info', 'Delivery in progress') !!}
            {!! $metricCard('Pending Pickup Assignments', $pendingPickupCount, route('deliveries.index', ['board' => 'pending_pickup']), 'warning', 'Pickup not started') !!}
            {!! $metricCard('Out For Pickup', $outForPickupCount, route('deliveries.index', ['board' => 'out_pickup']), 'info', 'Pickup in progress') !!}
        </div>
    </div>

    <div class="ops-two-col">
        <div style="display:grid; gap:10px;">
            <h2 class="ops-section-label">Rental Alerts</h2>
            <div class="ops-panel">
                <div class="ops-panel-head">
                    <div>
                        <h2>Operational Alerts</h2>
                        <p>Quick drilldowns for urgent rental follow-up.</p>
                    </div>
                </div>
                <div class="ops-panel-body">
                    <div class="ops-metric-grid" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                        {!! $metricCard('Ending Soon', $endingSoonCount, $rentalIndexUrl(['filter' => 'ending_soon', 'status' => null]), 'warning', 'Renewal or return attention') !!}
                        {!! $metricCard('Overdue', $overdueCount, $rentalIndexUrl(['filter' => 'overdue', 'status' => null]), 'danger', 'Past due delivered rentals') !!}
                        {!! $metricCard('Returns Due Today', $returnsDueTodayCount, $rentalIndexUrl(['filter' => 'returns_due_today', 'status' => null]), 'info', 'Due back today') !!}
                        {!! $metricCard('Maintenance Alerts', $maintenanceAlertCount, route('assets.index', ['asset_status' => 'maintenance']), 'danger', 'Assets in maintenance') !!}
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid; gap:10px;">
            <h2 class="ops-section-label">Business Snapshot</h2>
            <div class="ops-panel">
                <div class="ops-panel-head">
                    <div>
                        <h2>Sales & Stock Summary</h2>
                        <p>Compact non-rental visibility for daily operations.</p>
                    </div>
                </div>
                <div class="ops-panel-body">
                    <div class="ops-metric-grid" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                        {!! $metricCard('Total Sales', $totalSales, $salesIndexUrl, 'info', 'Open sales module') !!}
                        {!! $metricCard("Today's Sales", $todaySales, $salesIndexUrl, 'info', 'Sales created today') !!}
                        {!! $metricCard('Paid Sales', $currency($paidSalesAmount), $salesIndexUrl, 'success', 'Collected sales amount') !!}
                        {!! $metricCard('Pending Sales', $currency($pendingSalesAmount), $salesIndexUrl, 'warning', 'Pending payment value') !!}
                        {!! $metricCard('Sales Value', $currency($totalSalesAmount), $salesIndexUrl, 'success', 'Gross sales amount') !!}
                        {!! $metricCard('Available Stock Signal', $availableProducts, $productsIndexUrl, 'default', 'Current stock count') !!}
                        {!! $metricCard('Inventory Dashboard', 'Open', $inventoryUrl, 'default', 'Asset and warehouse view') !!}
                        {!! $metricCard('Rental Export', 'CSV', route('dashboard.export.csv', $baseFilters), 'default', 'Download filtered rentals') !!}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="ops-section-label">Breakdown Summaries</h2>
        <div class="ops-three-col">
            <div class="ops-panel">
                <div class="ops-panel-head">
                    <div>
                        <h2>City-wise Breakdown</h2>
                        <p>Click a city to open the filtered rentals list.</p>
                    </div>
                </div>
                <div class="ops-panel-body">
                    @if($citySummary->isEmpty())
                        <div class="ops-empty">No city-wise data found for the current filter set.</div>
                    @else
                        <div class="ops-breakdown-list">
                            @foreach($citySummary as $row)
                                <div class="ops-breakdown-row">
                                    <div>
                                        <a href="{{ $rentalIndexUrl(['city' => $row['label'] !== 'Unspecified' ? $row['label'] : null]) }}">{{ $row['label'] }}</a>
                                        <small>{{ $currency($row['total_amount']) }}</small>
                                    </div>
                                    <strong>{{ $row['count'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="ops-panel">
                <div class="ops-panel-head">
                    <div>
                        <h2>Vendor-wise Breakdown</h2>
                        <p>Assigned delivery and pickup responsibility rollup.</p>
                    </div>
                </div>
                <div class="ops-panel-body">
                    @if($vendorSummary->isEmpty())
                        <div class="ops-empty">No vendor summary available for the current filter set.</div>
                    @else
                        <div class="ops-breakdown-list">
                            @foreach($vendorSummary as $row)
                                <div class="ops-breakdown-row">
                                    <div>
                                        <a href="{{ $rentalIndexUrl(['vendor_id' => $row['vendor_id']]) }}">{{ $row['label'] }}</a>
                                        <small>Open assigned rentals</small>
                                    </div>
                                    <strong>{{ $row['count'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="ops-panel">
                <div class="ops-panel-head">
                    <div>
                        <h2>Warehouse-wise Breakdown</h2>
                        <p>Dispatch source summary plus recent rental dates.</p>
                    </div>
                </div>
                <div class="ops-panel-body" style="display:grid; gap:14px;">
                    @if($warehouseSummary->isEmpty())
                        <div class="ops-empty">No warehouse-linked rentals in the current filter set.</div>
                    @else
                        <div class="ops-breakdown-list">
                            @foreach($warehouseSummary as $row)
                                <div class="ops-breakdown-row">
                                    <div>
                                        <a href="{{ $rentalIndexUrl(['dispatch_warehouse_id' => $row['warehouse_id']]) }}">{{ $row['label'] }}</a>
                                        <small>Warehouse drilldown</small>
                                    </div>
                                    <strong>{{ $row['count'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($dateSummary->isNotEmpty())
                        <div style="border-top:1px solid #e2e8f0; padding-top:12px;">
                            <table class="ops-date-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($dateSummary as $row)
                                        <tr>
                                            <td>
                                                <a href="{{ $rentalIndexUrl(['from_date' => $row->rental_date, 'to_date' => $row->rental_date]) }}" style="color:#0f172a; text-decoration:none; font-weight:600;">
                                                    {{ \Carbon\Carbon::parse($row->rental_date)->format('d M Y') }}
                                                </a>
                                            </td>
                                            <td>{{ $row->aggregate }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="ops-two-col">
        <div class="ops-panel">
            <div class="ops-panel-head">
                <div>
                    <h2>Rental Alerts List</h2>
                    <p>Priority rentals that need immediate operational attention.</p>
                </div>
            </div>
            <div class="ops-panel-body" style="display:grid; gap:14px;">
                <div>
                    <div class="ops-section-label" style="margin-bottom:8px;">Ending Soon</div>
                    @if($endingSoonRentals->isEmpty())
                        <div class="ops-empty">No ending-soon rentals for the current filter set.</div>
                    @else
                        <div class="ops-list">
                            @foreach($endingSoonRentals as $rental)
                                <a href="{{ route('rentals.show', $rental) }}" style="text-decoration:none; color:inherit;">
                                    <div class="ops-list-item">
                                        <div class="ops-list-top">
                                            <div>
                                                <h3 class="ops-list-title">#{{ $rental->id }} - {{ $rental->customer_name }}</h3>
                                                <p class="ops-list-subtitle">{{ $rental->product->name ?? 'Product N/A' }}</p>
                                            </div>
                                            <span class="ops-badge" style="{{ $statusBadge('warning') }}">Due {{ optional($rental->end_date)->format('d M') }}</span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <div class="ops-section-label" style="margin-bottom:8px;">Overdue</div>
                    @if($overdueRentals->isEmpty())
                        <div class="ops-empty">No overdue rentals in the current live lifecycle.</div>
                    @else
                        <div class="ops-list">
                            @foreach($overdueRentals as $rental)
                                <a href="{{ route('rentals.show', $rental) }}" style="text-decoration:none; color:inherit;">
                                    <div class="ops-list-item">
                                        <div class="ops-list-top">
                                            <div>
                                                <h3 class="ops-list-title">#{{ $rental->id }} - {{ $rental->customer_name }}</h3>
                                                <p class="ops-list-subtitle">{{ $rental->product->name ?? 'Product N/A' }}</p>
                                            </div>
                                            <span class="ops-badge" style="{{ $statusBadge('overdue') }}">Ended {{ optional($rental->end_date)->format('d M') }}</span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <div class="ops-section-label" style="margin-bottom:8px;">Returns Due Today</div>
                    @if($returnsDueToday->isEmpty())
                        <div class="ops-empty">No returns due today for the current filter set.</div>
                    @else
                        <div class="ops-list">
                            @foreach($returnsDueToday as $rental)
                                <a href="{{ route('rentals.show', $rental) }}" style="text-decoration:none; color:inherit;">
                                    <div class="ops-list-item">
                                        <div class="ops-list-top">
                                            <div>
                                                <h3 class="ops-list-title">#{{ $rental->id }} - {{ $rental->customer_name }}</h3>
                                                <p class="ops-list-subtitle">{{ $rental->product->name ?? 'Product N/A' }}</p>
                                            </div>
                                            <span class="ops-badge" style="{{ $statusBadge('info') }}">Today</span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="ops-panel">
            <div class="ops-panel-head">
                <div>
                    <h2>Recent / Actionable Rentals</h2>
                    <p>Compact operational list with quick actions for staff.</p>
                </div>
                <a href="{{ $rentalIndexUrl() }}" class="ops-btn-secondary" style="min-height:32px;padding:6px 10px;">Open Full List</a>
            </div>
            <div class="ops-panel-body">
                @if($recentRentals->isEmpty())
                    <div class="ops-empty">No rentals found for the current filter set.</div>
                @else
                    <div class="ops-list">
                        @foreach($recentRentals as $rental)
                            @php
                                $deliveryStatusValue = $rental->deliveryStatus();
                                $pickupStatusValue = $rental->pickupStatus();
                                $assetSummary = ($rental->activeRentalAssets ?? collect())->pluck('asset.serial_number')->filter()->implode(', ');
                                $hasOpenDeliveryTask = in_array($deliveryStatusValue, ['pending', 'in_progress'], true);
                                $hasOpenPickupTask = in_array($pickupStatusValue, ['pending', 'in_progress'], true);
                            @endphp
                            <div class="ops-list-item">
                                <div class="ops-list-top">
                                    <div>
                                        <h3 class="ops-list-title">#{{ $rental->id }} - {{ $rental->customer_name }}</h3>
                                        <p class="ops-list-subtitle">
                                            {{ $rental->product->name ?? 'Product N/A' }}
                                            @if(optional($rental->customer)->city)
                                                | {{ $rental->customer->city }}
                                            @endif
                                            @if($rental->phone)
                                                | {{ $rental->phone }}
                                            @endif
                                        </p>
                                    </div>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <span class="ops-badge" style="{{ $statusBadge($rental->isOverdue() ? 'overdue' : $rental->status) }}">
                                            {{ $rental->isOverdue() ? 'Overdue' : ucfirst($rental->status) }}
                                        </span>
                                        <span class="ops-badge" style="{{ $statusBadge($deliveryStatusValue) }}">
                                            Delivery {{ ucfirst(str_replace('_', ' ', $deliveryStatusValue ?: 'pending')) }}
                                        </span>
                                        <span class="ops-badge" style="{{ $statusBadge($pickupStatusValue) }}">
                                            Pickup {{ ucfirst(str_replace('_', ' ', $pickupStatusValue ?: 'pending')) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="ops-mini-meta">
                                    <span>{{ optional($rental->start_date)->format('d M Y') }} to {{ optional($rental->end_date)->format('d M Y') }}</span>
                                    <span>{{ $rental->dispatchWarehouse->name ?? 'Any warehouse' }}</span>
                                    <span>{{ $assetSummary ?: 'No assigned assets yet' }}</span>
                                </div>

                                <div class="ops-mini-actions">
                                    <a href="{{ route('rentals.show', $rental) }}" class="ops-btn-secondary">View</a>

                                    @if(auth()->user()->canManageRentals() && $rental->status !== 'returned')
                                        <a href="{{ route('rentals.edit', $rental) }}" class="ops-btn-secondary">Edit</a>
                                    @endif

                                    @if(!$hasOpenDeliveryTask)
                                        <a href="{{ route('deliveries.create', ['rental_id' => $rental->id]) }}" class="ops-btn-secondary">Assign Delivery</a>
                                    @endif

                                    @if($deliveryStatusValue === 'pending' && $rental->deliveryRecord)
                                        <form method="POST" action="{{ route('deliveries.in_progress', $rental->deliveryRecord) }}" style="margin:0;">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ops-btn-secondary">Start Delivery</button>
                                        </form>
                                    @elseif($deliveryStatusValue === 'in_progress' && $rental->deliveryRecord)
                                        <form method="POST" action="{{ route('deliveries.complete', $rental->deliveryRecord) }}" style="margin:0;">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ops-btn-success">Complete Delivery</button>
                                        </form>
                                    @endif

                                    @if($pickupStatusValue === 'pending' && $rental->pickupRecord)
                                        <form method="POST" action="{{ route('deliveries.in_progress', $rental->pickupRecord) }}" style="margin:0;">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ops-btn-secondary">Assign Pickup</button>
                                        </form>
                                    @elseif($pickupStatusValue === 'in_progress' && $rental->pickupRecord)
                                        <form method="POST" action="{{ route('deliveries.complete', $rental->pickupRecord) }}" style="margin:0;">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ops-btn-success">Complete Pickup</button>
                                        </form>
                                    @elseif($deliveryStatusValue === 'completed' && !$hasOpenPickupTask)
                                        <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="ops-btn-secondary">Assign Pickup</a>
                                    @endif

                                    @if($rental->canBeReturned())
                                        <form action="{{ route('rentals.return', $rental) }}" method="POST" style="margin:0;">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ops-btn-secondary" onclick="return confirm('Mark this rental as returned?');">Return</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
