@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canManageBulkRecords = $currentUser?->can('manage-bulk-records') ?? false;
    $canCreateCustomers = $currentUser?->canAccessModule('customers', 'create') ?? false;
    $canUpdateCustomers = $currentUser?->canAccessModule('customers', 'update') ?? false;
    $canDeleteCustomers = $currentUser?->canAccessModule('customers', 'delete') ?? false;
    $canReadRentals = $currentUser?->canAccessModule('rentals', 'read') ?? false;
    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canReadSales = $currentUser?->canAccessModule('sales', 'read') ?? false;
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $canReadInvoices = $currentUser?->canAccessModule('invoices', 'read') ?? false;
    $canReadActivity = $canReadRentals || $canReadSales || $canReadInvoices;
    $whatsAppEnabled = \App\Models\Customer::hasWhatsappNumberColumn();
    $statusEnabled = \App\Models\Customer::hasStatusColumn();
    $hasCommunicationCenter = $currentUser && !$currentUser->isFinance()
        && \Illuminate\Support\Facades\Route::has('communication-center.index');

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

    $sortOptions = [
        'latest' => 'Newest first',
        'oldest' => 'Oldest first',
        'name_asc' => 'Name A–Z',
        'name_desc' => 'Name Z–A',
    ];
    $singleLegacyDate = filled($createdDate) && $fromDate === $toDate;
    $activeFilters = array_filter([
        'search' => filled($search) ? 'Search: ' . $search : null,
        'city' => filled($city) ? 'City: ' . $city : null,
        'state' => filled($state) ? 'State: ' . $state : null,
        'status' => filled($status) ? 'Status: ' . ucfirst($status) : null,
        'created_date' => $singleLegacyDate ? 'Created: ' . $fromDate : null,
        'from_date' => !$singleLegacyDate && filled($fromDate) ? 'From: ' . $fromDate : null,
        'to_date' => !$singleLegacyDate && filled($toDate) ? 'To: ' . $toDate : null,
        'sort_by' => $sortBy !== 'latest' ? 'Sort: ' . ($sortOptions[$sortBy] ?? $sortOptions['latest']) : null,
    ]);
    $hasActiveFilters = $activeFilters !== [];
    $columnCount = 5 + (int) $canManageBulkRecords + (int) $canReadActivity;

    $customerInitials = function (string $name): string {
        return collect(preg_split('/\s+/', trim($name)))->filter()->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'CU';
    };
@endphp

