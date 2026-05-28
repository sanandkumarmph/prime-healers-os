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
<div style="display:grid; gap:18px; max-width:100%;">
    <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff;">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">
            <div style="display:grid; gap:6px; min-width:0;">
                <span style="font-size:12px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#64748b;">Inventory</span>
                <h1 style="margin:0; font-size:28px; line-height:1.1;">Inventory Intelligence</h1>
                <p style="margin:0; color:#64748b;">Daily closing stock matrix and movement drill-down from the immutable ledger.</p>
            </div>
            @if($canExport)
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="{{ route('inventory-intelligence.export-matrix', request()->query()) }}" class="ph-button ph-button-secondary">Export Matrix CSV</a>
                    <a href="{{ route('inventory-intelligence.export-movements', request()->query()) }}" class="ph-button ph-button-secondary">Export Movement CSV</a>
                </div>
            @endif
        </div>
    </div>

    <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff;">
        <form method="GET" action="{{ route('inventory-intelligence.index') }}" style="display:grid; gap:14px;" id="inventory-intelligence-filter-form">
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
                            <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="ph-button ph-button-primary">Apply Filters</button>
                <a href="{{ route('inventory-intelligence.index') }}" class="ph-button ph-button-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:14px;">
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Selected Period</div>
            <div style="margin-top:10px; font-size:18px; font-weight:800; color:#0f172a;">{{ $periodLabel }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Total Movements</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ ($report['reconciliation']['ledger_movement_count'] ?? 0) + ($report['reconciliation']['fallback_movement_count'] ?? 0) }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Opening Stock</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['opening_stock'] }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Total Available Stock</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['total_available_stock'] }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Closing Stock</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['closing_stock'] }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Rental Utilization %</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ number_format((float) $summary['rental_utilization_pct'], 1) }}%</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Total Rental Out</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['total_rental_out'] }}</div>
            <div style="margin-top:8px; font-size:12px; color:#64748b;">Units dispatched in the selected period.</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Total Returns</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['total_returns'] }}</div>
            <div style="margin-top:8px; font-size:12px; color:#64748b;">Units picked up in the selected period.</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Sale Orders</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['sale_orders'] }}</div>
            <div style="margin-top:6px; font-size:12px; line-height:1.4; color:#64748b;">Sale Orders = number of sale transactions in the selected period.</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Sale Units Out</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['total_sale_out'] }}</div>
            <div style="margin-top:6px; font-size:12px; line-height:1.4; color:#64748b;">Sale Units Out = total product quantity consumed from stock.</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Sale Stock Consumed</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['sale_stock_consumed'] }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Net Change</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['net_change'] }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">Low Stock Products</div>
            <div style="margin-top:10px; font-size:28px; font-weight:800; color:#0f172a;">{{ $summary['low_stock_products'] }}</div>
        </div>
        <div class="ph-card" style="padding:18px; border:1px solid #dbe7f3; border-radius:22px; background:#ffffff;">
            <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b;">High Movement Products</div>
            <div style="margin-top:10px; font-size:14px; font-weight:700; color:#0f172a;">{{ $summary['high_movement_products']->isNotEmpty() ? $summary['high_movement_products']->join(', ') : 'None this month' }}</div>
        </div>
    </div>

    @php($reconciliation = $report['reconciliation'] ?? null)
    @if($reconciliation)
        <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; display:grid; gap:14px;">
            <div style="display:grid; gap:4px;">
                <strong style="font-size:18px;">Reconciliation</strong>
                <span style="font-size:13px; color:#64748b;">This report uses stock movements first, then safely falls back to completed operational records only when ledger rows are missing.</span>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Ledger Movements Counted</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconciliation['ledger_movement_count'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Fallback Records Used</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconciliation['fallback_movement_count'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Missing Ledger Records</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconciliation['missing_ledger_records_detected'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Quantity Warnings</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ collect($reconciliation['warnings'] ?? [])->count() }}</div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Ledger Quantities</strong>
                    <span style="font-size:13px; color:#334155;">Sale Out: {{ $reconciliation['ledger_quantities']['sale_out'] ?? 0 }}</span>
                    <span style="font-size:13px; color:#334155;">Rental Out: {{ $reconciliation['ledger_quantities']['rental_out'] ?? 0 }}</span>
                    <span style="font-size:13px; color:#334155;">Returns: {{ $reconciliation['ledger_quantities']['returns'] ?? 0 }}</span>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Fallback Quantities</strong>
                    <span style="font-size:13px; color:#334155;">Sale Out: {{ $reconciliation['fallback_quantities']['sale_out'] ?? 0 }}</span>
                    <span style="font-size:13px; color:#334155;">Rental Out: {{ $reconciliation['fallback_quantities']['rental_out'] ?? 0 }}</span>
                    <span style="font-size:13px; color:#334155;">Returns: {{ $reconciliation['fallback_quantities']['returns'] ?? 0 }}</span>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Unlinked Records</strong>
                    <span style="font-size:13px; color:#334155;">Sales: {{ $reconciliation['unlinked_sales'] ?? 0 }}</span>
                    <span style="font-size:13px; color:#334155;">Rentals: {{ $reconciliation['unlinked_rentals'] ?? 0 }}</span>
                    <span style="font-size:13px; color:#334155;">Deliveries: {{ $reconciliation['unlinked_deliveries'] ?? 0 }}</span>
                </div>
            </div>

            @if(collect($reconciliation['warnings'] ?? [])->isNotEmpty())
                <div style="display:grid; gap:8px;">
                    <strong style="font-size:14px;">Warnings</strong>
                    @foreach(($reconciliation['warnings'] ?? []) as $warning)
                        <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:14px; padding:12px 14px; font-size:13px;">
                            {{ $warning['message'] ?? 'Reconciliation warning detected.' }}
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @php($stockBasis = $summary['stock_basis_reconciliation'] ?? null)
    @if($stockBasis)
        <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; display:grid; gap:14px;">
            <div style="display:grid; gap:4px;">
                <strong style="font-size:18px;">Stock Basis Reconciliation</strong>
                <span style="font-size:13px; color:#64748b;">Opening stock is reconstructed from current sale availability, sale units sold in the selected period, and total rental assets. Current available stock comes from sale units available now plus rental assets currently available.</span>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Sale Units Available Now</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $stockBasis['sale_units_available_now'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Sale Units Sold In Period</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $stockBasis['sale_units_sold_in_period'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Total Rental Assets</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $stockBasis['rental_assets_total'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Rental Assets Available Now</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $stockBasis['rental_assets_available_now'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Rental Unavailable Now</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $stockBasis['rental_unavailable_now'] }}</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                    <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Current Available Formula</div>
                    <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $stockBasis['current_available_stock'] }}</div>
                    <div style="margin-top:6px; font-size:12px; color:#64748b;">Sale available now + rental available now</div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Opening Stock Formula</strong>
                    <span style="font-size:13px; color:#334155;">
                        {{ $stockBasis['sale_units_available_now'] }}
                        + {{ $stockBasis['sale_units_sold_in_period'] }}
                        + {{ $stockBasis['rental_assets_total'] }}
                        = <strong style="color:#0f172a;">{{ $stockBasis['opening_stock'] }}</strong>
                    </span>
                    <span style="font-size:12px; color:#64748b;">Sale units available now + sale units sold in period + total rental assets.</span>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Current Available Formula</strong>
                    <span style="font-size:13px; color:#334155;">
                        {{ $stockBasis['sale_units_available_now'] }}
                        + {{ $stockBasis['rental_assets_available_now'] }}
                        = <strong style="color:#0f172a;">{{ $stockBasis['current_available_stock'] }}</strong>
                    </span>
                    <span style="font-size:12px; color:#64748b;">Sale units available now + rental assets available now.</span>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Rental Unavailable Breakdown</strong>
                    <span style="font-size:13px; color:#334155;">Total Rental Assets: {{ $stockBasis['rental_assets_total'] }}</span>
                    <span style="font-size:13px; color:#334155;">Less Active Rented: {{ $stockBasis['rental_unavailable_breakdown']['rented'] }}</span>
                    <span style="font-size:13px; color:#334155;">Less Maintenance: {{ $stockBasis['rental_unavailable_breakdown']['maintenance'] }}</span>
                    <span style="font-size:13px; color:#334155;">Less Awaiting Verification: {{ $stockBasis['rental_unavailable_breakdown']['awaiting_verification'] }}</span>
                    <span style="font-size:13px; color:#334155;">Less Reserved: {{ $stockBasis['rental_unavailable_breakdown']['reserved'] }}</span>
                    <span style="font-size:13px; color:#334155;">Less Transfer / In Transit: {{ $stockBasis['rental_unavailable_breakdown']['transfer_in_progress'] }}</span>
                    <span style="font-size:13px; color:#334155;">Less Other (sold/retired): {{ ($stockBasis['rental_unavailable_breakdown']['sold'] ?? 0) + ($stockBasis['rental_unavailable_breakdown']['retired'] ?? 0) }}</span>
                    <span style="font-size:13px; color:#334155;">Rental Available: <strong style="color:#0f172a;">{{ $stockBasis['rental_assets_available_now'] }}</strong></span>
                    <span style="font-size:13px; color:#334155;">Explained total: <strong style="color:#0f172a;">{{ $stockBasis['rental_unavailable_explained'] }}</strong></span>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px; display:grid; gap:6px;">
                    <strong style="font-size:13px;">Sales Reconciliation</strong>
                    <span style="font-size:13px; color:#334155;">Sale Orders: {{ $summary['sale_orders'] }}</span>
                    <span style="font-size:13px; color:#334155;">Sale Units Sold: {{ $stockBasis['sale_units_sold_in_period'] }}</span>
                    <span style="font-size:13px; color:#334155;">Sale Units Available: {{ $stockBasis['sale_units_available_now'] }}</span>
                    <span style="font-size:12px; color:#64748b;">Orders are transaction count. Units sold are quantity consumed from stock.</span>
                </div>
            </div>

            @if(($stockBasis['rental_unavailable_unexplained'] ?? 0) > 0)
                <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:14px; padding:12px 14px; font-size:13px;">
                    {{ $stockBasis['rental_unavailable_unexplained'] }} rental asset(s) are still unaccounted for. The asset-state buckets do not fully explain why they are unavailable.
                </div>
            @endif
        </div>
    @endif

    @php($assetReconciliation = $report['asset_reconciliation'] ?? null)
    @if($assetReconciliation)
        <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; display:grid; gap:14px;">
            <div style="display:grid; gap:4px;">
                <strong style="font-size:18px;">Asset State Reconciliation</strong>
                <span style="font-size:13px; color:#64748b;">Current asset state distribution reconciled against filtered asset totals and warehouse-level counts.</span>
            </div>

            @php($rentalReconciliation = $assetReconciliation['rental_reconciliation'] ?? null)
            @if($rentalReconciliation)
                <div style="display:grid; gap:10px;">
                    <strong style="font-size:14px;">Rental Asset Reconciliation</strong>
                    @php($reconSummary = $rentalReconciliation['reconciliation_summary'] ?? [])
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                        <div style="border:1px solid #dbe7f3; border-radius:16px; padding:14px; background:#f8fafc;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Reconciliation Health</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['health_score'] ?? 100 }}%</div>
                        </div>
                        <div style="border:1px solid #dbe7f3; border-radius:16px; padding:14px; background:#f8fafc;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Fully Reconciled Assets</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconSummary['fully_reconciled_assets'] ?? 0 }}</div>
                        </div>
                        <div style="border:1px solid #dbe7f3; border-radius:16px; padding:14px; background:#f8fafc;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Needs Reconciliation</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconSummary['needs_reconciliation_assets'] ?? 0 }}</div>
                        </div>
                        <div style="border:1px solid #dbe7f3; border-radius:16px; padding:14px; background:#f8fafc;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Missing Ledger Rows</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $report['reconciliation']['missing_ledger_records_detected'] ?? 0 }}</div>
                        </div>
                        <div style="border:1px solid #dbe7f3; border-radius:16px; padding:14px; background:#f8fafc;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Stale States</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconSummary['stale_states_count'] ?? 0 }}</div>
                            <div style="margin-top:4px; font-size:12px; color:#64748b;">>{{ $reconSummary['stale_threshold_days'] ?? 7 }} days</div>
                        </div>
                        <div style="border:1px solid #dbe7f3; border-radius:16px; padding:14px; background:#f8fafc;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Orphaned Rented Assets</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $reconSummary['orphaned_rented_assets'] ?? 0 }}</div>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Total Rental Assets</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['total_rental_assets'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Available</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['available'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Active Rented</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['active_rented'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Maintenance</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['maintenance'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Awaiting Verification</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['awaiting_verification'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Reserved / Assigned</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['reserved'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Transfer / In Transit</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['transfer_in_progress'] }}</div>
                        </div>
                        <div style="border:1px solid #e2e8f0; border-radius:16px; padding:14px;">
                            <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Needs Reconciliation</div>
                            <div style="margin-top:8px; font-size:24px; font-weight:800; color:#0f172a;">{{ $rentalReconciliation['unaccounted'] }}</div>
                        </div>
                    </div>
                    @if(($rentalReconciliation['unaccounted'] ?? 0) > 0)
                        <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:14px; padding:12px 14px; font-size:13px;">
                            {{ $rentalReconciliation['unaccounted'] }} rental assets need reconciliation. Review asset statuses.
                            <div style="margin-top:4px; font-size:12px; color:#9a3412;">Needs Reconciliation means rental assets whose current status is not mapped to a dashboard state.</div>
                        </div>
                    @endif

                    @if(collect($rentalReconciliation['unclassified_assets'] ?? [])->isNotEmpty())
                        <div style="display:grid; gap:8px;">
                            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
                                <strong style="font-size:14px;">Needs Reconciliation Asset List</strong>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    @if($canExport ?? false)
                                        <a href="{{ route('inventory-intelligence.export-reconciliation', request()->query()) }}" class="ph-button ph-button-secondary">Export CSV</a>
                                    @endif
                                </div>
                            </div>
                            @if($canReconcile ?? false)
                                <form method="POST" action="{{ route('inventory-intelligence.bulk-resolve', request()->query()) }}" style="display:grid; gap:10px;">
                                    @csrf
                                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                                        <select name="action" class="ph-input" style="min-height:44px; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px;">
                                            <option value="mark_available">Bulk mark available</option>
                                            <option value="send_to_review">Bulk send to review</option>
                                            <option value="classify_maintenance">Bulk classify maintenance</option>
                                            <option value="mark_retired">Bulk mark retired/scrap</option>
                                        </select>
                                        <input type="text" name="reason" placeholder="Reason" class="ph-input" style="min-height:44px; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px;">
                                        <input type="text" name="remarks" placeholder="Remarks" class="ph-input" style="min-height:44px; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px;">
                                    </div>
                                    <div style="font-size:12px; color:#64748b;">Superadmin only. Corrections update asset status and create correction stock movements with audit trail.</div>
                            @endif
                            <div style="display:grid; gap:10px;">
                                @foreach(($rentalReconciliation['unclassified_assets'] ?? []) as $asset)
                                    <div style="border:1px solid #e2e8f0; border-radius:18px; padding:14px 16px; display:grid; gap:10px;">
                                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                                            <div style="display:grid; gap:3px;">
                                                <strong style="font-size:14px;">
                                                    @if($canReconcile ?? false)
                                                        <label style="display:inline-flex; align-items:center; gap:8px;">
                                                            <input type="checkbox" name="asset_ids[]" value="{{ $asset['asset_id'] }}">
                                                            <span>Asset #{{ $asset['asset_id'] }}</span>
                                                        </label>
                                                    @else
                                                        Asset #{{ $asset['asset_id'] }}
                                                    @endif
                                                </strong>
                                                <span style="font-size:12px; color:#64748b;">{{ $asset['product'] }}</span>
                                            </div>
                                            <span style="font-size:12px; color:#64748b;">Updated {{ $asset['updated_at'] ?: 'Unknown' }}</span>
                                        </div>
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px;">
                                            <div style="border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; background:#f8fafc;">
                                                <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Severity</div>
                                                <div style="margin-top:6px; font-size:16px; font-weight:800; color:{{ ($asset['severity'] ?? '') === 'Critical' ? '#b91c1c' : (($asset['severity'] ?? '') === 'Warning' ? '#b45309' : '#0f172a') }};">{{ $asset['severity'] ?? 'Informational' }}</div>
                                            </div>
                                            <div style="border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; background:#f8fafc;">
                                                <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Recommended Action</div>
                                                <div style="margin-top:6px; font-size:16px; font-weight:800; color:#0f172a;">{{ $asset['recommended_action'] ?? 'Investigate Manually' }}</div>
                                            </div>
                                            <div style="border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; background:#f8fafc;">
                                                <div style="font-size:11px; text-transform:uppercase; color:#64748b; font-weight:700;">Aging</div>
                                                <div style="margin-top:6px; font-size:14px; font-weight:700; color:#0f172a;">Unclassified for {{ $asset['unclassified_for_days'] ?? '—' }} day(s)</div>
                                                <div style="margin-top:4px; font-size:12px; color:#64748b;">Last movement {{ $asset['days_since_last_valid_movement'] ?? '—' }} day(s) ago</div>
                                                <div style="font-size:12px; color:#64748b;">Last update {{ $asset['days_since_last_update'] ?? '—' }} day(s) ago</div>
                                            </div>
                                        </div>
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; font-size:13px; color:#334155;">
                                            <div><strong>Serial / Barcode:</strong> {{ $asset['serial_number'] ?: ($asset['barcode_value'] ?: '—') }}</div>
                                            <div><strong>Status:</strong> {{ $asset['asset_status'] ?: '—' }}</div>
                                            <div><strong>Condition:</strong> {{ $asset['condition_status'] ?: '—' }}</div>
                                            <div><strong>Warehouse:</strong> {{ $asset['warehouse'] ?: '—' }}</div>
                                            <div><strong>Linked Rental:</strong> {{ $asset['linked_rental_number'] ?: ($asset['linked_rental_id'] ?: '—') }}</div>
                                            <div><strong>Rental / Delivery:</strong> {{ $asset['linked_rental_status'] ?: '—' }} / {{ $asset['linked_delivery_status'] ?: '—' }}</div>
                                            <div><strong>Pickup:</strong> {{ $asset['linked_pickup_status'] ?: '—' }}</div>
                                            <div><strong>Last Stock Movement:</strong> {{ $asset['last_stock_movement'] ?: '—' }} @if($asset['last_stock_movement_at'])· {{ $asset['last_stock_movement_at'] }}@endif</div>
                                            <div style="grid-column:1 / -1;"><strong>Reason:</strong> {{ $asset['reason'] }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @if($canReconcile ?? false)
                                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                        <button type="submit" class="ph-button">Apply Bulk Resolution</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            <div style="border:1px solid #dbe7f3; border-radius:18px; padding:14px 16px; background:#f8fafc; color:#475569; font-size:13px;">
                The rental asset reconciliation above is the canonical state view. Warehouse reconciliation below uses the same logic, so this page no longer shows a second raw asset-status summary with conflicting rented counts.
            </div>

            @if(($assetReconciliation['warehouse_breakdown'] ?? collect())->isNotEmpty())
                <div style="display:grid; gap:8px;">
                    <strong style="font-size:14px;">Warehouse Reconciliation</strong>
                    <div style="overflow:auto;">
                        <table style="width:100%; border-collapse:separate; border-spacing:0; min-width:900px;">
                            <thead>
                                <tr style="background:#f8fafc;">
                                    <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Warehouse</th>
                                    <th style="text-align:left; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">City</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Total</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Available</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Active Rented</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Reserved / Assigned</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Awaiting Verification</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Maintenance</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Transfer / In Transit</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Retired / Scrap</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">Needs Reconciliation</th>
                                    <th style="text-align:center; padding:12px 14px; font-size:12px; text-transform:uppercase; color:#64748b;">State Sum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($assetReconciliation['warehouse_breakdown'] as $warehouseRow)
                                    <tr>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0;">{{ $warehouseRow['warehouse_name'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0;">{{ $warehouseRow['city'] ?: '—' }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['total_rental_assets'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['available'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['active_rented'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['reserved'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['awaiting_verification'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['maintenance'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['transfer_in_progress'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['retired'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['unaccounted'] }}</td>
                                        <td style="padding:12px 14px; border-top:1px solid #e2e8f0; text-align:center;">{{ $warehouseRow['state_sum'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if(collect($assetReconciliation['warnings'] ?? [])->isNotEmpty())
                <div style="display:grid; gap:8px;">
                    <strong style="font-size:14px;">Asset Reconciliation Warnings</strong>
                    @foreach(($assetReconciliation['warnings'] ?? []) as $warning)
                        <div style="border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:14px; padding:12px 14px; font-size:13px;">
                            {{ $warning['message'] ?? 'Asset reconciliation warning detected.' }}
                        </div>
                    @endforeach
                </div>
            @endif

            @if(collect($assetReconciliation['unaccounted_assets'] ?? [])->isNotEmpty())
                <div style="display:grid; gap:8px;">
                    <strong style="font-size:14px;">Needs Reconciliation Assets</strong>
                    <div style="display:grid; gap:8px;">
                        @foreach($assetReconciliation['unaccounted_assets'] as $assetWarning)
                            <div style="border:1px dashed #cbd5e1; border-radius:14px; padding:12px 14px; font-size:13px; color:#334155;">
                                Asset #{{ $assetWarning['asset_id'] }} · {{ $assetWarning['product'] }} · {{ $assetWarning['asset_stage'] ?: 'no stage' }} / {{ $assetWarning['asset_status'] ?: 'no status' }} · {{ $assetWarning['warehouse'] ?: 'unassigned warehouse' }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

        <div class="ph-card" style="padding:0; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; overflow:hidden;">
        <div style="padding:18px 20px; border-bottom:1px solid #e2e8f0; display:grid; gap:4px;">
            <strong style="font-size:18px;">Daily Closing Stock Matrix</strong>
            <span style="font-size:13px; color:#64748b;">{{ $bucketMode === 'daily' ? 'Daily' : ($bucketMode === 'weekly' ? 'Weekly' : 'Monthly') }} overview. Cell value = closing available stock for that date range. Click a cell to open the movement drill-down.</span>
        </div>

        <div style="overflow:auto;">
            <table style="width:100%; border-collapse:separate; border-spacing:0; min-width:1200px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="text-align:left; padding:14px 16px; font-size:12px; text-transform:uppercase; color:#64748b; position:sticky; left:0; background:#f8fafc; z-index:3; min-width:260px; border-right:1px solid #e2e8f0;">Product</th>
                        @foreach($dateHeaders as $date)
                            <th style="text-align:center; padding:12px 8px; font-size:12px; text-transform:uppercase; color:#64748b; white-space:nowrap; min-width:76px;">{{ $date['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                @forelse($report['rows'] as $row)
                    <tr>
                        <td style="padding:14px 16px; vertical-align:top; position:sticky; left:0; background:#ffffff; min-width:260px; z-index:2; border-top:1px solid #e2e8f0; border-right:1px solid #e2e8f0;">
                            <div style="display:grid; gap:4px;">
                                <strong>{{ $row['product']->name }}</strong>
                                <span style="font-size:12px; color:#64748b;">{{ $row['product']->category ?: 'Uncategorized' }}@if($row['product']->model_name) · {{ $row['product']->model_name }}@endif</span>
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
                            <td style="padding:8px 6px; vertical-align:top; border-top:1px solid #e2e8f0;">
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
    </div>

    @if($drilldown)
        <div class="ph-card" style="padding:20px; border:1px solid #dbe7f3; border-radius:24px; background:#ffffff; display:grid; gap:14px;">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                <div style="display:grid; gap:4px;">
                    <strong style="font-size:18px;">Movement Drill-down</strong>
                    <span style="font-size:13px; color:#64748b;">{{ $drilldown['product']->name }} · {{ $drilldown['label'] ?? $drilldown['date']->format('d M Y') }}</span>
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
                                    <span style="font-size:12px; color:#64748b;">{{ optional($movement->movement_at)->format('d M Y h:i A') }} · Qty {{ $movement->quantity }}</span>
                                </div>
                                <span style="font-size:12px; color:#64748b;">{{ $movement->performedBy?->name ?: 'System' }}</span>
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; font-size:13px; color:#334155;">
                                <div><strong>From → To:</strong> {{ $movement->from_status ?: '—' }} → {{ $movement->to_status ?: '—' }}</div>
                                <div><strong>Warehouse:</strong> {{ $movement->fromWarehouse?->name ?: '—' }} → {{ $movement->toWarehouse?->name ?: '—' }}</div>
                                <div><strong>Rental / Sale:</strong> {{ $movement->rental?->rental_number ?: $movement->rental_id ?: '—' }} / {{ $movement->sale?->sale_number ?: $movement->sale_id ?: '—' }}</div>
                                <div><strong>Delivery:</strong> {{ $movement->delivery_id ?: '—' }}</div>
                                <div><strong>Customer:</strong> {{ $movement->rental?->customer?->name ?: $movement->sale?->customer?->name ?: $movement->rental?->customer_name ?: '—' }}</div>
                                <div><strong>Asset:</strong> {{ $movement->asset?->serial_number ?: $movement->asset?->barcode_value ?: '—' }}</div>
                                <div style="grid-column:1 / -1;"><strong>Notes:</strong> {{ $movement->notes ?: '—' }}</div>
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
