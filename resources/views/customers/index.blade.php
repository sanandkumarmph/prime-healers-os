@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateCustomers = $currentUser?->canAccessModule('customers', 'create') ?? false;
    $canUpdateCustomers = $currentUser?->canAccessModule('customers', 'update') ?? false;
    $canDeleteCustomers = $currentUser?->canAccessModule('customers', 'delete') ?? false;
    $canReadRentals = $currentUser?->canAccessModule('rentals', 'read') ?? false;
    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canReadSales = $currentUser?->canAccessModule('sales', 'read') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $canReadInvoices = $currentUser?->canAccessModule('invoices', 'read') ?? false;
    $whatsAppEnabled = \App\Models\Customer::hasWhatsappNumberColumn();
    $statusEnabled = \App\Models\Customer::hasStatusColumn();

    $queryFor = function (array $overrides = []) use ($search, $city, $state, $status, $createdDate, $fromDate, $toDate, $sortBy) {
        return array_filter(array_merge([
            'search' => $search ?: null,
            'city' => $city ?: null,
            'state' => $state ?: null,
            'status' => $status ?: null,
            'created_date' => $createdDate ?: null,
            'from_date' => $fromDate ?: null,
            'to_date' => $toDate ?: null,
            'sort_by' => $sortBy ?: null,
        ], $overrides), fn ($value) => $value !== null && $value !== '');
    };

    $whatsAppUrl = function ($customer) {
        $number = \App\Support\WhatsAppHelper::resolveCustomerNumber($customer);

        return \App\Support\WhatsAppHelper::chatUrl($number, $number ? "Hello {$customer->name}, this is a quick update from Prime Healers." : null);
    };
    $customerInitials = function (?string $name): string {
        $parts = collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)->map(fn ($part) => strtoupper(mb_substr($part, 0, 1)));
        return $parts->isNotEmpty() ? $parts->implode('') : 'CU';
    };
    $mobileSortOptions = [
        ['value' => 'latest', 'label' => 'Newest First'],
        ['value' => 'oldest', 'label' => 'Oldest First'],
        ['value' => 'name_asc', 'label' => 'Name A-Z'],
        ['value' => 'name_desc', 'label' => 'Name Z-A'],
    ];
    $currentMobileSortLabel = collect($mobileSortOptions)->firstWhere('value', $sortBy)['label'] ?? 'Newest First';
@endphp

