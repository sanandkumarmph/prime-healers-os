@extends('layouts.app')

@section('content')
@php
    $currency = fn ($value) => 'Rs. ' . number_format((float) $value, 2);
    $number = fn ($value, $decimals = 0) => number_format((float) $value, $decimals);
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? [];
    $reportGroups = $reportGroups ?? [];
    $exportUrl = $exportUrl ?? route('reports.export.csv', $filters);

    $cities = collect($filterOptions['cities'] ?? []);
    $vendors = collect($filterOptions['vendors'] ?? []);
    $warehouses = collect($filterOptions['warehouses'] ?? []);
    $customers = collect($filterOptions['customers'] ?? []);
    $products = collect($filterOptions['products'] ?? []);
    $productCategories = collect($filterOptions['productCategories'] ?? []);
    $businessPartners = collect($filterOptions['businessPartners'] ?? []);
    $staffUsers = collect($filterOptions['staffUsers'] ?? []);

    $revenue = $reportGroups['revenue_analytics'] ?? [];
    $rentals = $reportGroups['rental_reports'] ?? [];
    $collections = $reportGroups['collection_reports'] ?? [];
    $customersReport = $reportGroups['customer_reports'] ?? [];
    $inventory = $reportGroups['inventory_reports'] ?? [];
    $salesInvoices = $reportGroups['sales_invoice_reports'] ?? [];
    $staff = $reportGroups['staff_performance'] ?? [];
    $vendorsReport = $reportGroups['vendor_analytics'] ?? [];
    $profitability = $reportGroups['profitability_reports'] ?? [];
    $productTrends = $reportGroups['product_trends'] ?? [];
    $rentalMetrics = $rentals['metrics'] ?? [];

    $tabKeys = ['revenue', 'rentals', 'inventory', 'customers', 'staff', 'vendors', 'profit', 'trends'];
    $activeTab = in_array(($filters['tab'] ?? 'revenue'), $tabKeys, true) ? ($filters['tab'] ?? 'revenue') : 'revenue';
    $hasAppliedFilters = collect($filters)
        ->except(['report', 'tab', 'period'])
        ->filter(fn ($value) => filled($value))
        ->isNotEmpty();
    $activeFilters = collect($filters)->filter(fn ($value, $key) => filled($value) && !in_array($key, ['report', 'tab'], true))->all();
    $exportFor = fn ($report) => route('reports.export.csv', array_merge($activeFilters, ['report' => $report]));
    $reportUrl = fn (array $overrides = []) => route('reports.index', array_filter(array_merge($activeFilters, $overrides), fn ($value) => filled($value)));
    $rentalsUrl = fn (array $overrides = []) => route('rentals.index', array_filter($overrides, fn ($value) => filled($value)));
    $assetsUrl = fn (array $overrides = []) => route('assets.index', array_filter($overrides, fn ($value) => filled($value)));
    $invoicesUrl = fn (array $overrides = []) => route('invoices.index', array_filter($overrides, fn ($value) => filled($value)));
    $salesUrl = fn (array $overrides = []) => route('sales.index', array_filter($overrides, fn ($value) => filled($value)));
    $customerUrl = fn ($customerId = null) => $customerId ? route('customers.show', $customerId) : route('customers.index');
    $productUrl = fn ($productId = null) => $productId ? route('products.show', $productId) : route('products.index');
    $monthLabel = function ($month) {
        try {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m', (string) $month)->format('M Y');
        } catch (\Throwable $exception) {
            return (string) $month;
        }
    };
    $composition = collect($revenue['composition'] ?? [])->filter(fn ($row) => (float) ($row['value'] ?? 0) > 0)->values();
    $compositionTotal = max(1, (float) $composition->sum('value'));
    $donutStops = [];
    $cursor = 0;
    foreach ($composition as $row) {
        $next = $cursor + (((float) $row['value']) / $compositionTotal * 100);
        $donutStops[] = ($row['color'] ?? '#64748b') . ' ' . round($cursor, 2) . '% ' . round($next, 2) . '%';
        $cursor = $next;
    }
    $donutStyle = $donutStops ? 'background:conic-gradient(' . implode(', ', $donutStops) . ');' : 'background:#e2e8f0;';
@endphp