<style>
    .customers-page {
        --directory-ink: #172944;
        --directory-muted: #52657f;
        --directory-line: #dce5ef;
        --directory-soft: #f4f8fc;
        --directory-blue: #174f8e;
        display: grid;
        gap: 10px;
        width: 100%;
        max-width: 1460px;
        min-width: 0;
        margin: 0 auto;
        color: var(--directory-ink);
    }
    .customers-page .customers-header {
        display: grid;
        grid-template: "title search actions" / auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 10px 16px;
    }
    .customers-header h1 { grid-area: title; }
    .customers-page :is(.customer-search, .customers-actions, .customer-shortcuts, .customer-bulk-toolbar, .customer-status-links) {
        display: flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
    }
    .customer-search { grid-area: search; }
    .customer-search label { flex: 1; min-width: 0; }
    .customers-actions { grid-area: actions; justify-content: flex-end; }
    .customers-page :is(.customer-search input, .customer-filter-form input:not([type="hidden"]), .customer-filter-form select) {
        box-sizing: border-box;
        width: 100%;
        min-width: 0;
        min-height: 38px;
        padding: 7px 10px;
        border: 1px solid #becde0;
        border-radius: 8px;
        background: #fff;
        color: var(--directory-ink);
        font-size: 13px;
    }
    .customers-page :is(.ops-btn, .ops-btn-light, .customer-delete-selected) {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 36px;
        padding: 7px 10px;
        border: 1px solid #c7d6e6;
        border-radius: 8px;
        background: #fff;
        color: var(--directory-blue);
        font-size: 13px;
        font-weight: 650;
        line-height: 1.2;
        text-align: center;
        text-decoration: none;
        cursor: pointer;
    }
    .customers-page .ops-btn { background: #0f75bd; border-color: #0f75bd; color: #fff; }
    .customers-page .ops-btn:hover { background: #0b5e99; }
    .customers-page .ops-btn-light:hover { background: var(--directory-soft); }
    .customers-page .customer-delete-selected { color: #a52230; background: #fff5f6; border-color: #e7bec3; }
    .customers-page :focus-visible { outline: 2px solid #2361ad; outline-offset: 2px; }
    .customers-actions :is(a, summary) { white-space: nowrap; }
    .customers-actions .phos-list-filter-toggle { display: flex; gap: 6px; width: auto; }
    .customers-actions .phos-list-filter-toggle::after { content: "Filters"; }
    .customer-export-action { width: 36px; flex: 0 0 36px; }
    .customer-add-short { display: none; }
    .customer-filter-form { display: grid; gap: 12px; }
    .customer-filter-form .customer-filter-grid label {
        display: grid;
        gap: 4px;
        font-size: 12px;
        font-weight: 650;
    }
    .customer-filter-actions { display: flex; gap: 8px; }
    .customer-summary,
    .customer-directory {
        min-width: 0;
        border: 1px solid var(--directory-line);
        border-radius: 12px;
        background: #fff;
    }
    .customer-summary {
        display: flex;
        flex-wrap: wrap;
        overflow: hidden;
        padding: 3px;
        background: var(--directory-soft);
    }
    .customer-summary a {
        flex: 1 1 auto;
        display: inline-flex;
        align-items: baseline;
        justify-content: center;
        gap: 6px;
        padding: 7px 9px;
        border-radius: 7px;
        color: var(--directory-muted);
        font-size: 12px;
        text-decoration: none;
    }
    .customer-summary a:hover { background: #fff; color: var(--directory-blue); }
    .customer-summary a:focus-visible { outline-offset: -2px; }
    .customer-summary strong { color: var(--directory-ink); font-size: 16px; font-variant-numeric: tabular-nums; }
    .customer-list-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 7px 12px;
        padding: 9px 12px;
        border-bottom: 1px solid var(--directory-line);
    }
    .customer-list-toolbar strong { color: var(--directory-ink); font-size: 13px; font-variant-numeric: tabular-nums; }
    .customer-shortcuts { flex-wrap: wrap; }
    .customer-status-links a {
        display: inline-flex;
        min-height: 30px;
        align-items: center;
        padding: 4px 8px;
        border-radius: 6px;
        color: var(--directory-muted);
        font-size: 12px;
        font-weight: 650;
        text-decoration: none;
    }
    .customer-status-links a:hover,
    .customer-status-links a[aria-current="page"] { background: #eaf3fc; color: var(--directory-blue); }
    .customer-quick-links { position: relative; }
    .customer-quick-links summary { color: var(--directory-blue); font-size: 12px; font-weight: 650; cursor: pointer; }
    .customer-quick-links nav {
        position: absolute;
        right: 0;
        top: calc(100% + 8px);
        z-index: 85;
        display: grid;
        min-width: 156px;
        padding: 5px;
        border: 1px solid var(--directory-line);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 10px 24px rgb(23 41 68 / 15%);
    }
    .customer-filter-chips { display: flex; flex-wrap: wrap; gap: 5px; width: 100%; }
    .customer-filter-chips a {
        max-width: 100%;
        padding: 4px 7px;
        border: 1px solid #d4e1f0;
        border-radius: 6px;
        color: var(--directory-blue);
        background: var(--directory-soft);
        font-size: 11px;
        text-decoration: none;
        overflow-wrap: anywhere;
    }
    .customer-filter-chips a:last-child { background: #fff; text-decoration: underline; }
    .customers-page .customer-bulk-toolbar {
        justify-content: flex-start;
        flex-wrap: wrap;
        padding: 7px 12px;
        border-bottom: 1px solid var(--directory-line);
        background: #eef5fd;
    }
    .customer-bulk-toolbar[hidden] { display: none !important; }
    .customer-bulk-toolbar strong { font-size: 12px; }
    .customers-table { width: 100%; table-layout: fixed; border-spacing: 0; border-collapse: separate; }
    body.phos-list-page .customers-page .customers-table :is(th, td) {
        padding: 8px 9px !important;
        text-align: left;
        vertical-align: middle;
        border-bottom: 1px solid var(--directory-line);
    }
    .customers-table thead th { background: var(--directory-soft); }
    .customers-table .bulk-col { width: 36px; }
    .customers-table .serial-col { width: 40px; color: var(--directory-muted); font-variant-numeric: tabular-nums; }
    .customers-table .customer-contact-col { width: 22%; }
    .customers-table .customer-location-col { width: 16%; }
    .customers-table .customer-activity-col { width: 22%; }
    .customers-table .customer-actions-col { width: 120px; }
    body.phos-list-page .customers-page .customers-table th.customer-actions-col { text-align: right; padding-right: 15px !important; }
    .customer-bulk-target {
        display: inline-flex;
        min-height: 28px;
        align-items: center;
        gap: 5px;
        color: var(--directory-muted);
        font-size: 12px;
        cursor: pointer;
    }
    .customers-page .customer-bulk-target input[type="checkbox"] {
        appearance: auto;
        box-sizing: border-box;
        width: 16px;
        height: 16px;
        min-height: 16px !important;
        margin: 0;
        padding: 0;
        border-radius: 2px;
    }
    .customers-table .customer-bulk-target { width: 24px; justify-content: center; }
    .customers-table tbody tr:hover,
    .customers-table tbody tr:has(.customer-bulk-check:checked) { background: #f7fbff; }
    .customer-cell-identity { display: flex; align-items: flex-start; gap: 7px; min-width: 0; }
    .customer-list-avatar {
        flex: 0 0 28px;
        height: 28px;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #eaf3fc;
        color: var(--directory-blue);
        font-size: 11px;
        font-weight: 750;
    }
    .customer-list-name { color: var(--directory-blue); font-size: 13px; font-weight: 750; text-decoration: none; }
    .customer-list-name:hover { text-decoration: underline; }
    .customer-list-name,
    .customer-list-meta,
    .customer-location-text { overflow-wrap: anywhere; }
    .customer-list-meta { display: block; margin-top: 2px; color: var(--directory-muted); font-size: 11px; }
    .customer-status {
        display: inline-block;
        padding: 1px 5px;
        margin-top: 3px;
        border-radius: 5px;
        background: #ecf5ef;
        color: #206043;
        font-size: 10px;
        text-transform: capitalize;
    }
    .customer-status.is-inactive { background: #f1f4f8; color: var(--directory-muted); }
    .customer-contact { display: grid; gap: 2px; overflow-wrap: anywhere; }
    .customer-contact a { color: var(--directory-blue); text-decoration: none; }
    .customer-contact a:hover { text-decoration: underline; }
    .customer-contact small { color: var(--directory-muted); font-size: 11px; }
    .customer-activity-links { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 7px; }
    .customer-activity-links a {
        display: inline-flex;
        gap: 3px;
        align-items: center;
        padding: 2px 5px;
        border-radius: 5px;
        background: var(--directory-soft);
        color: var(--directory-blue);
        font-size: 11px;
        white-space: nowrap;
        text-decoration: none;
    }
    .customer-activity-links a:hover { background: #e5effb; text-decoration: underline; }
    .customer-row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
    .customers-page :is(.customer-icon-action, .customer-action-menu summary) {
        width: 29px;
        height: 29px;
        min-width: 29px;
        min-height: 29px;
        display: grid;
        place-items: center;
        border: 1px solid var(--directory-line);
        border-radius: 7px;
        background: #fff;
        color: var(--directory-blue);
        text-decoration: none;
        cursor: pointer;
    }
    .customer-icon-action svg { width: 14px; height: 14px; }
    .customers-page .customer-icon-action.is-whatsapp { color: #1a6742; background: #eef8f2; border-color: #cfe7d8; }
    .customer-action-menu { position: relative; }
    .customer-action-menu summary { list-style: none; font-weight: 800; }
    .customer-action-menu summary::-webkit-details-marker { display: none; }
    .customers-page :is(.customer-action-menu[open] summary, .customer-icon-action:hover) { background: #eaf3fc; }
    .customers-table tr:has(.customer-action-menu[open]) { position: relative; z-index: 60; }
    .customer-action-panel {
        position: absolute;
        z-index: 1;
        right: 0;
        top: calc(100% + 4px);
        width: 168px;
        max-height: min(65vh, 340px);
        overflow: auto;
        display: grid;
        gap: 2px;
        padding: 5px;
        border: 1px solid var(--directory-line);
        border-radius: 9px;
        background: #fff;
        box-shadow: 0 10px 24px rgb(23 41 68 / 15%);
    }
    .customer-action-menu.opens-up .customer-action-panel { top: auto; bottom: calc(100% + 4px); }
    .customers-page :is(.customer-action-link, .customer-action-panel button, .customer-quick-links nav a) {
        width: 100%;
        min-height: 30px;
        padding: 6px 8px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 6px;
        border: 0;
        border-radius: 6px;
        background: #fff;
        color: var(--directory-ink);
        text-align: left;
        text-decoration: none;
        font-size: 12px;
        cursor: pointer;
    }
    .customers-page :is(.customer-action-link, .customer-action-panel button, .customer-quick-links nav a):hover { background: var(--directory-soft); }
    .customer-action-panel form { margin: 2px 0 0; padding-top: 3px; border-top: 1px solid var(--directory-line); }
    .customers-page .customer-action-panel .danger { color: #a52230; }
    .customer-list-empty { margin: 0; padding: 24px 12px; color: var(--directory-muted); text-align: center; }
    .customer-page-links { padding: 9px 12px; }
    .customer-list-alert { padding: 8px 12px; border-radius: 9px; background: #ecf5ef; color: #206043; font-size: 13px; }
    .customer-list-alert.is-error { background: #fff1f2; color: #a52230; }
    @media (max-width: 1040px) {
        .customers-page .customers-header { grid-template: "title actions" "search search" / minmax(0, 1fr) auto; }
    }
    @media (max-width: 767px) {
        .customers-page { gap: 8px; }
        .customers-page .customers-header { column-gap: 8px; }
        .customers-actions .phos-list-filter-toggle::after { content: none; }
        .customer-add-full { display: none; }
        .customer-add-short { display: inline; }
        .customer-list-toolbar { padding: 7px 9px; }
        .customer-summary a { padding: 5px 6px; font-size: 11px; }
        .customer-summary strong { font-size: 14px; }
        .customers-table,
        .customers-table tbody { display: block; }
        .customers-table thead {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip-path: inset(50%);
        }
        .customers-table .customer-mobile-card {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: start;
            gap: 5px 7px;
            padding: 8px 9px;
            border-bottom: 1px solid var(--directory-line);
        }
        body.phos-list-page .customers-page .customers-table td {
            padding: 0 !important;
            border: 0;
            min-width: 0;
        }
        .customers-table .bulk-col { width: auto; grid-area: 1 / 1; }
        .customers-table .customer-bulk-target { min-height: 34px; }
        .customers-table .serial-col { width: auto; grid-area: 2 / 1; font-size: 11px; }
        .customers-table .customer-identity-col { grid-area: 1 / 2; }
        .customers-table .customer-actions-col { width: auto; grid-area: 1 / 3; }
        .customers-table .customer-contact-col { width: auto; grid-area: 2 / 2 / auto / -1; }
        .customers-table .customer-location-col { width: auto; grid-area: 3 / 2 / auto / -1; }
        .customers-table .customer-activity-col { width: auto; grid-area: 4 / 2 / auto / -1; }
        .customer-contact { display: flex; flex-wrap: wrap; gap: 2px 9px; }
        .customer-location-col .customer-list-meta { display: inline; margin-left: 3px; }
        .customers-page :is(.customer-icon-action, .customer-action-menu summary) { width: 34px; height: 34px; }
        .customers-page .customer-search input { font-size: 16px; }
        .customer-action-panel { width: 180px; }
        .customers-page .customer-action-link, .customers-page .customer-action-panel button { min-height: 34px; }
    }
    @media (max-width: 360px) {
        .customer-list-avatar { display: none; }
        .customers-actions { gap: 4px; }
    }
</style>

<div class="container customers-page" data-phos-compact-page="customers">
    <header class="customers-header phos-page-header">
        <h1>Customers</h1>
        <form method="GET" action="{{ route('customers.index') }}" class="customer-search" data-customer-search role="search">
            @foreach($queryFor(['search' => null]) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <label>
                <span class="sr-only">Search customer, phone, WhatsApp, city, state</span>
                <input id="desktop-customer-search" type="search" name="search" value="{{ $search }}" placeholder="Name, phone or city">
            </label>
            <button type="submit" class="ops-btn-light">Search</button>
        </form>
        <div class="customers-actions">
            @if($canManageBulkRecords)
                <a href="{{ route('customers.export.csv', $queryFor()) }}" class="ops-btn-light customer-export-action" data-ph-action="download" title="Export CSV" aria-label="Export customers as CSV"><x-phos-action-icon name="download" label="Export CSV" /></a>
            @endif
            <x-phos-list-filter id="customers-list-filters" label="Filter and sort customers" :active="$hasActiveFilters">
                <form method="GET" action="{{ route('customers.index') }}" class="customer-filter-form" data-filter-panel data-filter-panel-key="customers-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}">
                    @if(filled($createdDate))<input type="hidden" name="created_date" value="{{ $createdDate }}">@endif
                    @if(!$statusEnabled && filled($status))<input type="hidden" name="status" value="{{ $status }}">@endif
                    <div class="customer-filter-grid">
                        <label for="search">Search
                            <input id="search" type="search" name="search" value="{{ $search }}">
                        </label>
                        <label for="city">City
                            <select id="city" name="city">
                                <option value="">All cities</option>
                                @foreach($cities as $cityOption)
                                    <option value="{{ $cityOption }}" @selected($city === $cityOption)>{{ $cityOption }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label for="state">State
                            <select id="state" name="state">
                                <option value="">All states</option>
                                @foreach($states as $stateOption)
                                    <option value="{{ $stateOption }}" @selected($state === $stateOption)>{{ $stateOption }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if($statusEnabled)
                            <label for="status">Status
                                <select id="status" name="status">
                                    <option value="">All statuses</option>
                                    <option value="active" @selected($status === 'active')>Active</option>
                                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                                </select>
                            </label>
                        @endif
                        <label for="from_date">Created from
                            <input id="from_date" type="date" name="from_date" value="{{ $fromDate }}">
                        </label>
                        <label for="to_date">Created to
                            <input id="to_date" type="date" name="to_date" value="{{ $toDate }}">
                        </label>
                        <label for="sort_by">Sort by
                            <select id="sort_by" name="sort_by">
                                @foreach($sortOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($sortBy === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="customer-filter-actions">
                        <button type="submit" class="ops-btn">Apply filters</button>
                        <a href="{{ route('customers.index') }}" class="ops-btn-light" data-filter-clear="customers-index">Reset</a>
                    </div>
                </form>
            </x-phos-list-filter>
            @if($canCreateCustomers)
                <a href="{{ route('customers.create') }}" class="ops-btn" aria-label="Add Customer"><span class="customer-add-full">Add Customer</span><span class="customer-add-short" aria-hidden="true">Add</span></a>
            @endif
        </div>
    </header>

    @if(session('success'))<div class="customer-list-alert" role="status">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="customer-list-alert is-error" role="alert">{{ session('error') }}</div>@endif

    <nav class="customer-summary" aria-label="Customer totals for current filters" data-customer-summary>
        <a href="{{ route('customers.index', $queryFor()) }}"><strong>{{ number_format($totalCustomers) }}</strong> Customers</a>
        @if($canReadRentals)
            <a href="{{ route('rentals.index', $queryFor(['status' => 'active'])) }}"><strong>{{ number_format($activeRentals) }}</strong> Active rentals</a>
            <a href="{{ route('rentals.index', $queryFor()) }}"><strong>{{ number_format($totalRentals) }}</strong> Rentals</a>
        @endif
        @if($canReadSales)
            <a href="{{ route('sales.index', $queryFor()) }}"><strong>{{ number_format($totalSales) }}</strong> Sales</a>
        @endif
        @if($canReadInvoices)
            <a href="{{ route('invoices.index', $queryFor()) }}"><strong>{{ number_format($totalInvoices) }}</strong> Invoices</a>
        @endif
    </nav>

    <section class="customer-directory" aria-labelledby="customer-directory-count" data-customer-directory>
        <div class="customer-list-toolbar">
            <strong id="customer-directory-count">
                @if($customers->count())
                    {{ number_format($customers->firstItem()) }}–{{ number_format($customers->lastItem()) }} of {{ number_format($totalCustomers) }} customers
                @else
                    {{ number_format($totalCustomers) }} customers
                @endif
            </strong>
            <div class="customer-shortcuts">
                @if($canManageBulkRecords && $customers->count())
                    <label class="customer-bulk-target"><input type="checkbox" id="customerSelectAll">Select page</label>
                @endif
                @if($statusEnabled)
                    <nav class="customer-status-links" aria-label="Customer status">
                        <a href="{{ route('customers.index') }}" @if(!$hasActiveFilters) aria-current="page" @endif>All</a>
                        <a href="{{ route('customers.index', $queryFor(['status' => 'active'])) }}" @if($status === 'active') aria-current="page" @endif>Active</a>
                        <a href="{{ route('customers.index', $queryFor(['status' => 'inactive'])) }}" @if($status === 'inactive') aria-current="page" @endif>Inactive</a>
                    </nav>
                @endif
                <details class="customer-quick-links">
                    <summary>Quick links</summary>
                    <nav aria-label="Customer shortcuts">
                        @if($canReadRentals)<a href="{{ route('rentals.index', ['status' => 'active']) }}">Active Rentals</a>@endif
                        @if($canReadInvoices)<a href="{{ route('invoices.index', ['status' => 'unpaid']) }}">Unpaid Invoices</a>@endif
                        <a href="{{ route('customers.index', $queryFor(['search' => 'Business'])) }}">Search Business</a>
                        <a href="{{ route('customers.index', $queryFor(['search' => 'Individual'])) }}">Search Individual</a>
                        <a href="{{ route('customers.index', $queryFor(['sort_by' => 'latest'])) }}">Recent customers</a>
                    </nav>
                </details>
            </div>
            @if($hasActiveFilters)
                <div class="customer-filter-chips" aria-label="Active filters">
                    @foreach($activeFilters as $key => $filter)
                        @php
                            $withoutFilter = match ($key) {
                                'created_date' => ['created_date' => null, 'from_date' => null, 'to_date' => null],
                                'from_date', 'to_date' => ['created_date' => null, $key => null],
                                default => [$key => null],
                            };
                        @endphp
                        <a href="{{ route('customers.index', $queryFor($withoutFilter)) }}" aria-label="Remove {{ $filter }}">{{ $filter }} <span aria-hidden="true">×</span></a>
                    @endforeach
                    <a href="{{ route('customers.index') }}" data-filter-clear="customers-index">Clear all</a>
                </div>
            @endif
        </div>
        @if($canManageBulkRecords)
            <form id="customerBulkExportForm" method="POST" action="{{ route('customers.bulk.export.csv') }}" hidden>@csrf</form>
            @if($canDeleteCustomers)
                <form id="customerBulkDeleteForm" method="POST" action="{{ route('customers.bulk.delete') }}" hidden>@csrf @method('DELETE')</form>
            @endif
            <div class="customer-bulk-toolbar" data-customer-bulk-toolbar hidden>
                <strong role="status"><span id="customerSelectedCount">0</span> selected</strong>
                <button type="button" class="ops-btn-light" id="bulkCustomerExport" disabled>Export Selected CSV</button>
                @if($canDeleteCustomers)
                    <button type="button" class="customer-delete-selected" id="bulkCustomerDelete" disabled>Delete Selected</button>
                @endif
            </div>
        @endif

        <table class="customers-table" role="table" aria-label="Customer list">
            <thead role="rowgroup">
                <tr role="row">
                    @if($canManageBulkRecords)
                        <th class="bulk-col" role="columnheader" scope="col" aria-label="Select"></th>
                    @endif
                    <th class="serial-col" role="columnheader" scope="col" aria-label="Row number">#</th>
                    <th class="customer-identity-col" role="columnheader" scope="col">Customer</th>
                    <th class="customer-contact-col" role="columnheader" scope="col">Contact</th>
                    <th class="customer-location-col" role="columnheader" scope="col">Location</th>
                    @if($canReadActivity)<th class="customer-activity-col" role="columnheader" scope="col">Activity</th>@endif
                    <th class="customer-actions-col" role="columnheader" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                @forelse($customers as $customer)
                    @php
                        $rowNumber = $customers->firstItem() + $loop->index;
                        $customerUrl = route('customers.show', $customer);
                        $waNumber = \App\Support\WhatsAppHelper::resolveCustomerNumber($customer);
                        $customerWhatsapp = \App\Support\WhatsAppHelper::chatUrl($waNumber, $waNumber ? "Hello {$customer->name}, this is a quick update from Prime Healers." : null);
                        $preferredWhatsapp = $customer->preferredWhatsAppNumber();
                        $customerMeta = $customer->company_name ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                    @endphp
                    <tr class="customer-mobile-card" role="row" data-customer-href="{{ $customerUrl }}">
                        @if($canManageBulkRecords)
                            <td class="bulk-col" role="cell"><label class="customer-bulk-target"><input type="checkbox" class="customer-bulk-check" value="{{ $customer->id }}" aria-label="Select {{ $customer->name }}"></label></td>
                        @endif
                        <td class="serial-col" role="cell">{{ $rowNumber }}</td>
                        <td class="customer-identity-col" role="cell">
                            <div class="customer-cell-identity">
                                <span class="customer-list-avatar" aria-hidden="true">{{ $customerInitials((string) $customer->name) }}</span>
                                <div>
                                    <a href="{{ $customerUrl }}" class="customer-list-name">{{ $customer->name }}</a>
                                    @if($customerMeta && $customerMeta !== $customer->name)
                                        <span class="customer-list-meta">{{ $customerMeta }}</span>
                                    @endif
                                    @if($statusEnabled && filled($customer->status))
                                        <span class="customer-status {{ $customer->status === 'active' ? '' : 'is-inactive' }}">{{ $customer->status }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="customer-contact-col" role="cell">
                            <div class="customer-contact">
                                <span><small>Phone</small> {{ $customer->phone ?: '—' }}</span>
                                @if($whatsAppEnabled || $customer->phone)
                                    <span><small>WhatsApp</small>
                                        @if($customerWhatsapp)
                                            <a href="{{ $customerWhatsapp }}" target="_blank" rel="noopener">{{ $preferredWhatsapp ?: 'Chat' }}</a>
                                        @else
                                            {{ $preferredWhatsapp ?: '—' }} <small>Not available</small>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="customer-location-col" role="cell">
                            <span class="customer-location-text">{{ $customer->city ?: '—' }}</span>
                            <span class="customer-list-meta">{{ $customer->state ?: '—' }}</span>
                        </td>
                        @if($canReadActivity)
                            <td class="customer-activity-col" role="cell">
                                <div class="customer-activity-links">
                                    @if($canReadRentals)
                                        <a href="{{ route('rentals.index', ['customer_id' => $customer->id]) }}">Rentals <strong>{{ $customer->rentals_count ?? 0 }}</strong></a>
                                        <a href="{{ route('rentals.index', ['customer_id' => $customer->id, 'status' => 'active']) }}" aria-label="{{ $customer->active_rentals_count ?? 0 }} active rentals">Active <strong>{{ $customer->active_rentals_count ?? 0 }}</strong></a>
                                    @endif
                                    @if($canReadSales)
                                        <a href="{{ route('sales.index', ['customer_id' => $customer->id]) }}">Sales <strong>{{ $customer->sales_count ?? 0 }}</strong></a>
                                    @endif
                                    @if($canReadInvoices)
                                        <a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}">Invoices <strong>{{ $customer->invoices_count ?? 0 }}</strong></a>
                                    @endif
                                </div>
                            </td>
                        @endif
                        <td class="customer-actions-col" role="cell">
                            <div class="customer-row-actions">
                                @if($customer->phone)
                                    <a href="tel:{{ preg_replace('/\D+/', '', $customer->phone) }}" class="customer-icon-action is-primary" data-ph-action="phone" title="Call {{ $customer->name }}" aria-label="Call {{ $customer->name }}"><x-phos-action-icon name="phone" label="" /></a>
                                @endif
                                @if($customerWhatsapp)
                                    <a href="{{ $customerWhatsapp }}" target="_blank" rel="noopener" class="customer-icon-action is-whatsapp" data-ph-action="whatsapp" title="WhatsApp {{ $customer->name }}" aria-label="WhatsApp {{ $customer->name }}">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                    </a>
                                @endif
                                <details class="customer-action-menu">
                                    <summary aria-label="More actions for {{ $customer->name }}">⋯</summary>
                                    <div class="customer-action-panel">
                                        <a href="{{ $customerUrl }}" class="customer-action-link" data-ph-action="view"><x-phos-action-icon name="view" label="" />View Customer</a>
                                        @if($canUpdateCustomers)
                                            <a href="{{ route('customers.edit', $customer) }}" class="customer-action-link" data-ph-action="edit"><x-phos-action-icon name="edit" label="" />Edit Customer</a>
                                        @endif
                                        @if($canCreateRentals)<a href="{{ route('rentals.create', ['customer_id' => $customer->id]) }}" class="customer-action-link">Create Rental</a>@endif
                                        @if($canCreateSales)<a href="{{ route('sales.create', ['customer_id' => $customer->id]) }}" class="customer-action-link">Create Sale</a>@endif
                                        @if($canReadRentals)<a href="{{ route('rentals.index', ['customer_id' => $customer->id]) }}" class="customer-action-link">Rental History</a>@endif
                                        @if($canReadSales)<a href="{{ route('sales.index', ['customer_id' => $customer->id]) }}" class="customer-action-link">Sales History</a>@endif
                                        @if($canReadInvoices)<a href="{{ route('invoices.index', ['customer_id' => $customer->id]) }}" class="customer-action-link">Invoice History</a>@endif
                                        @if($hasCommunicationCenter)
                                            <a href="{{ route('communication-center.index', ['search' => $customer->phone ?: $customer->name]) }}" class="customer-action-link">Communication History</a>
                                        @endif
                                        @if($canDeleteCustomers)
                                            <form method="POST" action="{{ route('customers.destroy', $customer) }}" onsubmit="return confirm('Delete this customer? This will be blocked if dependencies exist.');">
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
                    <tr role="row"><td colspan="{{ $columnCount }}" role="cell"><p class="customer-list-empty">No customers match these filters.</p></td></tr>
                @endforelse
            </tbody>
        </table>
        @if($customers->hasPages())
            <div class="customer-page-links">{{ $customers->links() }}</div>
        @endif
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const directory = document.querySelector('[data-customer-directory]');
        const menus = [...directory.querySelectorAll('.customer-action-menu')];
        const shortcuts = directory.querySelector('.customer-quick-links');
        menus.forEach(menu => {
            menu.addEventListener('toggle', () => {
                menu.classList.remove('opens-up');
                if (!menu.open) return;

                const panel = menu.querySelector('.customer-action-panel');
                const trigger = menu.querySelector('summary').getBoundingClientRect();
                const viewportHeight = window.visualViewport?.height ?? window.innerHeight;
                const below = viewportHeight - trigger.bottom;
                const above = trigger.top;
                if (below < panel.offsetHeight + 8 && above > below) menu.classList.add('opens-up');
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const openMenu = menus.find(menu => menu.open);
            if (openMenu) openMenu.querySelector('summary').focus();
            if (shortcuts.open) {
                shortcuts.open = false;
                if (shortcuts.contains(document.activeElement)) shortcuts.querySelector('summary').focus();
            }
        }, true);
        document.addEventListener('click', event => {
            if (shortcuts.open && !shortcuts.contains(event.target)) shortcuts.open = false;
        });
        const mobileLayout = window.matchMedia('(max-width: 767px)');
        directory.addEventListener('click', event => {
            const row = event.target.closest('[data-customer-href]');
            if (mobileLayout.matches && row && !event.target.closest('a, button, input, label, details')) {
                window.location.href = row.dataset.customerHref;
            }
        });

        const toolbar = directory.querySelector('[data-customer-bulk-toolbar]');
        const selectAll = directory.querySelector('#customerSelectAll');
        if (!toolbar || !selectAll) return;
        const checks = [...directory.querySelectorAll('.customer-bulk-check')];
        const countLabel = toolbar.querySelector('#customerSelectedCount');
        const exportButton = toolbar.querySelector('#bulkCustomerExport');
        const deleteButton = toolbar.querySelector('#bulkCustomerDelete');
        const exportForm = directory.querySelector('#customerBulkExportForm');
        const deleteForm = directory.querySelector('#customerBulkDeleteForm');
        const selectedIds = () => checks.filter(check => check.checked).map(check => check.value);

        function refreshBulkState() {
            const selected = selectedIds().length;
            toolbar.hidden = selected === 0;
            countLabel.textContent = selected;
            exportButton.disabled = selected === 0;
            if (deleteButton) deleteButton.disabled = selected === 0;
            selectAll.checked = selected > 0 && selected === checks.length;
            selectAll.indeterminate = selected > 0 && selected < checks.length;
        }

        function submitSelection(form, ids) {
            form.querySelectorAll('input[name="customer_ids[]"]').forEach(input => input.remove());
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'customer_ids[]';
                input.value = id;
                form.append(input);
            });
            form.submit();
        }

        selectAll.addEventListener('change', () => {
            checks.forEach(check => { check.checked = selectAll.checked; });
            refreshBulkState();
        });
        checks.forEach(check => check.addEventListener('change', refreshBulkState));
        exportButton.addEventListener('click', () => {
            const ids = selectedIds();
            if (ids.length) submitSelection(exportForm, ids);
        });
        deleteButton?.addEventListener('click', () => {
            const ids = selectedIds();
            if (ids.length && confirm('Delete ' + ids.length + ' selected customer(s)? Customers with linked records will be skipped.')) {
                submitSelection(deleteForm, ids);
            }
        });
        refreshBulkState();
    });
</script>
@endsection