<style>
    .customers-page { padding: 10px 0 24px; display: grid; gap: 14px; width: 100%; max-width: 1280px; min-width: 0; margin: 0 auto; box-sizing: border-box; }
    .customers-shell { display: grid; gap: 16px; min-width: 0; }
    .customers-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
    .customers-title h1 { margin: 10px 0 0; font-size: 30px; letter-spacing:-0.04em; color: var(--ph-color-text); font-family: var(--ph-font-heading); }
    .customers-title p { margin: 8px 0 0; color: var(--ph-color-text-soft); font-size: 13px; line-height:1.55; max-width:720px; }
    .customers-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .ops-card {
        background: var(--ph-color-surface);
        border: 1px solid var(--ph-color-border);
        border-radius: var(--ph-radius-lg);
        box-shadow: var(--ph-shadow-soft);
        min-width: 0;
        max-width: 100%;
    }
    .ops-card-body { padding: 18px; min-width: 0; }
    .desktop-filter-toggle summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        color: var(--ph-color-text);
        font-size: 15px;
        font-weight: 800;
        font-family: var(--ph-font-heading);
    }
    .desktop-filter-toggle summary::-webkit-details-marker { display: none; }
    .desktop-filter-toggle summary span { color: var(--ph-color-text-soft); font-size: 12px; font-weight: 700; }
    .ops-btn,
    .ops-btn-secondary,
    .ops-btn-light,
    .ops-btn-danger,
    .ops-btn-disabled {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 12px;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid transparent;
        cursor: pointer;
        line-height: 1.2;
    }
    .ops-btn { background: var(--ph-color-primary); color: #fff; border-color: var(--ph-color-primary); }
    .ops-btn-secondary { background: var(--ph-color-primary); color: #fff; border-color: var(--ph-color-primary); }
    .ops-btn-light { background: #fff; color: var(--ph-color-text); border-color: var(--ph-color-border-strong); }
    .ops-btn-danger { background: var(--ph-color-danger); color: #fff; }
    .ops-btn-disabled {
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-text-faint);
        border-color: var(--ph-color-border);
        cursor: not-allowed;
    }
    .ops-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 10px;
        align-items: end;
    }
    .ops-field { display: grid; gap: 6px; }
    .ops-field label {
        font-size: 11px;
        font-weight: 700;
        color: var(--ph-color-text-soft);
        text-transform: uppercase;
        letter-spacing: .04em;
        font-family: var(--ph-font-heading);
    }
    .ops-field input,
    .ops-field select {
        width: 100%;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid var(--ph-color-border-strong);
        background: #fff;
        color: var(--ph-color-text);
        font-size: 14px;
    }
    .ops-field input:focus,
    .ops-field select:focus {
        outline: none;
        border-color: rgba(23, 119, 189, 0.55);
        box-shadow: 0 0 0 3px rgba(23, 119, 189, 0.12);
    }
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
    }
    .summary-box {
        display: block;
        padding: 16px;
        border-radius: 18px;
        border: 1px solid var(--ph-color-border);
        background: #ffffff;
        text-decoration: none;
        color: inherit;
        box-shadow: var(--ph-shadow-soft);
    }
    .summary-box span {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--ph-color-text-soft);
        font-weight: 700;
        margin-bottom: 8px;
        font-family: var(--ph-font-heading);
    }
    .summary-box strong {
        font-size: 20px;
        color: var(--ph-color-text);
        line-height: 1;
        font-family: var(--ph-font-heading);
    }
    .summary-box small {
        display: block;
        margin-top: 8px;
        color: var(--ph-color-text-soft);
        font-size: 12px;
    }
    .customers-table-wrap { overflow-x: auto; width: 100%; max-width: 100%; min-width: 0; }
    .customers-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 980px;
    }
    .customers-table th,
    .customers-table td {
        padding: 12px 10px;
        border-bottom: 1px solid var(--ph-color-border);
        vertical-align: top;
        text-align: left;
        font-size: 13px;
    }
    .customers-table th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--ph-color-text-soft);
        font-weight: 700;
        background: var(--ph-color-surface-soft);
        position: sticky;
        top: 0;
        font-family: var(--ph-font-heading);
    }
    .customers-table tr:hover td { background: #f7fbfe; }
    .bulk-col {
        width: 34px;
        text-align: center !important;
    }
    .serial-col {
        width: 56px;
        text-align: center !important;
        color: var(--ph-color-text-soft);
        font-weight: 700;
    }
    .bulk-col input[type="checkbox"] {
        width: 16px;
        height: 16px;
        cursor: pointer;
    }
    .bulk-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .bulk-actions strong {
        color: var(--ph-color-text);
        font-size: 13px;
        font-family: var(--ph-font-heading);
    }
    .bulk-actions button:disabled {
        opacity: .45;
        cursor: not-allowed;
    }
    .customer-name {
        font-size: 14px;
        font-weight: 700;
        color: var(--ph-color-text);
        margin: 0 0 4px;
        font-family: var(--ph-font-heading);
    }
    .customer-sub {
        color: var(--ph-color-text-soft);
        font-size: 12px;
        margin: 0;
    }
    .pill {
        display: inline-flex;
        align-items: center;
        padding: 4px 9px;
        border-radius: 999px;
        background: var(--ph-color-info-soft);
        color: var(--ph-color-primary);
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }
    .pill-muted {
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-text-soft);
    }
    .count-stack { display: flex; flex-wrap: wrap; gap: 6px; }
    .action-row { display: flex; flex-wrap: wrap; gap: 6px; align-items:flex-start; }
    .customer-icon-action {
        width:34px;
        height:34px;
        border-radius:12px;
        border:1px solid var(--ph-color-border);
        background:#fff;
        color:var(--ph-color-text);
        display:inline-grid;
        place-items:center;
        text-decoration:none;
        box-shadow:var(--ph-shadow-soft);
    }
    .customer-icon-action svg { width:15px; height:15px; }
    .customer-icon-action.is-primary { background:var(--ph-color-primary); border-color:var(--ph-color-primary); color:#fff; }
    .customer-icon-action.is-whatsapp { background:var(--ph-color-success-soft); border-color:rgba(14,159,75,.18); color:var(--ph-color-success); }
    .customer-action-menu { position:relative; display:inline-block; }
    .customer-action-menu summary {
        list-style:none; width:34px; height:34px; border-radius:12px; border:1px solid var(--ph-color-border);
        background:#fff; color:var(--ph-color-text); display:grid; place-items:center; cursor:pointer; font-weight:900;
        box-shadow:var(--ph-shadow-soft);
    }
    .customer-action-menu summary::-webkit-details-marker { display:none; }
    .customer-action-menu[open] summary { background:var(--ph-color-info-soft); color:var(--ph-color-primary); border-color:rgba(23,119,189,.18); }
    .customer-action-panel {
        position:absolute; right:0; top:40px; z-index:30; min-width:180px;
        display:grid; gap:6px; padding:8px; border:1px solid var(--ph-color-border); border-radius:14px;
        background:#fff; box-shadow:var(--ph-shadow-float);
    }
    .customer-action-link,
    .customer-action-panel button {
        display:flex; align-items:center; justify-content:flex-start; min-height:34px; padding:8px 10px;
        border-radius:10px; border:1px solid var(--ph-color-border); background:#fff; color:var(--ph-color-text); text-decoration:none; font-size:12px; font-weight:700; cursor:pointer;
    }
    .customer-action-panel .danger {
        color:var(--ph-color-danger);
        background:var(--ph-color-danger-soft);
        border-color:rgba(179,13,35,.18);
    }
    .mobile-chip-row { display: none; }
    .mobile-list-command { display: none; }
    .empty-state {
        padding: 28px 18px;
        text-align: center;
        color: var(--ph-color-text-soft);
        font-size: 14px;
    }
    .status-tag {
        display: inline-flex;
        align-items: center;
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
        background: var(--ph-color-success-soft);
        color: var(--ph-color-success);
    }
    .status-tag.inactive {
        background: var(--ph-color-surface-soft);
        color: var(--ph-color-text-soft);
    }
    .section-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }
    .section-title h2 {
        margin: 0;
        font-size: 20px;
        letter-spacing:-0.02em;
        color: var(--ph-color-text);
        font-family: var(--ph-font-heading);
    }
    .section-title p {
        margin: 4px 0 0;
        color: var(--ph-color-text-soft);
        font-size: 12px;
    }
    .pagination-wrap nav { margin-top: 6px; }
    @media (max-width: 1200px) {
        .customers-page { padding: 16px; }
        .customers-table { min-width: 920px; }
    }
    @media (max-width: 820px) {
        .customers-page { padding: 14px; }
        .ops-form-grid { grid-template-columns: 1fr; }
        .customers-actions { width: 100%; }
    }
    @media (max-width: 767px) {
        .customers-page {
            padding: 6px 0 18px;
            gap: 8px;
        }
        .customers-shell {
            gap: 8px;
        }
        .customers-header {
            display: none;
        }
        .desktop-filter-card,
        .desktop-summary-grid {
            display: none !important;
        }
        .mobile-list-command {
            position: relative;
            z-index: 20;
            display: grid;
            gap: 8px;
            padding: 8px;
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 8px 22px rgba(15,23,42,.04);
            pointer-events: auto;
        }
        .mobile-search-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
        }
        .mobile-search-row input {
            width: 100%;
            min-height: 40px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 16px;
            box-sizing: border-box;
        }
        .mobile-search-row .ops-btn-secondary {
            min-height: 40px;
            border-radius: 12px;
            padding: 8px 12px;
        }
        .mobile-stat-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
        }
        .mobile-stat-strip a {
            display: grid;
            gap: 2px;
            min-width: 0;
            padding: 7px 8px;
            border: 1px solid var(--ph-color-border);
            border-radius: 12px;
            background: var(--ph-color-surface-soft);
            color: var(--ph-color-text);
            text-decoration: none;
        }
        .mobile-stat-strip span {
            color: var(--ph-color-text-soft);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .05em;
            overflow: hidden;
            text-overflow: ellipsis;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .mobile-stat-strip strong {
            font-size: 16px;
            line-height: 1;
        }
        .mobile-filter-toggle {
            position: relative;
            z-index: 21;
            border: 1px solid var(--ph-color-border);
            border-radius: 12px;
            background: var(--ph-color-surface-soft);
            overflow: hidden;
            pointer-events: auto;
        }
        .mobile-filter-toggle summary {
            align-items: center;
            color: var(--ph-color-text);
            cursor: pointer;
            display: flex;
            font-size: 12px;
            font-weight: 800;
            justify-content: space-between;
            list-style: none;
            min-height: 38px;
            padding: 8px 10px;
            user-select: none;
        }
        .mobile-filter-toggle summary::-webkit-details-marker {
            display: none;
        }
        .mobile-filter-body {
            display: grid;
            gap: 8px;
            padding: 0 10px 10px;
        }
        .customers-shell > .mobile-chip-row {
            display: none;
        }
        .mobile-chip-row {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 2px 1px 4px;
            scrollbar-width: none;
        }
        .mobile-chip-row::-webkit-scrollbar { display: none; }
        .mobile-chip {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 7px 11px;
            border-radius: 999px;
            border: 1px solid var(--ph-color-border-strong);
            background: #fff;
            color: var(--ph-color-text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
        }
        .mobile-chip.is-active { background: var(--ph-color-sidebar); color: #fff; border-color: var(--ph-color-sidebar); }
        .customers-table-wrap { overflow-x: visible; }
        .customers-table {
            min-width: 0;
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        .customers-table thead { display: none; }
        .customers-table,
        .customers-table tbody,
        .customers-table tr,
        .customers-table td {
            display: block;
            width: 100%;
        }
        .customers-table tr {
            border: 1px solid var(--ph-color-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: var(--ph-shadow-soft);
            overflow: hidden;
        }
        .customers-table tr:hover td { background: #fff; }
        .customers-table td {
            display: grid;
            grid-template-columns: 104px minmax(0, 1fr);
            gap: 10px;
            padding: 9px 12px;
            border-bottom: 1px solid var(--ph-color-border);
            background: #fff;
        }
        .customers-table td:last-child { border-bottom: none; }
        .customers-table td::before {
            content: attr(data-label);
            color: var(--ph-color-text-soft);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .customers-table td.bulk-col {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .customers-table td.serial-col {
            display: grid;
            grid-template-columns: 104px minmax(0, 1fr);
            text-align: left !important;
        }
        .customers-table td.customer-mobile-whatsapp {
            display: none !important;
        }
        .customer-action-panel { position:static; min-width:0; margin-top:8px; box-shadow:none; }
    }
    @media (max-width: 560px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container customers-page">
    <div class="customers-shell">
        <div class="customers-header">
            <div class="customers-title">
                <div class="rx-eyebrow">Customer CRM</div>
                <h1>Customers</h1>
                <p>Search, contact, and open customer-linked work from one clean list.</p>
            </div>
            <div class="customers-actions">
                <a href="{{ route('customers.export.csv', $queryFor()) }}" class="ops-btn-light">Export CSV</a>
                @if($canCreateCustomers)
                    <a href="{{ route('customers.create') }}" class="ops-btn">+ Add Customer</a>
                @endif
            </div>
        </div>

        <div class="mobile-chip-row" aria-label="Customer quick filters">
            <a href="{{ route('customers.index') }}" class="mobile-chip {{ blank($status) && blank($city) && blank($state) ? 'is-active' : '' }}">All</a>
            @if($statusEnabled)
                <a href="{{ route('customers.index', $queryFor(['status' => 'active'])) }}" class="mobile-chip {{ $status === 'active' ? 'is-active' : '' }}">Active</a>
                <a href="{{ route('customers.index', $queryFor(['status' => 'inactive'])) }}" class="mobile-chip {{ $status === 'inactive' ? 'is-active' : '' }}">Inactive</a>
            @endif
            @if($canReadRentals)
                <a href="{{ route('rentals.index', ['status' => 'active']) }}" class="mobile-chip">Active Rentals</a>
            @endif
            @if($canReadInvoices)
                <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}" class="mobile-chip">Unpaid Invoices</a>
            @endif
        </div>

        @if(session('success'))
            <div class="ops-card">
                <div class="ops-card-body" style="color:var(--ph-color-success); background:var(--ph-color-success-soft); border-radius:14px;">
                    {{ session('success') }}
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="ops-card">
                <div class="ops-card-body" style="color:var(--ph-color-danger); background:var(--ph-color-danger-soft); border-radius:14px;">
                    {{ session('error') }}
                </div>
            </div>
        @endif

        <div class="mobile-list-command" aria-label="Mobile customer controls">
            <form method="GET" action="{{ route('customers.index') }}" class="mobile-search-row">
                @foreach(request()->except(['search', 'page']) as $key => $value)
                    @if(is_scalar($value) && $value !== '')
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="search" name="search" value="{{ $search }}" placeholder="Search customer, phone, city">
                <button type="submit" class="ops-btn-secondary">Search</button>
            </form>

            <div class="mobile-stat-strip" aria-label="Customer summary">
                <a href="{{ route('customers.index', $queryFor()) }}"><span>Total</span><strong>{{ number_format($totalCustomers) }}</strong></a>
                @if($canReadRentals)
                    <a href="{{ route('rentals.index', ['status' => 'active']) }}"><span>Rentals</span><strong>{{ number_format($activeRentals) }}</strong></a>
                @endif
                @if($canReadInvoices)
                    <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}"><span>Invoices</span><strong>{{ number_format($totalInvoices) }}</strong></a>
                @endif
            </div>

            <div class="mobile-chip-row" aria-label="Customer quick filters">
                <a href="{{ route('customers.index') }}" class="mobile-chip {{ blank($status) && blank($city) && blank($state) ? 'is-active' : '' }}">All</a>
                @if($statusEnabled)
                    <a href="{{ route('customers.index', $queryFor(['status' => 'active'])) }}" class="mobile-chip {{ $status === 'active' ? 'is-active' : '' }}">Active</a>
                    <a href="{{ route('customers.index', $queryFor(['status' => 'inactive'])) }}" class="mobile-chip {{ $status === 'inactive' ? 'is-active' : '' }}">Inactive</a>
                @endif
                @if($canReadRentals)
                    <a href="{{ route('rentals.index', ['status' => 'active']) }}" class="mobile-chip">Active Rentals</a>
                @endif
            </div>

            <div class="mobile-action-toolbar" aria-label="Mobile customer filters and sorting">
                <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="customers-mobile-filters">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>
                    <span>Filter</span>
                </button>
                <div class="mobile-sort-anchor" data-mobile-sort-root>
                    <button type="button" class="mobile-toolbar-btn" data-mobile-sort-trigger>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m7 15 5 5 5-5"/><path d="M7 9 12 4l5 5"/></svg>
                        <span>{{ $currentMobileSortLabel }}</span>
                    </button>
                    <div class="mobile-sort-popover" data-mobile-sort-menu hidden>
                        @foreach($mobileSortOptions as $option)
                            <a href="{{ route('customers.index', $queryFor(['sort_by' => $option['value']])) }}" class="mobile-sort-option {{ $sortBy === $option['value'] ? 'is-active' : '' }}">{{ $option['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div id="customers-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
            <div class="mobile-filter-sheet-panel">
                <div class="mobile-filter-sheet-header">
                    <div>
                        <h3>Customer Filters</h3>
                        <p>Keep city, state, and status controls easy to reach on mobile.</p>
                    </div>
                    <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="customers-mobile-filters" aria-label="Close filters">×</button>
                </div>
                <div class="mobile-filter-sheet-body">
                    <form method="GET" action="{{ route('customers.index') }}" class="mobile-sheet-form">
                        <input type="hidden" name="search" value="{{ $search }}">
                        <input type="hidden" name="sort_by" value="{{ $sortBy }}">
                        <div class="mobile-sheet-grid">
                            <div class="mobile-sheet-field">
                                <label for="mobile_city">City</label>
                                <select id="mobile_city" name="city">
                                    <option value="">All cities</option>
                                    @foreach($cities as $cityOption)
                                        <option value="{{ $cityOption }}" {{ $city === $cityOption ? 'selected' : '' }}>{{ $cityOption }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mobile-sheet-field">
                                <label for="mobile_state">State</label>
                                <select id="mobile_state" name="state">
                                    <option value="">All states</option>
                                    @foreach($states as $stateOption)
                                        <option value="{{ $stateOption }}" {{ $state === $stateOption ? 'selected' : '' }}>{{ $stateOption }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($statusEnabled)
                                <div class="mobile-sheet-field">
                                    <label for="mobile_status">Status</label>
                                    <select id="mobile_status" name="status">
                                        <option value="">All</option>
                                        <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                                        <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                    </select>
                                </div>
                            @endif
                            <div class="mobile-sheet-field">
                                <label for="mobile_created_date">Created Date</label>
                                <input id="mobile_created_date" type="date" name="created_date" value="{{ $createdDate }}">
                            </div>
                        </div>
                        <div class="mobile-sheet-actions">
                            <button type="submit" class="ops-btn-secondary">Apply</button>
                            <a href="{{ route('customers.index') }}" class="ops-btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <details class="ops-card desktop-filter-card desktop-filter-toggle">
            <summary>Filter / Sort <span>{{ $search || $city || $state || $status || $createdDate || $fromDate || $toDate ? 'Active' : 'Expand' }}</span></summary>
            <div class="ops-card-body">
                <form method="GET" action="{{ route('customers.index') }}" style="display:grid; gap:12px;">
                    <div class="ops-form-grid">
                        <div class="ops-field">
                            <label for="search">Search</label>
                            <input id="search" type="text" name="search" value="{{ $search }}" placeholder="Search customer, phone, WhatsApp, city, state">
                        </div>
                        <div class="ops-field">
                            <label for="city">City</label>
                            <select id="city" name="city">
                                <option value="">All cities</option>
                                @foreach($cities as $cityOption)
                                    <option value="{{ $cityOption }}" {{ $city === $cityOption ? 'selected' : '' }}>{{ $cityOption }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ops-field">
                            <label for="state">State</label>
                            <select id="state" name="state">
                                <option value="">All states</option>
                                @foreach($states as $stateOption)
                                    <option value="{{ $stateOption }}" {{ $state === $stateOption ? 'selected' : '' }}>{{ $stateOption }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($statusEnabled)
                            <div class="ops-field">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="">All</option>
                                    <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                </select>
                            </div>
                        @endif
                        <div class="ops-field">
                            <label for="created_date">Created Date</label>
                            <input id="created_date" type="date" name="created_date" value="{{ $createdDate }}">
                        </div>
                        <div class="ops-field">
                            <label for="sort_by">Sort By</label>
                            <select id="sort_by" name="sort_by">
                                <option value="latest" {{ $sortBy === 'latest' ? 'selected' : '' }}>Newest First</option>
                                <option value="oldest" {{ $sortBy === 'oldest' ? 'selected' : '' }}>Oldest First</option>
                                <option value="name_asc" {{ $sortBy === 'name_asc' ? 'selected' : '' }}>Name A-Z</option>
                                <option value="name_desc" {{ $sortBy === 'name_desc' ? 'selected' : '' }}>Name Z-A</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit" class="ops-btn-secondary">Apply Filters</button>
                        <a href="{{ route('customers.index') }}" class="ops-btn-light">Reset</a>
                    </div>
                </form>
            </div>
        </details>

        <div class="summary-grid desktop-summary-grid">
            <a href="{{ route('customers.index', $queryFor()) }}" class="summary-box rn-summary-link">
                <span class="rn-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><path d="M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <span>Total Customers</span>
                <strong>{{ number_format($totalCustomers) }}</strong>
                <small>Based on current filters</small>
            </a>
            @if($canReadRentals)
            <a href="{{ route('rentals.index', $queryFor(['status' => 'active'])) }}" class="summary-box rn-summary-link">
                <span class="rn-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1Z"/><path d="M8 12h4"/><path d="M8 16h8"/></svg>
                </span>
                <span>Active Rentals</span>
                <strong>{{ number_format($activeRentals) }}</strong>
                <small>Open rental workload from selected customers</small>
            </a>
            <a href="{{ route('rentals.index', $queryFor()) }}" class="summary-box rn-summary-link">
                <span class="rn-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16"/><path d="M4 18h10"/><path d="M4 6h16"/></svg>
                </span>
                <span>Total Rentals</span>
                <strong>{{ number_format($totalRentals) }}</strong>
                <small>Full rental history tied to this filtered list</small>
            </a>
            @endif
            @if($canReadSales)
            <a href="{{ route('sales.index', $queryFor()) }}" class="summary-box rn-summary-link">
                <span class="rn-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
                </span>
                <span>Total Sales</span>
                <strong>{{ number_format($totalSales) }}</strong>
                <small>Sales linked to the visible customers</small>
            </a>
            @endif
            @if($canReadInvoices)
            <a href="{{ route('invoices.index', $queryFor()) }}" class="summary-box rn-summary-link">
                <span class="rn-summary-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h3"/></svg>
                </span>
                <span>Total Invoices</span>
                <strong>{{ number_format($totalInvoices) }}</strong>
                <small>Invoice volume for the current list</small>
            </a>
            @endif
        </div>

        <div class="ops-card rn-table-shell">
            <div class="ops-card-body">
                <form id="customerBulkExportForm" method="POST" action="{{ route('customers.bulk.export.csv') }}" style="display:none;">
                    @csrf
                </form>
                @if($canDeleteCustomers)
                    <form id="customerBulkDeleteForm" method="POST" action="{{ route('customers.bulk.delete') }}" style="display:none;">
                        @csrf
                        @method('DELETE')
                    </form>
                @endif

                <div class="section-title">
                    <div>
                        <h2>Customer List</h2>
                        <p>Compact daily operations view with drilldowns into rentals, sales, and invoices.</p>
                    </div>
                    <div class="bulk-actions">
                        <strong><span id="customerSelectedCount">0</span> selected</strong>
                        <button type="button" class="ops-btn-light" id="bulkCustomerExport" disabled>Export Selected CSV</button>
                        @if($canDeleteCustomers)
                            <button type="button" class="ops-btn-danger" id="bulkCustomerDelete" disabled>Delete Selected</button>
                        @endif
                    </div>
                </div>

                <div class="customers-table-wrap">
                    <table class="customers-table">
                        <thead>
                            <tr>
                                <th class="bulk-col">
                                    <input type="checkbox" id="customerSelectAll" aria-label="Select all customers on this page">
                                </th>
                                <th class="serial-col">#</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>WhatsApp</th>
                                <th>Location</th>
                                @if($canReadRentals)<th>Rentals</th>@endif
                                @if($canReadSales)<th>Sales</th>@endif
                                @if($canReadInvoices)<th>Invoices</th>@endif
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($customers as $customer)
                                @php
                                    $customerWhatsapp = $whatsAppUrl($customer);
                                    $preferredWhatsapp = $customer->preferredWhatsAppNumber();
                                    $rowNumber = method_exists($customers, 'firstItem') && $customers->firstItem()
                                        ? $customers->firstItem() + $loop->index
                                        : $loop->iteration;
                                @endphp
                                <tr>
                                    <td class="bulk-col" data-label="Select">
                                        <input type="checkbox" class="customer-bulk-check" value="{{ $customer->id }}" aria-label="Select {{ $customer->name }}">
                                    </td>
                                    <td class="serial-col" data-label="No.">{{ $rowNumber }}</td>
                                    <td data-label="Customer">
                                        <div class="rn-customer-block">
                                            <div class="rn-customer-avatar">{{ $customerInitials($customer->name) }}</div>
                                            <div class="rn-name-stack">
                                                @if(\Illuminate\Support\Facades\Route::has('customers.show'))
                                                    <a href="{{ route('customers.show', $customer) }}" class="title rn-record-link">{{ $customer->name }}</a>
                                                @else
                                                    <span class="title">{{ $customer->name }}</span>
                                                @endif
                                                <span class="meta">
                                                    @if(!empty($customer->company_name))
                                                        {{ $customer->company_name }}
                                                    @elseif(!empty(trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))))
                                                        {{ trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) }}
                                                    @else
                                                        Customer record
                                                    @endif
                                                </span>
                                                @if($statusEnabled && !empty($customer->status))
                                                    <span class="rn-badge {{ $customer->status === 'active' ? 'rn-badge-active' : 'rn-badge-muted' }}" style="width:max-content;">{{ $customer->status }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Phone">{{ $customer->phone ?: '-' }}</td>
                                    <td data-label="WhatsApp" class="customer-mobile-whatsapp">
                                        @if($whatsAppEnabled || $customer->phone)
                                            <div style="display:grid; gap:6px;">
                                                <span>{{ $preferredWhatsapp ?: '-' }}</span>
                                                @if($customerWhatsapp)
                                                    <a href="{{ $customerWhatsapp }}" target="_blank" class="pill">WhatsApp</a>
                                                @else
                                                    <span class="pill pill-muted">Not Available</span>
                                                @endif
                                            </div>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td data-label="Location">
                                        <div>{{ $customer->city ?: '-' }}</div>
                                        <div class="customer-sub">{{ $customer->state ?: '-' }}</div>
                                    </td>
                                    @if($canReadRentals)
                                    <td data-label="Rentals">
                                        <div class="count-stack">
                                            <a href="{{ route('rentals.index', ['customer_id' => $customer->id]) }}" class="pill">
                                                Total {{ $customer->rentals_count ?? 0 }}
                                            </a>
                                            <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'status' => 'active']) }}" class="pill">
                                                Active {{ $customer->active_rentals_count ?? 0 }}
                                            </a>
                                        </div>
                                    </td>
                                    @endif
                                    @if($canReadSales)
                                    <td data-label="Sales">
                                        <a href="{{ route('sales.index', ['customer_id' => $customer->id]) }}" class="pill">
                                            {{ $customer->sales_count ?? 0 }} Sales
                                        </a>
                                    </td>
                                    @endif
                                    @if($canReadInvoices)
                                    <td data-label="Invoices">
                                        <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="pill">
                                            {{ $customer->invoices_count ?? 0 }} Invoices
                                        </a>
                                    </td>
                                    @endif
                                    <td data-label="Actions">
                                        <div class="action-row">
                                            @if($customer->phone)
                                                <a href="tel:{{ preg_replace('/\D+/', '', $customer->phone) }}" class="customer-icon-action is-primary" title="Call {{ $customer->name }}" aria-label="Call {{ $customer->name }}">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 1.8l-1.3 1.3a16 16 0 0 0 6.4 6.4l1.3-1.3a2 2 0 0 1 1.8-.6l3 .5A2 2 0 0 1 22 16.9Z"/></svg>
                                                </a>
                                            @endif
                                            @if($customerWhatsapp)
                                                <a href="{{ $customerWhatsapp }}" target="_blank" class="customer-icon-action is-whatsapp" title="WhatsApp {{ $customer->name }}" aria-label="WhatsApp {{ $customer->name }}">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                                </a>
                                            @endif
                                            <details class="customer-action-menu">
                                                <summary aria-label="More actions for {{ $customer->name }}">...</summary>
                                                <div class="customer-action-panel">
                                                    <a href="{{ route('customers.show', $customer) }}" class="customer-action-link">View</a>
                                                    @if($canUpdateCustomers)
                                                        <a href="{{ route('customers.edit', $customer) }}" class="customer-action-link">Edit</a>
                                                    @endif
                                                    @if($canCreateRentals)
                                                        <a href="{{ route('rentals.create', ['customer_id' => $customer->id]) }}" class="customer-action-link">Create Rental</a>
                                                    @endif
                                                    @if($canCreateSales)
                                                        <a href="{{ route('sales.create', ['customer_id' => $customer->id]) }}" class="customer-action-link">Create Sale</a>
                                                    @endif
                                                    @if($canReadInvoices)
                                                        <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="customer-action-link">View Invoices</a>
                                                    @endif
                                                    @if($canDeleteCustomers)
                                                        <form method="POST" action="{{ route('customers.destroy', $customer) }}" style="margin:0;" onsubmit="return confirm('Delete this customer? This will be blocked if dependencies exist.');">
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
                                    <td colspan="{{ 7 + ($canReadRentals ? 1 : 0) + ($canReadSales ? 1 : 0) + ($canReadInvoices ? 1 : 0) }}" class="empty-state">
                                        No customers match this view yet. Try a broader filter or add a new customer to get started.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pagination-wrap">
                    {{ $customers->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@if($canCreateCustomers)
    @include('partials.mobile-fab', ['href' => route('customers.create'), 'label' => 'Add Customer'])
@endif

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const selectAll = document.getElementById('customerSelectAll');
        const checks = Array.from(document.querySelectorAll('.customer-bulk-check'));
        const countLabel = document.getElementById('customerSelectedCount');
        const exportButton = document.getElementById('bulkCustomerExport');
        const deleteButton = document.getElementById('bulkCustomerDelete');
        const exportForm = document.getElementById('customerBulkExportForm');
        const deleteForm = document.getElementById('customerBulkDeleteForm');

        const selectedIds = () => checks
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => checkbox.value);

        const clearFormIds = (form) => {
            form.querySelectorAll('input[name="customer_ids[]"]').forEach((input) => input.remove());
        };

        const appendIds = (form, ids) => {
            clearFormIds(form);
            ids.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'customer_ids[]';
                input.value = id;
                form.appendChild(input);
            });
        };

        const refreshBulkState = () => {
            const ids = selectedIds();
            const hasSelection = ids.length > 0;

            if (countLabel) {
                countLabel.textContent = ids.length;
            }

            if (exportButton) {
                exportButton.disabled = !hasSelection;
            }

            if (deleteButton) {
                deleteButton.disabled = !hasSelection;
            }

            if (selectAll) {
                selectAll.checked = checks.length > 0 && ids.length === checks.length;
                selectAll.indeterminate = ids.length > 0 && ids.length < checks.length;
            }
        };

        selectAll?.addEventListener('change', () => {
            checks.forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });
            refreshBulkState();
        });

        checks.forEach((checkbox) => {
            checkbox.addEventListener('change', refreshBulkState);
        });

        exportButton?.addEventListener('click', () => {
            const ids = selectedIds();
            if (!ids.length || !exportForm) {
                return;
            }

            appendIds(exportForm, ids);
            exportForm.submit();
        });

        deleteButton?.addEventListener('click', () => {
            const ids = selectedIds();
            if (!ids.length || !deleteForm) {
                return;
            }

            if (!confirm(`Delete ${ids.length} selected customer(s)? Customers with rentals, sales, invoices, or payments will be skipped.`)) {
                return;
            }

            appendIds(deleteForm, ids);
            deleteForm.submit();
        });

        refreshBulkState();
    });
</script>
@endsection
