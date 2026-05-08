@extends('layouts.app')

@section('content')
@php
    $currency = fn ($value) => 'Rs. ' . number_format((float) $value, 2);

    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? [];
    $summaryCards = $summaryCards ?? [];
    $reportGroups = $reportGroups ?? [];
    $exportUrl = $exportUrl ?? route('reports.export.csv', $filters);

    $cities = collect($filterOptions['cities'] ?? []);
    $vendors = collect($filterOptions['vendors'] ?? []);
    $warehouses = collect($filterOptions['warehouses'] ?? []);
    $customers = collect($filterOptions['customers'] ?? []);
    $products = collect($filterOptions['products'] ?? []);

    $rentalReports = $reportGroups['rental_reports'] ?? [];
    $collectionReports = $reportGroups['collection_reports'] ?? [];
    $customerReports = $reportGroups['customer_reports'] ?? [];
    $inventoryReports = $reportGroups['inventory_reports'] ?? [];
    $salesInvoiceReports = $reportGroups['sales_invoice_reports'] ?? [];

    $activeFilters = collect($filters)
        ->filter(fn ($value) => filled($value))
        ->all();

    $reportUrl = function (array $overrides = []) use ($activeFilters) {
        $query = array_merge($activeFilters, $overrides);

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return route('reports.index', $query);
    };

    $rentalsUrl = fn (array $overrides = []) => route('rentals.index', array_filter($overrides, fn ($value) => $value !== null && $value !== ''));
    $assetsUrl = fn (array $overrides = []) => route('assets.index', array_filter($overrides, fn ($value) => $value !== null && $value !== ''));
    $invoicesUrl = fn (array $overrides = []) => route('invoices.index', array_filter($overrides, fn ($value) => $value !== null && $value !== ''));
    $salesUrl = fn (array $overrides = []) => route('sales.index', array_filter($overrides, fn ($value) => $value !== null && $value !== ''));
    $paymentsUrl = \Illuminate\Support\Facades\Route::has('payments.index') ? route('payments.index') : null;
    $customerUrl = fn ($customerId = null) => $customerId ? route('customers.show', $customerId) : route('customers.index');
    $productUrl = fn ($productId = null) => $productId ? route('products.show', $productId) : route('products.index');

    $cardTheme = function (string $key) {
        return match ($key) {
            'overdue_rentals', 'outstanding_dues', 'maintenance_assets' => ['bg' => '#fff1f2', 'border' => '#fecdd3', 'accent' => '#b91c1c'],
            'payments_today', 'payments_this_month', 'available_assets', 'active_rentals' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'accent' => '#166534'],
            'invoices_this_month', 'rented_assets' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'accent' => '#1d4ed8'],
            'unpaid_invoices', 'overdue_invoices', 'ending_soon' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'accent' => '#a16207'],
            default => ['bg' => '#ffffff', 'border' => '#dbe3ef', 'accent' => '#0f172a'],
        };
    };

    $formatMetric = function ($key, $value) use ($currency) {
        $currencyKeys = ['payments_today', 'payments_this_month', 'outstanding_dues'];

        if (in_array($key, $currencyKeys, true)) {
            return $currency($value);
        }

        return is_numeric($value) ? number_format((float) $value, ((float) $value === floor((float) $value)) ? 0 : 2) : $value;
    };
    $reportIcon = function (string $key): string {
        return match ($key) {
            'active_rentals' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1Z"/><path d="M8 12h4"/><path d="M8 16h8"/></svg>',
            'available_assets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 7.5 12 3 3.5 7.5 12 12l8.5-4.5Z"/><path d="M3.5 7.5V16L12 21l8.5-5V7.5"/><path d="M12 12v9"/></svg>',
            'overdue_rentals', 'overdue_invoices', 'ending_soon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><path d="m12 16 .01 0"/><circle cx="12" cy="12" r="9"/></svg>',
            'payments_today', 'payments_this_month', 'outstanding_dues', 'unpaid_invoices' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14.5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'rented_assets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.5"/><circle cx="18" cy="18" r="1.5"/></svg>',
            'maintenance_assets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m14.7 6.3 3 3"/><path d="m6.5 14.5 7.2-7.2 3 3-7.2 7.2L6 18l.5-3.5Z"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        };
    };

    $rentalMetrics = $rentalReports['metrics'] ?? [];
@endphp

<style>
    .reports-shell { display:grid; gap:16px; padding:18px 22px 28px; }
    .reports-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; }
    .reports-header h1 { margin:0; font-size:28px; line-height:1.08; color:#0f172a; }
    .reports-header p { margin:6px 0 0; color:#64748b; font-size:13px; max-width:860px; }
    .reports-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .reports-section-label { margin:0; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.08em; }
    .reports-card { background:#fff; border:1px solid #dbe3ef; border-radius:16px; box-shadow:0 8px 24px rgba(15,23,42,0.04); }
    .reports-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; padding:14px 16px; border-bottom:1px solid #e2e8f0; }
    .reports-card-head h2 { margin:0; font-size:16px; color:#0f172a; }
    .reports-card-head p { margin:4px 0 0; color:#64748b; font-size:12px; }
    .reports-card-body { padding:14px 16px; }
    .reports-btn,
    .reports-btn-secondary,
    .reports-btn-success {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:36px; padding:8px 12px; border-radius:10px; font-size:13px; font-weight:600;
        text-decoration:none; border:1px solid transparent; cursor:pointer;
    }
    .reports-btn { background:#0f172a; color:#fff; }
    .reports-btn-secondary { background:#fff; color:#334155; border-color:#cbd5e1; }
    .reports-btn-success { background:#16a34a; color:#fff; }
    .reports-actions-inline { display:flex; gap:8px; flex-wrap:wrap; }
    .reports-filter-grid { display:grid; grid-template-columns:repeat(8, minmax(0, 1fr)); gap:10px; }
    .reports-field { display:grid; gap:5px; }
    .reports-field label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .reports-input,
    .reports-select {
        width:100%; min-height:38px; padding:8px 11px; border:1px solid #cbd5e1; border-radius:10px;
        font-size:13px; color:#0f172a; background:#fff;
    }
    .reports-filter-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:12px; }
    .reports-grid-4 { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .reports-grid-3 { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
    .reports-grid-2 { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
    .reports-summary-card,
    .reports-metric-tile {
        display:flex; flex-direction:column; gap:7px; min-height:96px;
        padding:11px 12px; border:1px solid; border-radius:14px;
        text-decoration:none; color:inherit; transition:transform .15s ease, box-shadow .15s ease;
        box-shadow:0 8px 22px rgba(15,23,42,0.03);
    }
    .reports-summary-card:hover,
    .reports-metric-tile:hover { transform:translateY(-1px); box-shadow:0 12px 26px rgba(15,23,42,0.07); }
    .reports-summary-card span,
    .reports-metric-tile span { display:block; max-width:calc(100% - 44px); font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; line-height:1.35; overflow-wrap:anywhere; text-wrap:balance; }
    .reports-summary-card strong,
    .reports-metric-tile strong { display:block; max-width:100%; font-size:21px; line-height:1.08; overflow-wrap:anywhere; }
    .reports-summary-card small,
    .reports-metric-tile small { color:#64748b; font-size:12px; line-height:1.5; overflow-wrap:anywhere; }
    .reports-summary-top,
    .reports-metric-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .reports-summary-icon {
        width:34px; height:34px; flex:0 0 34px; display:grid; place-items:center;
        border-radius:12px; background:rgba(255,255,255,.7); border:1px solid rgba(255,255,255,.8); color:inherit;
        box-shadow:0 10px 20px rgba(15,23,42,.04);
    }
    .reports-summary-icon svg { width:16px; height:16px; }
    .reports-kpi-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:10px; }
    .reports-table-wrap { overflow-x:auto; }
    .reports-table { width:100%; border-collapse:collapse; }
    .reports-table th,
    .reports-table td { padding:10px 8px; border-bottom:1px solid #e2e8f0; text-align:left; font-size:13px; vertical-align:top; }
    .reports-table th { color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
    .reports-table a { color:#0f172a; text-decoration:none; font-weight:600; }
    .reports-table a:hover { color:#1d4ed8; }
    .reports-empty { color:#64748b; font-size:13px; padding:8px 0; }
    .reports-pill {
        display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px;
        font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    }
    .pill-success { background:#dcfce7; color:#166534; }
    .pill-warning { background:#fffbeb; color:#a16207; }
    .pill-danger { background:#fee2e2; color:#b91c1c; }
    .pill-info { background:#eff6ff; color:#1d4ed8; }
    .pill-default { background:#f1f5f9; color:#475569; }
    .reports-list { display:grid; gap:10px; }
    .reports-list-item {
        display:grid; gap:6px; padding:12px; border:1px solid #e2e8f0;
        border-radius:12px; background:#fcfdff;
    }
    .reports-list-head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; flex-wrap:wrap; }
    .reports-list-title { margin:0; font-size:14px; color:#0f172a; }
    .reports-list-subtitle { margin:0; font-size:12px; color:#64748b; }
    .reports-list-meta { display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:#64748b; }
    .reports-breakdown-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
    .reports-breakdown-card { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fff; }
    .reports-breakdown-card h3 { margin:0 0 10px; font-size:13px; color:#0f172a; }
    .reports-breakdown-row {
        display:flex; justify-content:space-between; align-items:center; gap:10px;
        padding:9px 0; border-bottom:1px solid #eef2f7;
    }
    .reports-breakdown-row:last-child { border-bottom:none; padding-bottom:0; }
    .reports-breakdown-row a { color:#0f172a; text-decoration:none; font-weight:600; }
    .reports-trend-list { display:grid; gap:8px; }
    .reports-trend-row {
        display:flex; justify-content:space-between; align-items:center; gap:10px;
        border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; background:#fff;
    }
    .reports-soft-note {
        display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
        background:#f8fafc; border:1px solid #e2e8f0; color:#475569; font-size:12px; font-weight:600;
    }
    @media (max-width: 1280px) {
        .reports-filter-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); }
        .reports-grid-4,
        .reports-kpi-grid,
        .reports-breakdown-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 920px) {
        .reports-grid-2,
        .reports-grid-3,
        .reports-grid-4,
        .reports-kpi-grid,
        .reports-breakdown-grid { grid-template-columns:1fr; }
        .reports-filter-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
        .reports-shell { padding:14px; }
        .reports-filter-grid { grid-template-columns:1fr; }
        .reports-filter-actions,
        .reports-header-actions,
        .reports-actions-inline { flex-direction:column; align-items:stretch; }
        .reports-summary-card span,
        .reports-metric-tile span { font-size:11px; }
        .reports-summary-card strong,
        .reports-metric-tile strong { font-size:22px; }
    }
</style>

<div class="container reports-shell rn-list-page rn-report-shell">
    <div class="reports-header">
        <div>
            <div class="rx-eyebrow">Analytics</div>
            <h1 class="rn-report-title">Reports & Insights</h1>
            <p class="rn-report-subtitle">Compact management dashboard for rentals, collections, customers, inventory, and invoice performance. Each section stays filter-aware and ready for drilldown.</p>
        </div>

        <div class="reports-header-actions">
            <a href="{{ route('dashboard') }}" class="reports-btn-secondary">Operations Dashboard</a>
            <a href="{{ $exportUrl }}" class="reports-btn">Export CSV</a>
        </div>
    </div>

    <details class="reports-card rn-card">
        <summary class="reports-card-head" style="cursor:pointer; list-style:none;">
            <div>
                <h2>Top Filter Bar</h2>
                <p>Apply a management slice once and use it across every card, table, and export.</p>
            </div>
        </summary>
        <div class="reports-card-body">
            <form method="GET" action="{{ route('reports.index') }}">
                <div class="reports-filter-grid">
                    <div class="reports-field">
                        <label for="from_date">From Date</label>
                        <input id="from_date" class="reports-input" type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}">
                    </div>

                    <div class="reports-field">
                        <label for="to_date">To Date</label>
                        <input id="to_date" class="reports-input" type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}">
                    </div>

                    <div class="reports-field">
                        <label for="city">City</label>
                        <select id="city" class="reports-select" name="city">
                            <option value="">All Cities</option>
                            @foreach($cities as $city)
                                <option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="reports-field">
                        <label for="vendor_id">Vendor</label>
                        <select id="vendor_id" class="reports-select" name="vendor_id">
                            <option value="">All Vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) ($filters['vendor_id'] ?? '') === (string) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="reports-field">
                        <label for="warehouse_id">Warehouse</label>
                        <select id="warehouse_id" class="reports-select" name="warehouse_id">
                            <option value="">All Warehouses</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="reports-field">
                        <label for="customer_id">Customer</label>
                        <select id="customer_id" class="reports-select" name="customer_id">
                            <option value="">All Customers</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected((string) ($filters['customer_id'] ?? '') === (string) $customer->id)>{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="reports-field">
                        <label for="product_id">Product</label>
                        <select id="product_id" class="reports-select" name="product_id">
                            <option value="">All Products</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="reports-field">
                        <label for="report">Quick Focus</label>
                        <select id="report" class="reports-select" name="report">
                            <option value="">Overview</option>
                            <option value="active_rentals" @selected(($filters['report'] ?? '') === 'active_rentals')>Active Rentals</option>
                            <option value="overdue_rentals" @selected(($filters['report'] ?? '') === 'overdue_rentals')>Overdue Rentals</option>
                            <option value="customer_outstanding" @selected(($filters['report'] ?? '') === 'customer_outstanding')>Customer Outstanding</option>
                            <option value="available_assets" @selected(($filters['report'] ?? '') === 'available_assets')>Available Assets</option>
                            <option value="product_utilization" @selected(($filters['report'] ?? '') === 'product_utilization')>Product Utilization</option>
                            <option value="unpaid_invoices" @selected(($filters['report'] ?? '') === 'unpaid_invoices')>Unpaid Invoices</option>
                        </select>
                    </div>
                </div>

                <div class="reports-filter-actions">
                    <button type="submit" class="reports-btn">Apply Filters</button>
                    <a href="{{ route('reports.index') }}" class="reports-btn-secondary">Reset</a>
                    <a href="{{ $exportUrl }}" class="reports-btn-secondary">Export Current View</a>
                </div>
            </form>
        </div>
    </details>

    <div style="display:grid; gap:10px;">
        <h2 class="reports-section-label">Summary Cards</h2>
        <div class="reports-grid-4">
            @foreach($summaryCards as $card)
                @php
                    $theme = $cardTheme($card['key'] ?? 'default');
                    $cardHref = $card['url'] ?? null;
                @endphp
                @if(!empty($cardHref))
                    <a href="{{ $cardHref }}" class="reports-summary-card rn-card" style="background:{{ $theme['bg'] }}; border-color:{{ $theme['border'] }};">
                        <div class="reports-summary-top">
                            <span>{{ $card['label'] ?? 'Metric' }}</span>
                            <span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon($card['key'] ?? 'default') !!}</span>
                        </div>
                        <strong style="color:{{ $theme['accent'] }};">{{ $formatMetric($card['key'] ?? 'default', $card['value'] ?? 0) }}</strong>
                        <small>Open related detail</small>
                    </a>
                @else
                    <div class="reports-summary-card rn-card" style="background:{{ $theme['bg'] }}; border-color:{{ $theme['border'] }};">
                        <div class="reports-summary-top">
                            <span>{{ $card['label'] ?? 'Metric' }}</span>
                            <span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon($card['key'] ?? 'default') !!}</span>
                        </div>
                        <strong style="color:{{ $theme['accent'] }};">{{ $formatMetric($card['key'] ?? 'default', $card['value'] ?? 0) }}</strong>
                        <small>Open related detail</small>
                    </div>
                @endif
            @endforeach

            @php($theme = $cardTheme('rented_assets'))
            <a href="{{ $assetsUrl(['asset_status' => 'rented', 'warehouse_id' => $filters['warehouse_id'] ?? null, 'product_id' => $filters['product_id'] ?? null]) }}" class="reports-summary-card rn-card" style="background:{{ $theme['bg'] }}; border-color:{{ $theme['border'] }};">
                <div class="reports-summary-top">
                    <span>Rented Assets</span>
                    <span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('rented_assets') !!}</span>
                </div>
                <strong style="color:{{ $theme['accent'] }};">{{ number_format((float) ($inventoryReports['rented_assets'] ?? 0), 0) }}</strong>
                <small>Track assets in field use</small>
            </a>

            @php($theme = $cardTheme('maintenance_assets'))
            <a href="{{ $assetsUrl(['asset_status' => 'maintenance', 'warehouse_id' => $filters['warehouse_id'] ?? null]) }}" class="reports-summary-card rn-card" style="background:{{ $theme['bg'] }}; border-color:{{ $theme['border'] }};">
                <div class="reports-summary-top">
                    <span>Maintenance Assets</span>
                    <span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('maintenance_assets') !!}</span>
                </div>
                <strong style="color:{{ $theme['accent'] }};">{{ number_format((float) ($inventoryReports['maintenance_assets'] ?? 0), 0) }}</strong>
                <small>Review service workload</small>
            </a>

            @php($theme = $cardTheme('unpaid_invoices'))
            <a href="{{ $invoicesUrl(['status' => 'unpaid', 'customer_id' => $filters['customer_id'] ?? null]) }}" class="reports-summary-card rn-card" style="background:{{ $theme['bg'] }}; border-color:{{ $theme['border'] }};">
                <div class="reports-summary-top">
                    <span>Unpaid Invoices</span>
                    <span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('unpaid_invoices') !!}</span>
                </div>
                <strong style="color:{{ $theme['accent'] }};">{{ number_format((float) ($salesInvoiceReports['unpaid_invoices'] ?? 0), 0) }}</strong>
                <small>Open invoice follow-up list</small>
            </a>
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="reports-section-label">Rental Reports</h2>
        <div class="reports-card rn-card">
            <div class="reports-card-head">
                <div>
                    <h2>Rental Health & Distribution</h2>
                    <p>Monitor lifecycle load, overdue pressure, and where rentals are concentrated.</p>
                </div>
                <div class="reports-actions-inline">
                    <a href="{{ route('reports.export.csv', array_merge($activeFilters, ['report' => 'active_rentals'])) }}" class="reports-btn-secondary">Export Active</a>
                    <a href="{{ route('reports.export.csv', array_merge($activeFilters, ['report' => 'overdue_rentals'])) }}" class="reports-btn-secondary">Export Overdue</a>
                </div>
            </div>
            <div class="reports-card-body" style="display:grid; gap:16px;">
                <div class="reports-kpi-grid">
                    <a href="{{ $rentalsUrl(['status' => 'active']) }}" class="reports-metric-tile" style="background:#f0fdf4;border-color:#bbf7d0;">
                        <div class="reports-metric-top"><span>Active Rentals</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('active_rentals') !!}</span></div>
                        <strong style="color:#166534;">{{ number_format((float) ($rentalMetrics['activeRentals'] ?? 0), 0) }}</strong>
                        <small>Open live rentals</small>
                    </a>
                    <a href="{{ $rentalsUrl(['status' => 'returned']) }}" class="reports-metric-tile" style="background:#ffffff;border-color:#dbe3ef;">
                        <div class="reports-metric-top"><span>Returned Rentals</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('active_rentals') !!}</span></div>
                        <strong style="color:#0f172a;">{{ number_format((float) ($rentalMetrics['returnedRentals'] ?? 0), 0) }}</strong>
                        <small>Review completed rentals</small>
                    </a>
                    <a href="{{ $rentalsUrl(['filter' => 'overdue']) }}" class="reports-metric-tile" style="background:#fff1f2;border-color:#fecdd3;">
                        <div class="reports-metric-top"><span>Overdue Rentals</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('overdue_rentals') !!}</span></div>
                        <strong style="color:#b91c1c;">{{ number_format((float) ($rentalMetrics['overdueRentals'] ?? 0), 0) }}</strong>
                        <small>Immediate operational follow-up</small>
                    </a>
                    <a href="{{ $rentalsUrl(['filter' => 'ending_soon']) }}" class="reports-metric-tile" style="background:#fffbeb;border-color:#fde68a;">
                        <div class="reports-metric-top"><span>Ending Soon</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('ending_soon') !!}</span></div>
                        <strong style="color:#a16207;">{{ number_format((float) ($rentalMetrics['endingSoonRentals'] ?? 0), 0) }}</strong>
                        <small>Prepare renewals or returns</small>
                    </a>
                </div>

                <div class="reports-breakdown-grid">
                    <div class="reports-breakdown-card rn-card">
                        <h3>City-wise Rentals</h3>
                        @forelse(collect($rentalReports['city_wise'] ?? []) as $row)
                            <div class="reports-breakdown-row">
                                <a href="{{ $reportUrl(['city' => $row->label !== 'Unspecified' ? $row->label : null]) }}">{{ $row->label }}</a>
                                <strong>{{ $row->total }}</strong>
                            </div>
                        @empty
                            <div class="reports-empty">No city-wise rental summary available.</div>
                        @endforelse
                    </div>

                    <div class="reports-breakdown-card rn-card">
                        <h3>Vendor-wise Rentals</h3>
                        @forelse(collect($rentalReports['vendor_wise'] ?? []) as $row)
                            <div class="reports-breakdown-row">
                                <span>{{ $row->label }}</span>
                                <strong>{{ $row->total }}</strong>
                            </div>
                        @empty
                            <div class="reports-empty">No vendor-wise rental summary available.</div>
                        @endforelse
                    </div>

                    <div class="reports-breakdown-card rn-card">
                        <h3>Warehouse-wise Rentals</h3>
                        @forelse(collect($rentalReports['warehouse_wise'] ?? []) as $row)
                            <div class="reports-breakdown-row">
                                <span>{{ $row->label }}</span>
                                <strong>{{ $row->total }}</strong>
                            </div>
                        @empty
                            <div class="reports-empty">No warehouse-wise rental summary available.</div>
                        @endforelse
                    </div>
                </div>

                <div class="reports-grid-2">
                    <div class="reports-card rn-card" style="box-shadow:none;">
                        <div class="reports-card-head">
                            <div>
                                <h2>Product-wise Rentals</h2>
                                <p>Most active products and quantity movement.</p>
                            </div>
                        </div>
                        <div class="reports-card-body">
                            <div class="reports-table-wrap rn-table-shell">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Rentals</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse(collect($rentalReports['product_wise'] ?? []) as $row)
                                            <tr>
                                                <td>{{ $row->label }}</td>
                                                <td>{{ $row->total }}</td>
                                                <td>{{ $row->quantity_total }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3"><div class="reports-empty">No product-wise rental summary available.</div></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="reports-card rn-card" style="box-shadow:none;">
                        <div class="reports-card-head">
                            <div>
                                <h2>Monthly Rentals Trend</h2>
                                <p>Quick trend view for rental volume across months.</p>
                            </div>
                        </div>
                        <div class="reports-card-body">
                            <div class="reports-trend-list">
                                @forelse(collect($rentalReports['monthly_trend'] ?? []) as $row)
                                    <div class="reports-trend-row">
                                        <strong>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $row['month'])->format('M Y') }}</strong>
                                        <span>{{ $row['value'] }} rentals</span>
                                    </div>
                                @empty
                                    <div class="reports-empty">No rental trend available.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="reports-section-label">Collections & Payments</h2>
        <div class="reports-card rn-card">
            <div class="reports-card-head">
                <div>
                    <h2>Collection Performance</h2>
                    <p>Keep receipts, pending collections, and overdue billing visible from one place.</p>
                </div>
                <div class="reports-actions-inline">
                    <span class="reports-soft-note">Finance follow-up friendly</span>
                </div>
            </div>
            <div class="reports-card-body" style="display:grid; gap:16px;">
                <div class="reports-kpi-grid">
                    @if($paymentsUrl)
                    <a href="{{ $paymentsUrl }}" class="reports-metric-tile" style="background:#f0fdf4;border-color:#bbf7d0;">
                        <span>Payments Today</span>
                        <strong style="color:#166534;">{{ $currency($collectionReports['payments_today'] ?? 0) }}</strong>
                        <small>Open payments register</small>
                    </a>
                    @else
                    <div class="reports-metric-tile" style="background:#f0fdf4;border-color:#bbf7d0;">
                        <span>Payments Today</span>
                        <strong style="color:#166534;">{{ $currency($collectionReports['payments_today'] ?? 0) }}</strong>
                        <small>Payments register route not configured</small>
                    </div>
                    @endif
                    @if($paymentsUrl)
                    <a href="{{ $paymentsUrl }}" class="reports-metric-tile" style="background:#eff6ff;border-color:#bfdbfe;">
                        <span>Payments This Month</span>
                        <strong style="color:#1d4ed8;">{{ $currency($collectionReports['payments_this_month'] ?? 0) }}</strong>
                        <small>Month collections summary</small>
                    </a>
                    @else
                    <div class="reports-metric-tile" style="background:#eff6ff;border-color:#bfdbfe;">
                        <span>Payments This Month</span>
                        <strong style="color:#1d4ed8;">{{ $currency($collectionReports['payments_this_month'] ?? 0) }}</strong>
                        <small>Payments register route not configured</small>
                    </div>
                    @endif
                    <a href="{{ $invoicesUrl(['status' => 'unpaid']) }}" class="reports-metric-tile" style="background:#fff1f2;border-color:#fecdd3;">
                        <span>Outstanding Dues</span>
                        <strong style="color:#b91c1c;">{{ $currency($collectionReports['outstanding_dues'] ?? 0) }}</strong>
                        <small>Open due invoices</small>
                    </a>
                    <a href="{{ $invoicesUrl(['status' => 'overdue']) }}" class="reports-metric-tile" style="background:#fffbeb;border-color:#fde68a;">
                        <span>Overdue Invoices</span>
                        <strong style="color:#a16207;">{{ number_format((float) ($collectionReports['overdue_invoices'] ?? 0), 0) }}</strong>
                        <small>Urgent recovery queue</small>
                    </a>
                </div>

                <div class="reports-grid-2">
                    <div class="reports-card rn-card" style="box-shadow:none;">
                        <div class="reports-card-head">
                            <div>
                                <h2>Customer-wise Outstanding</h2>
                                <p>Customers with unpaid amounts requiring follow-up.</p>
                            </div>
                        </div>
                        <div class="reports-card-body">
                            <div class="reports-table-wrap rn-table-shell">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Outstanding</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse(collect($collectionReports['customer_outstanding'] ?? []) as $row)
                                            <tr>
                                                <td><a href="{{ $customerUrl($row->customer_id ?? null) }}">{{ $row->label }}</a></td>
                                                <td>{{ $currency($row->total) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="2"><div class="reports-empty">No customer-wise outstanding found.</div></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="reports-card rn-card" style="box-shadow:none;">
                        <div class="reports-card-head">
                            <div>
                                <h2>Payment Mode Summary</h2>
                                <p>See how money is coming in across modes.</p>
                            </div>
                        </div>
                        <div class="reports-card-body">
                            <div class="reports-table-wrap">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Mode</th>
                                            <th>Count</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse(collect($collectionReports['payment_mode_summary'] ?? []) as $row)
                                            <tr>
                                                <td>{{ ucfirst(str_replace('_', ' ', $row->label)) }}</td>
                                                <td>{{ $row->total_count }}</td>
                                                <td>{{ $currency($row->amount_total) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3"><div class="reports-empty">No payment mode summary available.</div></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="reports-card rn-card" style="box-shadow:none;">
                    <div class="reports-card-head">
                        <div>
                            <h2>Monthly Collections Trend</h2>
                            <p>Simple month-level trend to support finance reviews.</p>
                        </div>
                    </div>
                    <div class="reports-card-body">
                        <div class="reports-trend-list">
                            @forelse(collect($collectionReports['monthly_trend'] ?? []) as $row)
                                <div class="reports-trend-row">
                                    <strong>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $row['month'])->format('M Y') }}</strong>
                                    <span>{{ $currency($row['value']) }}</span>
                                </div>
                            @empty
                                <div class="reports-empty">No collections trend available.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="reports-section-label">Customer Reports</h2>
        <div class="reports-grid-2">
            <div class="reports-card rn-card">
                <div class="reports-card-head">
                    <div>
                        <h2>Top & Repeat Customers</h2>
                        <p>Identify customers driving recurring business volume.</p>
                    </div>
                </div>
                <div class="reports-card-body">
                    <div class="reports-table-wrap">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Tag</th>
                                    <th>Rentals</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(collect($customerReports['top_customers'] ?? []) as $row)
                                    <tr>
                                        <td><a href="{{ $customerUrl($row->id) }}">{{ $row->name }}</a></td>
                                        <td><span class="reports-pill pill-info">Top</span></td>
                                        <td>{{ $row->rentals_count }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3"><div class="reports-empty">No top customer data available.</div></td>
                                    </tr>
                                @endforelse

                                @foreach(collect($customerReports['repeat_customers'] ?? [])->take(8) as $row)
                                    <tr>
                                        <td><a href="{{ $customerUrl($row->id) }}">{{ $row->name }}</a></td>
                                        <td><span class="reports-pill pill-success">Repeat</span></td>
                                        <td>{{ $row->rentals_count }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="reports-card rn-card">
                <div class="reports-card-head">
                    <div>
                        <h2>Inactive Customers</h2>
                        <p>Customers needing reactivation, follow-up, or remarketing attention.</p>
                    </div>
                </div>
                <div class="reports-card-body">
                    <div class="reports-list">
                        @forelse(collect($customerReports['inactive_customers'] ?? []) as $row)
                            <div class="reports-list-item">
                                <div class="reports-list-head">
                                    <div>
                                        <h3 class="reports-list-title"><a href="{{ $customerUrl($row->id) }}">{{ $row->name }}</a></h3>
                                        <p class="reports-list-subtitle">{{ $row->city ?: 'City not available' }}</p>
                                    </div>
                                    <span class="reports-pill pill-warning">Inactive</span>
                                </div>
                            </div>
                        @empty
                            <div class="reports-empty">No inactive customers matched the current filters.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="reports-grid-2">
            <div class="reports-card rn-card">
                <div class="reports-card-head">
                    <div>
                        <h2>New Customers by Month</h2>
                        <p>Acquisition visibility for growth and engagement tracking.</p>
                    </div>
                </div>
                <div class="reports-card-body">
                    <div class="reports-trend-list">
                        @forelse(collect($customerReports['new_customers_by_month'] ?? []) as $row)
                            <div class="reports-trend-row">
                                <strong>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $row['month'])->format('M Y') }}</strong>
                                <span>{{ $row['value'] }} customers</span>
                            </div>
                        @empty
                            <div class="reports-empty">No customer acquisition trend available.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="reports-card rn-card">
                <div class="reports-card-head">
                    <div>
                        <h2>City-wise Customer Count</h2>
                        <p>Customer spread by city for branch and market planning.</p>
                    </div>
                </div>
                <div class="reports-card-body">
                    @forelse(collect($customerReports['city_wise_counts'] ?? []) as $row)
                        <div class="reports-breakdown-row">
                            <a href="{{ route('customers.index', ['city' => $row->label !== 'Unspecified' ? $row->label : null]) }}">{{ $row->label }}</a>
                            <strong>{{ $row->total }}</strong>
                        </div>
                    @empty
                        <div class="reports-empty">No city-wise customer summary available.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="reports-section-label">Inventory & Assets</h2>
        <div class="reports-card rn-card">
            <div class="reports-card-head">
                <div>
                    <h2>Asset Utilization & Warehouse Stock</h2>
                    <p>Balance availability, rented load, idle stock, and warehouse positioning.</p>
                </div>
                <div class="reports-actions-inline">
                    <a href="{{ route('inventory.dashboard') }}" class="reports-btn-secondary">Inventory Dashboard</a>
                    <a href="{{ route('reports.export.csv', array_merge($activeFilters, ['report' => 'warehouse_stock_summary'])) }}" class="reports-btn-secondary">Export Stock</a>
                </div>
            </div>
            <div class="reports-card-body" style="display:grid; gap:16px;">
                <div class="reports-kpi-grid">
                    <a href="{{ $assetsUrl(['asset_status' => 'available']) }}" class="reports-metric-tile" style="background:#f0fdf4;border-color:#bbf7d0;">
                        <div class="reports-metric-top"><span>Available Assets</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('available_assets') !!}</span></div>
                        <strong style="color:#166534;">{{ number_format((float) ($inventoryReports['available_assets'] ?? 0), 0) }}</strong>
                        <small>Open ready stock</small>
                    </a>
                    <a href="{{ $assetsUrl(['asset_status' => 'rented']) }}" class="reports-metric-tile" style="background:#eff6ff;border-color:#bfdbfe;">
                        <div class="reports-metric-top"><span>Rented Assets</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('rented_assets') !!}</span></div>
                        <strong style="color:#1d4ed8;">{{ number_format((float) ($inventoryReports['rented_assets'] ?? 0), 0) }}</strong>
                        <small>Assets deployed in field</small>
                    </a>
                    <a href="{{ $assetsUrl(['asset_status' => 'maintenance']) }}" class="reports-metric-tile" style="background:#fff1f2;border-color:#fecdd3;">
                        <div class="reports-metric-top"><span>Maintenance Assets</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('maintenance_assets') !!}</span></div>
                        <strong style="color:#b91c1c;">{{ number_format((float) ($inventoryReports['maintenance_assets'] ?? 0), 0) }}</strong>
                        <small>Service and repair queue</small>
                    </a>
                    <a href="{{ $assetsUrl() }}" class="reports-metric-tile" style="background:#ffffff;border-color:#dbe3ef;">
                        <div class="reports-metric-top"><span>Idle Assets</span><span class="reports-summary-icon" aria-hidden="true">{!! $reportIcon('available_assets') !!}</span></div>
                        <strong style="color:#0f172a;">{{ collect($inventoryReports['idle_assets'] ?? [])->count() }}</strong>
                        <small>Review underutilized stock</small>
                    </a>
                </div>

                <div class="reports-grid-2">
                    <div class="reports-card rn-card" style="box-shadow:none;">
                        <div class="reports-card-head">
                            <div>
                                <h2>Warehouse Stock Summary</h2>
                                <p>Warehouse-level view of current stock holding.</p>
                            </div>
                        </div>
                        <div class="reports-card-body">
                            <div class="reports-table-wrap rn-table-shell">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Warehouse</th>
                                            <th>Total Assets</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse(collect($inventoryReports['warehouse_stock_summary'] ?? []) as $row)
                                            <tr>
                                                <td>{{ $row->label }}</td>
                                                <td>{{ $row->total }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="2"><div class="reports-empty">No warehouse stock summary available.</div></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="reports-card rn-card" style="box-shadow:none;">
                        <div class="reports-card-head">
                            <div>
                                <h2>Product Utilization</h2>
                                <p>Product demand versus asset pool and live rental usage.</p>
                            </div>
                        </div>
                        <div class="reports-card-body">
                            <div class="reports-table-wrap rn-table-shell">
                                <table class="reports-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Total Assets</th>
                                            <th>In Use</th>
                                            <th>Active Rentals</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse(collect($inventoryReports['product_utilization'] ?? []) as $row)
                                            <tr>
                                                <td><a href="{{ $productUrl($row->id) }}">{{ $row->name }}</a></td>
                                                <td>{{ $row->total_assets }}</td>
                                                <td>{{ $row->rented_assets }}</td>
                                                <td>{{ $row->active_rentals }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4"><div class="reports-empty">No product utilization data available.</div></td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="reports-card rn-card" style="box-shadow:none;">
                    <div class="reports-card-head">
                        <div>
                            <h2>Idle Assets</h2>
                            <p>Assets currently available but not heavily utilized.</p>
                        </div>
                    </div>
                    <div class="reports-card-body">
                        <div class="reports-table-wrap rn-table-shell">
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Asset</th>
                                        <th>Serial</th>
                                        <th>Warehouse</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse(collect($inventoryReports['idle_assets'] ?? []) as $asset)
                                        <tr>
                                            <td><a href="{{ route('assets.show', $asset->id) }}">{{ $asset->asset_name ?: ('Asset #' . $asset->id) }}</a></td>
                                            <td>{{ $asset->serial_number }}</td>
                                            <td>{{ optional($asset->warehouse)->name ?: 'Not assigned' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3"><div class="reports-empty">No idle assets flagged by current backend logic.</div></td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid; gap:10px;">
        <h2 class="reports-section-label">Sales & Invoice Reports</h2>
        <div class="reports-grid-2">
            <div class="reports-card">
                <div class="reports-card-head">
                    <div>
                        <h2>Invoice Status Summary</h2>
                        <p>Simple invoice status visibility for collections and management review.</p>
                    </div>
                </div>
                <div class="reports-card-body">
                    <div class="reports-kpi-grid" style="grid-template-columns:repeat(2, minmax(0, 1fr));">
                        <a href="{{ $invoicesUrl() }}" class="reports-metric-tile" style="background:#eff6ff;border-color:#bfdbfe;">
                            <span>Invoices This Month</span>
                            <strong style="color:#1d4ed8;">{{ number_format((float) ($salesInvoiceReports['invoices_this_month'] ?? 0), 0) }}</strong>
                            <small>Open invoice register</small>
                        </a>
                        <a href="{{ $invoicesUrl(['status' => 'unpaid']) }}" class="reports-metric-tile" style="background:#fffbeb;border-color:#fde68a;">
                            <span>Unpaid Invoices</span>
                            <strong style="color:#a16207;">{{ number_format((float) ($salesInvoiceReports['unpaid_invoices'] ?? 0), 0) }}</strong>
                            <small>Open unpaid invoice list</small>
                        </a>
                        <a href="{{ $invoicesUrl(['status' => 'partial']) }}" class="reports-metric-tile" style="background:#ffffff;border-color:#dbe3ef;">
                            <span>Partial Invoices</span>
                            <strong style="color:#0f172a;">{{ number_format((float) ($salesInvoiceReports['partial_invoices'] ?? 0), 0) }}</strong>
                            <small>Open part-paid invoices</small>
                        </a>
                        <a href="{{ $invoicesUrl(['status' => 'paid']) }}" class="reports-metric-tile" style="background:#f0fdf4;border-color:#bbf7d0;">
                            <span>Paid Invoices</span>
                            <strong style="color:#166534;">{{ number_format((float) ($salesInvoiceReports['paid_invoices'] ?? 0), 0) }}</strong>
                            <small>Open closed invoices</small>
                        </a>
                    </div>
                </div>
            </div>

            <div class="reports-card">
                <div class="reports-card-head">
                    <div>
                        <h2>Monthly Invoice Trend</h2>
                        <p>Month-wise billing movement for management review.</p>
                    </div>
                    <div class="reports-actions-inline">
                        <a href="{{ $salesUrl() }}" class="reports-btn-secondary">Open Sales</a>
                    </div>
                </div>
                <div class="reports-card-body">
                    <div class="reports-trend-list">
                        @forelse(collect($salesInvoiceReports['monthly_invoice_trend'] ?? []) as $row)
                            <div class="reports-trend-row">
                                <strong>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $row['month'])->format('M Y') }}</strong>
                                <span>{{ $row['value'] }} invoices</span>
                            </div>
                        @empty
                            <div class="reports-empty">No invoice trend available.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