<style>
    .reports-shell { display:grid; gap:14px; padding:16px 20px 28px; color:#0f172a; }
    .reports-top { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; }
    .reports-title h1 { margin:0; font-size:24px; line-height:1.1; }
    .reports-title p { margin:4px 0 0; color:#526786; font-size:13px; }
    .reports-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .reports-btn { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:7px 11px; border-radius:10px; border:1px solid #cbd5e1; background:#fff; color:#1e293b; text-decoration:none; font-size:12px; font-weight:800; }
    .reports-btn.primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .reports-panel { background:#fff; border:1px solid #dbe3ef; border-radius:16px; box-shadow:0 10px 26px rgba(15,23,42,.04); }
    .reports-filter { padding:12px; display:grid; gap:10px; }
    .reports-filter[open] { gap:10px; }
    .reports-filter-summary { display:flex; justify-content:space-between; gap:12px; align-items:center; cursor:pointer; list-style:none; }
    .reports-filter-summary::-webkit-details-marker { display:none; }
    .reports-filter-summary h2 { margin:0; font-size:14px; }
    .reports-filter-summary p { margin:3px 0 0; color:#64748b; font-size:11px; }
    .reports-filter-toggle { display:inline-flex; align-items:center; min-height:30px; padding:6px 10px; border-radius:999px; border:1px solid #cbd5e1; color:#334155; font-size:11px; font-weight:900; text-transform:uppercase; letter-spacing:.04em; }
    .reports-filter-grid { display:grid; grid-template-columns:repeat(9, minmax(112px, 1fr)); gap:8px; }
    .reports-field { display:grid; gap:4px; }
    .reports-field label { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:#64748b; font-weight:800; }
    .reports-field input, .reports-field select { width:100%; min-height:34px; border:1px solid #cbd5e1; border-radius:9px; padding:6px 9px; font-size:12px; color:#0f172a; background:#fff; }
    .reports-tabs { display:flex; flex-wrap:wrap; gap:7px; padding:10px; border-bottom:1px solid #e2e8f0; }
    .reports-tab { min-height:32px; padding:7px 10px; border:1px solid #dbe3ef; border-radius:999px; color:#475569; font-size:12px; font-weight:800; cursor:pointer; background:#fff; }
    .reports-tab-content { display:none; padding:12px; }
    .reports-radio { display:none; }
    #tab-revenue:checked ~ .reports-panel .reports-tabs label[for="tab-revenue"],
    #tab-rentals:checked ~ .reports-panel .reports-tabs label[for="tab-rentals"],
    #tab-inventory:checked ~ .reports-panel .reports-tabs label[for="tab-inventory"],
    #tab-customers:checked ~ .reports-panel .reports-tabs label[for="tab-customers"],
    #tab-staff:checked ~ .reports-panel .reports-tabs label[for="tab-staff"],
    #tab-vendors:checked ~ .reports-panel .reports-tabs label[for="tab-vendors"],
    #tab-profit:checked ~ .reports-panel .reports-tabs label[for="tab-profit"],
    #tab-trends:checked ~ .reports-panel .reports-tabs label[for="tab-trends"] { background:#eef2ff; border-color:#a5b4fc; color:#4338ca; }
    #tab-revenue:checked ~ .reports-panel .revenue-tab,
    #tab-rentals:checked ~ .reports-panel .rentals-tab,
    #tab-inventory:checked ~ .reports-panel .inventory-tab,
    #tab-customers:checked ~ .reports-panel .customers-tab,
    #tab-staff:checked ~ .reports-panel .staff-tab,
    #tab-vendors:checked ~ .reports-panel .vendors-tab,
    #tab-profit:checked ~ .reports-panel .profit-tab,
    #tab-trends:checked ~ .reports-panel .trends-tab { display:grid; gap:12px; }
    .metric-strip { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:8px; }
    .metric { border:1px solid #e2e8f0; border-radius:12px; padding:10px; min-height:78px; background:#fcfdff; }
    .metric span { display:block; color:#64748b; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
    .metric strong { display:block; margin-top:7px; font-size:18px; line-height:1.1; color:#0f172a; overflow-wrap:anywhere; }
    .metric small { display:block; margin-top:4px; color:#64748b; font-size:11px; line-height:1.35; }
    .reports-grid-2 { display:grid; grid-template-columns:minmax(0, 1.1fr) minmax(0, .9fr); gap:12px; }
    .reports-grid-3 { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
    .mini-card { border:1px solid #e2e8f0; border-radius:13px; background:#fff; overflow:hidden; }
    .mini-head { display:flex; justify-content:space-between; gap:10px; padding:10px 12px; border-bottom:1px solid #edf2f7; align-items:flex-start; }
    .mini-head h2 { margin:0; font-size:14px; }
    .mini-head p { margin:3px 0 0; color:#64748b; font-size:11px; }
    .mini-body { padding:10px 12px; }
    .reports-table-wrap { overflow-x:auto; }
    .reports-table { width:100%; border-collapse:collapse; }
    .reports-table th, .reports-table td { padding:8px 7px; border-bottom:1px solid #edf2f7; text-align:left; font-size:12px; vertical-align:top; white-space:nowrap; }
    .reports-table th { color:#64748b; font-size:10px; text-transform:uppercase; letter-spacing:.06em; }
    .reports-table a { color:#1d4ed8; font-weight:800; text-decoration:none; }
    .empty { color:#64748b; font-size:12px; padding:8px 0; }
    .donut-wrap { display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
    .donut { width:132px; height:132px; border-radius:50%; position:relative; flex:0 0 auto; }
    .donut:after { content:""; position:absolute; inset:28px; background:#fff; border-radius:50%; box-shadow:inset 0 0 0 1px #e2e8f0; }
    .legend { display:grid; gap:7px; min-width:220px; }
    .legend-row { display:flex; justify-content:space-between; gap:12px; align-items:center; font-size:12px; }
    .swatch { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:6px; vertical-align:middle; }
    .trend-list { display:grid; gap:7px; }
    .trend-row { display:grid; grid-template-columns:92px 1fr auto; align-items:center; gap:8px; font-size:12px; }
    .bar { height:7px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
    .bar i { display:block; height:100%; border-radius:999px; background:#4f46e5; }
    .badge { display:inline-flex; align-items:center; justify-content:center; padding:4px 8px; border-radius:999px; font-size:10px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; background:#f1f5f9; color:#475569; }
    .badge.good { background:#dcfce7; color:#166534; }
    .badge.warn { background:#fef3c7; color:#92400e; }
    .badge.bad { background:#fee2e2; color:#991b1b; }
    @media (max-width: 1280px) { .reports-filter-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); } .metric-strip { grid-template-columns:repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 920px) { .reports-grid-2, .reports-grid-3 { grid-template-columns:1fr; } .metric-strip { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .reports-shell { padding:12px; } .reports-filter-grid, .metric-strip { grid-template-columns:1fr; } .reports-actions { width:100%; } .reports-btn { flex:1; } }
</style>

<div class="container reports-shell rn-list-page rn-report-shell">
    <div class="reports-top">
        <div class="reports-title">
            <div class="rx-eyebrow">Analytics</div>
            <h1>Reports & Insights</h1>
            <p>Structured analytics center for revenue, rentals, inventory, customers, staff, vendors, profitability, and product trends.</p>
        </div>
        <div class="reports-actions">
            <a href="{{ route('dashboard') }}" class="reports-btn">Dashboard</a>
            <a href="{{ $exportUrl }}" class="reports-btn primary">Export CSV</a>
        </div>
    </div>

    <details class="reports-panel reports-filter" @if($hasAppliedFilters) open @endif>
        <summary class="reports-filter-summary">
            <div>
                <h2>Global Filters</h2>
                <p>{{ $hasAppliedFilters ? 'Filtered view active. Expand to adjust date, city, vendor, source, product, warehouse, partner, customer, or staff.' : 'Date, city, fulfilment source, vendor, product, warehouse, partner, customer, and staff filters.' }}</p>
            </div>
            <span class="reports-filter-toggle">{{ $hasAppliedFilters ? 'Edit Filters' : 'Show Filters' }}</span>
        </summary>
    <form method="GET" action="{{ route('reports.index') }}">
        <input type="hidden" name="tab" id="reports_active_tab" value="{{ $activeTab }}">
        <div class="reports-filter-grid">
            <div class="reports-field"><label for="from_date">From</label><input id="from_date" type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}"></div>
            <div class="reports-field"><label for="to_date">To</label><input id="to_date" type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}"></div>
            <div class="reports-field">
                <label for="period">Period</label>
                <select id="period" name="period">
                    @foreach(['day' => 'Day', 'month' => 'Month', 'quarter' => 'Quarter', 'half_year' => 'Half-year', 'year' => 'Year'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['period'] ?? 'month') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="reports-field">
                <label for="city">City</label>
                <select id="city" name="city"><option value="">All</option>@foreach($cities as $city)<option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>@endforeach</select>
            </div>
            <div class="reports-field">
                <label for="fulfilment_source">Fulfilment</label>
                <select id="fulfilment_source" name="fulfilment_source">
                    <option value="">All</option>
                    <option value="in_house" @selected(($filters['fulfilment_source'] ?? '') === 'in_house')>In-house</option>
                    <option value="vendor_supplied" @selected(($filters['fulfilment_source'] ?? '') === 'vendor_supplied')>Vendor Supplied</option>
                </select>
            </div>
            <div class="reports-field"><label for="product_category">Category</label><select id="product_category" name="product_category"><option value="">All</option>@foreach($productCategories as $category)<option value="{{ $category }}" @selected(($filters['product_category'] ?? '') === $category)>{{ $category }}</option>@endforeach</select></div>
            <div class="reports-field"><label for="warehouse_id">Warehouse</label><select id="warehouse_id" name="warehouse_id"><option value="">All</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)($filters['warehouse_id'] ?? '') === (string)$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            <div class="reports-field"><label for="business_partner_id">Partner</label><select id="business_partner_id" name="business_partner_id"><option value="">All</option>@foreach($businessPartners as $partner)<option value="{{ $partner->id }}" @selected((string)($filters['business_partner_id'] ?? '') === (string)$partner->id)>{{ $partner->name }}</option>@endforeach</select></div>
            <div class="reports-field"><label for="vendor_id">Vendor</label><select id="vendor_id" name="vendor_id"><option value="">All</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string)($filters['vendor_id'] ?? '') === (string)$vendor->id)>{{ $vendor->name }}</option>@endforeach</select></div>
            <div class="reports-field"><label for="product_id">Product</label><select id="product_id" name="product_id"><option value="">All</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)($filters['product_id'] ?? '') === (string)$product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div class="reports-field"><label for="customer_id">Customer</label><select id="customer_id" name="customer_id"><option value="">All</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)($filters['customer_id'] ?? '') === (string)$customer->id)>{{ $customer->name }}</option>@endforeach</select></div>
            <div class="reports-field"><label for="referred_by">Referred By</label><input id="referred_by" type="search" name="referred_by" value="{{ $filters['referred_by'] ?? '' }}" placeholder="Doctor, clinic, customer..."></div>
            <div class="reports-field"><label for="staff_user_id">Staff/User</label><select id="staff_user_id" name="staff_user_id"><option value="">All</option>@foreach($staffUsers as $user)<option value="{{ $user->id }}" @selected((string)($filters['staff_user_id'] ?? '') === (string)$user->id)>{{ $user->name }}</option>@endforeach</select></div>
        </div>
        <div class="reports-actions">
            <button type="submit" class="reports-btn primary">Apply Filters</button>
            <a href="{{ route('reports.index', ['tab' => $activeTab]) }}" class="reports-btn">Reset</a>
            <a href="{{ $exportUrl }}" class="reports-btn">Export Current View</a>
        </div>
    </form>
    </details>

    <input class="reports-radio" type="radio" id="tab-revenue" name="reports-tab" value="revenue" @checked($activeTab === 'revenue')>
    <input class="reports-radio" type="radio" id="tab-rentals" name="reports-tab" value="rentals" @checked($activeTab === 'rentals')>
    <input class="reports-radio" type="radio" id="tab-inventory" name="reports-tab" value="inventory" @checked($activeTab === 'inventory')>
    <input class="reports-radio" type="radio" id="tab-customers" name="reports-tab" value="customers" @checked($activeTab === 'customers')>
    <input class="reports-radio" type="radio" id="tab-staff" name="reports-tab" value="staff" @checked($activeTab === 'staff')>
    <input class="reports-radio" type="radio" id="tab-vendors" name="reports-tab" value="vendors" @checked($activeTab === 'vendors')>
    <input class="reports-radio" type="radio" id="tab-profit" name="reports-tab" value="profit" @checked($activeTab === 'profit')>
    <input class="reports-radio" type="radio" id="tab-trends" name="reports-tab" value="trends" @checked($activeTab === 'trends')>

    <div class="reports-panel">
        <div class="reports-tabs">
            <label class="reports-tab" for="tab-revenue">Revenue Analytics</label>
            <label class="reports-tab" for="tab-rentals">Rental Analytics</label>
            <label class="reports-tab" for="tab-inventory">Inventory Analytics</label>
            <label class="reports-tab" for="tab-customers">Customer & Partner</label>
            <label class="reports-tab" for="tab-staff">Staff Performance</label>
            <label class="reports-tab" for="tab-vendors">Vendor Analytics</label>
            <label class="reports-tab" for="tab-profit">Profitability / EBITDA</label>
            <label class="reports-tab" for="tab-trends">Product Trends</label>
        </div>

        <section class="reports-tab-content revenue-tab">
            <div class="metric-strip">
                <div class="metric"><span>Total Revenue</span><strong>{{ $currency($revenue['total_revenue'] ?? 0) }}</strong><small>Invoices or available order value</small></div>
                <div class="metric"><span>Rental Revenue</span><strong>{{ $currency($revenue['rental_revenue'] ?? 0) }}</strong><small><a href="{{ $exportFor('active_rentals') }}">Export rentals</a></small></div>
                <div class="metric"><span>Sales Revenue</span><strong>{{ $currency($revenue['sales_revenue'] ?? 0) }}</strong><small><a href="{{ $salesUrl() }}">View details</a></small></div>
                <div class="metric"><span>Deposit</span><strong>{{ $currency($revenue['deposit'] ?? 0) }}</strong><small>Deposit component</small></div>
                <div class="metric"><span>Transport</span><strong>{{ $currency($revenue['transport'] ?? 0) }}</strong><small>Shipping and transport</small></div>
                <div class="metric"><span>Other Charges</span><strong>{{ $currency($revenue['other_charges'] ?? 0) }}</strong><small>Extra charge component</small></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card">
                    <div class="mini-head"><div><h2>Revenue Composition</h2><p>Rental, sales, deposit, transport, and other mix.</p></div><a class="reports-btn" href="{{ $exportUrl }}">Export CSV</a></div>
                    <div class="mini-body donut-wrap">
                        <div class="donut" style="{{ $donutStyle }}"></div>
                        <div class="legend">
                            @forelse($composition as $row)
                                <div class="legend-row"><span><i class="swatch" style="background:{{ $row['color'] ?? '#64748b' }}"></i>{{ $row['label'] }}</span><strong>{{ $currency($row['value']) }}</strong></div>
                            @empty
                                <div class="empty">No revenue composition available for the current filters.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="mini-card">
                    <div class="mini-head"><div><h2>Revenue Trend</h2><p>Billing value by selected reporting period.</p></div><span class="badge good">{{ $number($revenue['collection_efficiency'] ?? 0, 1) }}% collected</span></div>
                    <div class="mini-body trend-list">
                        @php($maxTrend = max(1, collect($revenue['revenue_trend'] ?? [])->max('value') ?: 1))
                        @forelse(collect($revenue['revenue_trend'] ?? [])->take(12) as $row)
                            <div class="trend-row"><strong>{{ $monthLabel($row['month']) }}</strong><span class="bar"><i style="width:{{ min(100, (((float)$row['value']) / $maxTrend) * 100) }}%"></i></span><span>{{ $currency($row['value']) }}</span></div>
                        @empty
                            <div class="empty">No revenue trend available.</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Rental vs Sales Trend</h2><p>Rental volume against sales value.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Period</th><th>Rentals</th><th>Sales Value</th></tr></thead><tbody>@forelse(collect($revenue['rental_trend'] ?? []) as $row)<tr><td>{{ $monthLabel($row['month']) }}</td><td>{{ $number($row['value']) }}</td><td>{{ $currency(collect($revenue['sales_trend'] ?? [])->firstWhere('month', $row['month'])['value'] ?? 0) }}</td></tr>@empty<tr><td colspan="3"><div class="empty">No rental vs sales trend available.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Collection Performance</h2><p>Outstanding and collection workload.</p></div><a class="reports-btn" href="{{ $exportFor('customer_outstanding') }}">Export Dues</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Customer</th><th>Outstanding</th></tr></thead><tbody>@forelse(collect($collections['customer_outstanding'] ?? [])->take(8) as $row)<tr><td><a href="{{ $customerUrl($row->customer_id ?? null) }}">{{ $row->label }}</a></td><td>{{ $currency($row->total) }}</td></tr>@empty<tr><td colspan="2"><div class="empty">No outstanding dues.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
        </section>

        <section class="reports-tab-content rentals-tab">
            <div class="metric-strip">
                <a class="metric" href="{{ $rentalsUrl(['status' => 'active']) }}"><span>Active Rentals</span><strong>{{ $number($rentalMetrics['activeRentals'] ?? 0) }}</strong><small>View details</small></a>
                <a class="metric" href="{{ $rentalsUrl(['status' => 'returned']) }}"><span>Returned Rentals</span><strong>{{ $number($rentalMetrics['returnedRentals'] ?? 0) }}</strong><small>View details</small></a>
                <a class="metric" href="{{ $rentalsUrl(['filter' => 'overdue']) }}"><span>Overdue Rentals</span><strong>{{ $number($rentalMetrics['overdueRentals'] ?? 0) }}</strong><small>Export available</small></a>
                <a class="metric" href="{{ $rentalsUrl(['filter' => 'ending_soon']) }}"><span>Ending Soon</span><strong>{{ $number($rentalMetrics['endingSoonRentals'] ?? 0) }}</strong><small>Renewal planning</small></a>
                <div class="metric"><span>Sales Count</span><strong>{{ $number($salesInvoices['sales_count'] ?? 0) }}</strong><small>Filtered sales</small></div>
                <div class="metric"><span>Invoices This Month</span><strong>{{ $number($salesInvoices['invoices_this_month'] ?? 0) }}</strong><small><a href="{{ $exportFor('invoices_this_month') }}">Export CSV</a></small></div>
            </div>
            <div class="reports-grid-3">
                @foreach(['city_wise' => 'City-wise Rentals', 'vendor_wise' => 'Vendor-wise Rentals', 'warehouse_wise' => 'Warehouse-wise Rentals'] as $key => $title)
                    <div class="mini-card"><div class="mini-head"><div><h2>{{ $title }}</h2><p>Top filtered distribution.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Name</th><th>Rentals</th></tr></thead><tbody>@forelse(collect($rentals[$key] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->total) }}</td></tr>@empty<tr><td colspan="2"><div class="empty">No data available.</div></td></tr>@endforelse</tbody></table></div></div>
                @endforeach
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Product-wise Rentals</h2><p>Most active rented products.</p></div><a class="reports-btn" href="{{ $exportFor('product_utilization') }}">Export CSV</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Product</th><th>Rentals</th><th>Quantity</th></tr></thead><tbody>@forelse(collect($rentals['product_wise'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->total) }}</td><td>{{ $number($row->quantity_total) }}</td></tr>@empty<tr><td colspan="3"><div class="empty">No product rental data.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Monthly Rental Trend</h2><p>Rental volume movement.</p></div></div><div class="mini-body trend-list">@php($maxRentals = max(1, collect($rentals['monthly_trend'] ?? [])->max('value') ?: 1))@forelse(collect($rentals['monthly_trend'] ?? [])->take(12) as $row)<div class="trend-row"><strong>{{ $monthLabel($row['month']) }}</strong><span class="bar"><i style="width:{{ min(100, (((float)$row['value']) / $maxRentals) * 100) }}%"></i></span><span>{{ $number($row['value']) }}</span></div>@empty<div class="empty">No rental trend available.</div>@endforelse</div></div>
            </div>
        </section>

        <section class="reports-tab-content inventory-tab">
            <div class="metric-strip">
                <a class="metric" href="{{ $assetsUrl(['asset_status' => 'available']) }}"><span>Available</span><strong>{{ $number($inventory['available_assets'] ?? 0) }}</strong><small>Ready stock</small></a>
                <a class="metric" href="{{ $assetsUrl(['asset_status' => 'rented']) }}"><span>Rented Out</span><strong>{{ $number($inventory['rented_assets'] ?? 0) }}</strong><small>Field stock</small></a>
                <a class="metric" href="{{ $assetsUrl(['asset_status' => 'maintenance']) }}"><span>Maintenance</span><strong>{{ $number($inventory['maintenance_assets'] ?? 0) }}</strong><small>Repair queue</small></a>
                <div class="metric"><span>Reserved / Blocked</span><strong>{{ $number($inventory['reserved_blocked_assets'] ?? 0) }}</strong><small>Committed stock</small></div>
                <div class="metric"><span>Utilization</span><strong>{{ $number($inventory['utilization_percent'] ?? 0, 1) }}%</strong><small>Rented plus reserved</small></div>
                <div class="metric"><span>Low Stock</span><strong>{{ collect($inventory['low_stock_products'] ?? [])->count() }}</strong><small>Products below threshold</small></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Product Availability</h2><p>Available, deployed, maintenance, and utilization.</p></div><a class="reports-btn" href="{{ $exportFor('product_utilization') }}">Export CSV</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Product</th><th>Avail</th><th>Rented</th><th>Maint.</th><th>Util.</th></tr></thead><tbody>@forelse(collect($inventory['product_utilization'] ?? []) as $row)<tr><td><a href="{{ $productUrl($row->id) }}">{{ $row->name }}</a></td><td>{{ $number($row->available_assets ?? 0) }}</td><td>{{ $number($row->rented_assets ?? 0) }}</td><td>{{ $number($row->maintenance_assets ?? 0) }}</td><td>{{ ($row->total_assets ?? 0) > 0 ? $number((($row->rented_assets ?? 0) / $row->total_assets) * 100, 1) : '0.0' }}%</td></tr>@empty<tr><td colspan="5"><div class="empty">No product utilization data.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Category Availability</h2><p>Product/category availability chart.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Category</th><th>Available</th><th>Committed</th><th>Total</th></tr></thead><tbody>@forelse(collect($inventory['category_availability'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->available_count) }}</td><td>{{ $number($row->committed_count) }}</td><td>{{ $number($row->total_count) }}</td></tr>@empty<tr><td colspan="4"><div class="empty">No category availability data.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Low Stock</h2><p>Products needing availability attention.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Product</th><th>Available</th><th>Total</th></tr></thead><tbody>@forelse(collect($inventory['low_stock_products'] ?? []) as $row)<tr><td>{{ $row->name }}</td><td>{{ $number($row->available_quantity) }}</td><td>{{ $number($row->total_quantity) }}</td></tr>@empty<tr><td colspan="3"><div class="empty">No low stock products under current filters.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Warehouse Stock</h2><p>Warehouse contribution summary.</p></div><a class="reports-btn" href="{{ $exportFor('warehouse_stock_summary') }}">Export CSV</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Warehouse</th><th>Total Assets</th></tr></thead><tbody>@forelse(collect($inventory['warehouse_stock_summary'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->total) }}</td></tr>@empty<tr><td colspan="2"><div class="empty">No warehouse stock data.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
        </section>

        <section class="reports-tab-content customers-tab">
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Business Partner Performance</h2><p>Revenue, actual clients, orders, dues, and collection performance.</p></div><a class="reports-btn" href="{{ $exportFor('customer_outstanding') }}">Export Dues</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Partner</th><th>Revenue</th><th>Clients</th><th>Rentals</th><th>Sales</th><th>Dues</th><th>Collection</th></tr></thead><tbody>@forelse(collect($customersReport['business_partner_performance'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $currency($row->revenue) }}</td><td>{{ $number($row->actual_clients) }}</td><td>{{ $number($row->rentals_count) }}</td><td>{{ $number($row->sales_count) }}</td><td>{{ $currency($row->outstanding) }}</td><td>{{ $number($row->collection_percent, 1) }}%</td></tr>@empty<tr><td colspan="7"><div class="empty">No business partner data for current filters.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Top Customers</h2><p>Direct customers by rental count.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Customer</th><th>Type</th><th>Rentals</th></tr></thead><tbody>@forelse(collect($customersReport['top_customers'] ?? []) as $row)<tr><td><a href="{{ $customerUrl($row->id) }}">{{ $row->name }}</a></td><td><span class="badge good">Top</span></td><td>{{ $number($row->rentals_count) }}</td></tr>@empty<tr><td colspan="3"><div class="empty">No customer data available.</div></td></tr>@endforelse @foreach(collect($customersReport['repeat_customers'] ?? [])->take(5) as $row)<tr><td><a href="{{ $customerUrl($row->id) }}">{{ $row->name }}</a></td><td><span class="badge">Repeat</span></td><td>{{ $number($row->rentals_count) }}</td></tr>@endforeach</tbody></table></div></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Customer Acquisition</h2><p>New customers by month.</p></div></div><div class="mini-body trend-list">@php($maxCustomers = max(1, collect($customersReport['new_customers_by_month'] ?? [])->max('value') ?: 1))@forelse(collect($customersReport['new_customers_by_month'] ?? [])->take(12) as $row)<div class="trend-row"><strong>{{ $monthLabel($row['month']) }}</strong><span class="bar"><i style="width:{{ min(100, (((float)$row['value']) / $maxCustomers) * 100) }}%"></i></span><span>{{ $number($row['value']) }}</span></div>@empty<div class="empty">No acquisition trend available.</div>@endforelse</div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>City-wise Customers</h2><p>Market spread by city.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>City</th><th>Customers</th></tr></thead><tbody>@forelse(collect($customersReport['city_wise_counts'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->total) }}</td></tr>@empty<tr><td colspan="2"><div class="empty">No city customer data.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card">
                    <div class="mini-head"><div><h2>Referral Analytics</h2><p>Rental orders and value attributed to referred-by entries.</p></div><a class="reports-btn" href="{{ $rentalsUrl(['referred_by' => $filters['referred_by'] ?? '']) }}">View Rentals</a></div>
                    <div class="mini-body">
                        @php($referrals = $customersReport['referral_summary'] ?? [])
                        @if(!($referrals['available'] ?? false))
                            <div class="empty">Referral analytics will appear after the referral migration is applied.</div>
                        @else
                            <div class="metric-strip" style="grid-template-columns:repeat(3, minmax(0, 1fr)); margin-bottom:10px;">
                                <div class="metric"><span>Referred Rentals</span><strong>{{ $number($referrals['rental_count'] ?? 0) }}</strong><small>Filtered rental count</small></div>
                                <div class="metric"><span>Referral Value</span><strong>{{ $currency($referrals['revenue'] ?? 0) }}</strong><small>Rental, deposit, transport, other</small></div>
                                <div class="metric"><span>Referrers</span><strong>{{ $number($referrals['unique_referrers'] ?? 0) }}</strong><small>Unique referred-by names</small></div>
                            </div>
                            <div class="reports-table-wrap"><table class="reports-table"><thead><tr><th>Referred By</th><th>Type</th><th>Rentals</th><th>Value</th></tr></thead><tbody>@forelse(collect($referrals['leaderboard'] ?? [])->take(8) as $row)<tr><td>{{ $row->label }}</td><td>{{ ucfirst(str_replace('_', ' ', $row->referral_type ?? 'other')) }}</td><td>{{ $number($row->rentals_count) }}</td><td>{{ $currency($row->revenue) }}</td></tr>@empty<tr><td colspan="4"><div class="empty">No referral data under current filters.</div></td></tr>@endforelse</tbody></table></div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="reports-tab-content staff-tab">
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Sales Staff</h2><p>Orders created, revenue generated, collections supported, and follow-ups completed.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Staff</th><th>Orders</th><th>Revenue</th><th>Collections</th><th>Follow-ups</th></tr></thead><tbody>@forelse(collect($staff['sales_staff'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->orders_created) }}</td><td>{{ $currency($row->revenue_generated) }}</td><td>{{ $number($row->collections_supported) }}</td><td>{{ $number($row->followups_completed) }}</td></tr>@empty<tr><td colspan="5"><div class="empty">No sales staff activity under current filters.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Delivery Staff</h2><p>Assigned deliveries, completions, delays, and pickups completed.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Staff</th><th>Assigned</th><th>Completed</th><th>Delayed</th><th>Pickups</th></tr></thead><tbody>@forelse(collect($staff['delivery_staff'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->assigned_deliveries) }}</td><td>{{ $number($row->completed_deliveries) }}</td><td>{{ $number($row->delayed_deliveries) }}</td><td>{{ $number($row->pickups_completed) }}</td></tr>@empty<tr><td colspan="5"><div class="empty">No delivery staff activity under current filters.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
        </section>

        <section class="reports-tab-content vendors-tab">
            <div class="mini-card">
                <div class="mini-head"><div><h2>Vendor Performance Summary</h2><p>Financial view of vendor-supplied rental and sales business.</p></div><a class="reports-btn" href="{{ route('vendor-orders.index') }}">View Details</a></div>
            </div>
            <div class="metric-strip">
                <div class="metric"><span>Vendor Revenue</span><strong>{{ $currency($vendorsReport['vendor_revenue'] ?? 0) }}</strong><small>Vendor-supplied customer value</small></div>
                <div class="metric"><span>Vendor Cost</span><strong>{{ $currency($vendorsReport['vendor_cost'] ?? 0) }}</strong><small>Procurement and fulfilment cost</small></div>
                <div class="metric"><span>Vendor Margin</span><strong>{{ $currency($vendorsReport['vendor_margin'] ?? 0) }}</strong><small>Revenue less vendor cost</small></div>
                <div class="metric"><span>Vendor Orders</span><strong>{{ $number($vendorsReport['vendor_orders_count'] ?? collect($vendorsReport['top_vendors'] ?? [])->sum('orders')) }}</strong><small>{{ $number($vendorsReport['vendor_supplied_rentals'] ?? 0) }} rentals &middot; {{ $number($vendorsReport['vendor_supplied_sales'] ?? 0) }} sales</small></div>
            </div>
            <div class="metric-strip">
                <div class="metric"><span>On-time Fulfilments</span><strong>{{ $number($vendorsReport['fulfilment_performance']['on_time'] ?? 0) }}</strong><small>Completed vendor fulfilments</small></div>
                <div class="metric"><span>Delayed Fulfilments</span><strong>{{ $number($vendorsReport['fulfilment_performance']['delayed'] ?? 0) }}</strong><small>Open vendor-supplied delays</small></div>
                <div class="metric"><span>Cancelled Fulfilments</span><strong>{{ $number($vendorsReport['fulfilment_performance']['cancelled'] ?? 0) }}</strong><small>Cancelled vendor orders</small></div>
                <div class="metric"><span>Fulfilment Score</span><strong>{{ $number($vendorsReport['fulfilment_performance']['score'] ?? 0, 1) }}%</strong><small>Completion score after delay/cancel penalty</small></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Vendor Leaderboard</h2><p>Revenue, cost, margin, and order count from vendor costing data.</p></div><a class="reports-btn" href="{{ route('vendor-orders.index') }}">View Details</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Vendor</th><th>Revenue</th><th>Cost</th><th>Margin</th><th>Orders</th></tr></thead><tbody>@forelse(collect($vendorsReport['top_vendors'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $currency($row->revenue) }}</td><td>{{ $currency($row->cost) }}</td><td>{{ $currency($row->margin) }}</td><td>{{ $number($row->orders) }}</td></tr>@empty<tr><td colspan="5"><div class="empty">No vendor data available for current filters.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Vendor Fulfilment Performance</h2><p>On-time, delayed, cancelled, and score by vendor.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Vendor</th><th>On-time</th><th>Delayed</th><th>Cancelled</th><th>Score</th></tr></thead><tbody>@forelse(collect($vendorsReport['top_vendors'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->on_time ?? 0) }}</td><td>{{ $number($row->delayed ?? 0) }}</td><td>{{ $number($row->cancelled ?? 0) }}</td><td><span class="badge {{ ($row->fulfilment_score ?? 0) >= 80 ? 'good' : ((($row->fulfilment_score ?? 0) >= 50) ? 'warn' : 'bad') }}">{{ $number($row->fulfilment_score ?? 0, 1) }}%</span></td></tr>@empty<tr><td colspan="5"><div class="empty">No fulfilment performance available for current filters.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Vendor Trend</h2><p>Monthly vendor revenue, cost, and margin.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Month</th><th>Revenue</th><th>Cost</th><th>Margin</th></tr></thead><tbody>@forelse(collect($vendorsReport['vendor_trend'] ?? []) as $row)<tr><td>{{ $monthLabel($row['month'] ?? '') }}</td><td>{{ $currency($row['revenue'] ?? 0) }}</td><td>{{ $currency($row['cost'] ?? 0) }}</td><td>{{ $currency($row['margin'] ?? 0) }}</td></tr>@empty<tr><td colspan="4"><div class="empty">No vendor trend available for the current filters.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Delayed Vendor Fulfilment</h2><p>Open vendor-supplied orders not yet fulfilled.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Vendor</th><th>Type</th><th>Status</th><th>Margin</th></tr></thead><tbody>@forelse(collect($vendorsReport['delayed_vendor_fulfilment'] ?? []) as $row)<tr><td>{{ $row->vendor?->name ?? 'Unassigned' }}</td><td>{{ ucfirst($row->order_type ?? 'order') }}</td><td><span class="badge warn">{{ str_replace('_', ' ', $row->operationalFulfilmentStatus()) }}</span></td><td>{{ $currency($row->grossMargin()) }}</td></tr>@empty<tr><td colspan="4"><div class="empty">No delayed vendor fulfilment found.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
            <div class="reports-grid-3">
                <div class="mini-card"><div class="mini-head"><div><h2>Delayed Vendors</h2><p>Vendors with unresolved fulfilment delays.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Vendor</th><th>Delayed</th><th>Score</th></tr></thead><tbody>@forelse(collect($vendorsReport['vendor_risk']['delayed_vendors'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->delayed ?? 0) }}</td><td>{{ $number($row->fulfilment_score ?? 0, 1) }}%</td></tr>@empty<tr><td colspan="3"><div class="empty">No delayed vendor risk.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>High-cost Vendors</h2><p>Vendors where cost consumes most revenue.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Vendor</th><th>Cost</th><th>Revenue</th></tr></thead><tbody>@forelse(collect($vendorsReport['vendor_risk']['high_cost_vendors'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $currency($row->cost ?? 0) }}</td><td>{{ $currency($row->revenue ?? 0) }}</td></tr>@empty<tr><td colspan="3"><div class="empty">No high-cost vendor risk.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>Low-margin Vendors</h2><p>Vendors at or below 10% margin.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Vendor</th><th>Margin</th><th>Margin %</th></tr></thead><tbody>@forelse(collect($vendorsReport['vendor_risk']['low_margin_vendors'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $currency($row->margin ?? 0) }}</td><td><span class="badge {{ ($row->margin_percent ?? 0) < 0 ? 'bad' : 'warn' }}">{{ $number($row->margin_percent ?? 0, 1) }}%</span></td></tr>@empty<tr><td colspan="3"><div class="empty">No low-margin vendor risk.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
        </section>

        <section class="reports-tab-content profit-tab">
            <div class="metric-strip">
                <div class="metric"><span>Total Revenue</span><strong>{{ $currency($profitability['total_revenue'] ?? 0) }}</strong><small>Filtered revenue</small></div>
                <div class="metric"><span>Vendor Cost</span><strong>{{ $currency($profitability['vendor_cost'] ?? 0) }}</strong><small>Vendor cost data</small></div>
                <div class="metric"><span>Logistics Cost</span><strong>{{ $currency($profitability['delivery_logistics_cost'] ?? 0) }}</strong><small>Delivery/pickup cost</small></div>
                <div class="metric"><span>Repair Cost</span><strong>{{ $currency($profitability['repair_maintenance_cost'] ?? 0) }}</strong><small>Available maintenance cost</small></div>
                <div class="metric"><span>Gross Margin</span><strong>{{ $currency($profitability['gross_margin'] ?? 0) }}</strong><small>Revenue less vendor cost</small></div>
                <div class="metric"><span>Estimated EBITDA</span><strong>{{ $currency($profitability['estimated_ebitda'] ?? 0) }}</strong><small>Estimated based on available cost data.</small></div>
            </div>
            <div class="mini-card"><div class="mini-head"><div><h2>Profitability Notes</h2><p>Estimated based on available cost data.</p></div></div><div class="mini-body"><div class="empty">Delivery/logistics and repair/maintenance costs are included only when captured in vendor order cost fields. Missing cost categories are intentionally labelled as estimates rather than inferred.</div></div></div>
        </section>

        <section class="reports-tab-content trends-tab">
            <div class="metric-strip">
                <div class="metric"><span>Fast-moving Products</span><strong>{{ collect($productTrends['fast_moving_products'] ?? [])->count() }}</strong><small>Ranked by rental frequency</small></div>
                <div class="metric"><span>Avg Rental Duration</span><strong>{{ $number($productTrends['average_rental_duration'] ?? 0, 1) }} days</strong><small>Filtered rentals</small></div>
                <div class="metric"><span>Rising Demand</span><strong>{{ collect($productTrends['demand_projection'] ?? [])->count() }}</strong><small>Projection signal</small></div>
                <div class="metric"><span>Procurement Signals</span><strong>{{ collect($productTrends['suggested_procurement'] ?? [])->count() }}</strong><small>Products to monitor</small></div>
            </div>
            <div class="reports-grid-2">
                <div class="mini-card"><div class="mini-head"><div><h2>Product Trend & Forecasting</h2><p>Frequency, duration proxy, utilization trend, projection, and procurement signal.</p></div><a class="reports-btn" href="{{ $exportFor('product_utilization') }}">Export CSV</a></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Product</th><th>Freq.</th><th>Qty.</th><th>Util.</th><th>Demand</th><th>Procurement</th></tr></thead><tbody>@forelse(collect($productTrends['utilization_trend'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->rental_frequency) }}</td><td>{{ $number($row->quantity_total) }}</td><td>{{ $number($row->utilization_percent, 1) }}%</td><td><span class="badge {{ $row->demand_projection === 'Rising' ? 'good' : '' }}">{{ $row->demand_projection }}</span></td><td>{{ $row->suggested_procurement }}</td></tr>@empty<tr><td colspan="6"><div class="empty">No product trend data under current filters.</div></td></tr>@endforelse</tbody></table></div></div>
                <div class="mini-card"><div class="mini-head"><div><h2>High Demand Products</h2><p>Fast-moving products by rental frequency.</p></div></div><div class="mini-body reports-table-wrap"><table class="reports-table"><thead><tr><th>Product</th><th>Rentals</th><th>Quantity</th></tr></thead><tbody>@forelse(collect($productTrends['fast_moving_products'] ?? []) as $row)<tr><td>{{ $row->label }}</td><td>{{ $number($row->total) }}</td><td>{{ $number($row->quantity_total) }}</td></tr>@empty<tr><td colspan="3"><div class="empty">No fast-moving product data.</div></td></tr>@endforelse</tbody></table></div></div>
            </div>
        </section>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var activeTabInput = document.getElementById('reports_active_tab');

        if (!activeTabInput) {
            return;
        }

        document.querySelectorAll('input[name="reports-tab"]').forEach(function (tabInput) {
            tabInput.addEventListener('change', function () {
                if (tabInput.checked) {
                    activeTabInput.value = tabInput.value;
                }
            });
        });
    });
</script>
@endsection
