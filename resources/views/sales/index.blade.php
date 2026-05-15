@extends('layouts.app')

@section('content')
@php
    use App\Support\WhatsAppHelper;

    $currentUser = auth()->user();
    $canCreateSales = $currentUser?->canAccessModule('sales', 'create') ?? false;
    $canUpdateSales = $currentUser?->canAccessModule('sales', 'update') ?? false;
    $canDeleteSales = $currentUser?->canAccessModule('sales', 'delete') ?? false;
    $canReadRentals = $currentUser?->canAccessModule('rentals', 'read') ?? false;
    $canCreateDeliveries = $currentUser?->canAccessModule('deliveries', 'create') ?? false;
    $canReadInvoices = $currentUser?->canAccessModule('invoices', 'read') ?? false;
    $canCreatePayments = $currentUser?->canAccessModule('payments', 'create') ?? false;
    $hasSaleDeliverySupport = \Illuminate\Support\Facades\Schema::hasColumn('deliveries', 'sale_id');
    $canViewFinance = $currentUser?->canViewFinance() ?? false;
    $canViewFinanceSummary = $currentUser?->isSuperAdmin() ?? false;
    $currency = fn ($value) => "\u{20B9}" . number_format((float) $value, 2);
    $salesUrl = function (array $overrides = []) use ($search, $customerId, $paymentStatus, $fromDate, $toDate, $sortBy) {
        return route('sales.index', array_filter(array_merge([
            'search' => $search ?: null,
            'customer_id' => $customerId ?: null,
            'payment_status' => $paymentStatus ?: null,
            'from_date' => $fromDate ?: null,
            'to_date' => $toDate ?: null,
            'sort_by' => $sortBy ?: null,
        ], $overrides), fn ($value) => $value !== null && $value !== ''));
    };
    $statusTone = fn ($status) => match ($status) {
        'paid' => 'background:#dcfce7;color:#166534;',
        'partial' => 'background:#fef3c7;color:#b45309;',
        'void' => 'background:#f1f5f9;color:#64748b;',
        default => 'background:#fee2e2;color:#b91c1c;',
    };
    $salePaymentBadge = function (?string $status) use ($statusTone) {
        $normalizedStatus = strtolower((string) $status);

        return match ($normalizedStatus) {
            'paid' => ['label' => 'Paid', 'tone' => $statusTone('paid'), 'class' => 'rn-badge-success'],
            'partial' => ['label' => 'Partial', 'tone' => $statusTone('partial'), 'class' => 'rn-badge-warning'],
            'void', 'cancelled' => ['label' => ucfirst($normalizedStatus), 'tone' => 'background:#f1f5f9;color:#64748b;', 'class' => 'rn-badge-muted'],
            default => ['label' => 'Pending', 'tone' => $statusTone('pending'), 'class' => 'rn-badge-danger'],
        };
    };
    $deliveryAssignmentLabel = function ($task) {
        if (! $task) {
            return 'Not assigned';
        }

        $isThirdParty = ($task->assignment_type ?? null) === 'third_party'
            || filled($task->third_party_name ?? null)
            || filled($task->third_party_contact ?? null)
            || filled($task->third_party_phone ?? null);

        if (($task->status ?? null) === 'completed') {
            return $isThirdParty ? 'Third-party completed' : 'Completed';
        }

        if ($isThirdParty) {
            return 'Third-party assigned';
        }

        $assigneeName = $task->assignedUser?->name
            ?? $task->assignedStaff?->name
            ?? $task->assigned_to
            ?? null;

        if (filled($assigneeName)) {
            return 'Assigned to ' . $assigneeName;
        }

        return match ($task->status ?? null) {
            'in_progress' => 'In progress',
            'pending', 'assigned' => 'Assigned',
            'cancelled' => 'Cancelled',
            default => 'Not assigned',
        };
    };
    $gstTone = fn ($mode) => ($mode ?? 'exclusive') === 'inclusive'
        ? 'background:#ede9fe;color:#6d28d9;'
        : 'background:#eff6ff;color:#1d4ed8;';
    $invoiceTone = fn ($linked) => $linked
        ? 'background:#dcfce7;color:#166534;'
        : 'background:#f8fafc;color:#64748b;';
    $mobileSortOptions = [
        ['value' => 'latest', 'label' => 'Newest First'],
        ['value' => 'oldest', 'label' => 'Oldest First'],
        ['value' => 'amount_desc', 'label' => 'Amount High-Low'],
        ['value' => 'amount_asc', 'label' => 'Amount Low-High'],
        ['value' => 'priority', 'label' => 'Priority'],
    ];
    $currentMobileSortLabel = collect($mobileSortOptions)->firstWhere('value', $sortBy)['label'] ?? 'Newest First';
@endphp

<style>
    .sales-page { display:grid; gap:12px; width:100%; max-width:100%; min-width:0; margin:0 auto; padding:8px 0 18px; box-sizing:border-box; overflow:visible; }
    .sales-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; background:#fff; border:1px solid var(--ph-color-border); border-radius:18px; padding:16px 18px; box-shadow:var(--ph-shadow-soft); min-width:0; }
    .sales-header h1 { margin:0; font-size:28px; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .sales-header p { margin:5px 0 0; color:var(--ph-color-text-soft); font-size:13px; max-width:760px; }
    .sales-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; min-width:0; }
    .ops-btn, .ops-btn-light, .ops-btn-wa {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:32px; padding:7px 11px; border-radius:10px; border:1px solid transparent;
        text-decoration:none; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap;
    }
    .ops-btn { background:var(--ph-color-primary); color:#fff; border-color:var(--ph-color-primary); }
    .ops-btn-light { background:#fff; color:var(--ph-color-text); border-color:var(--ph-color-border-strong); }
    .ops-btn-wa { background:var(--ph-color-success-soft); color:var(--ph-color-success); border-color:rgba(14,159,75,.18); padding:7px 10px; }
    .ops-btn-wa svg { width:14px; height:14px; flex:0 0 14px; }
    .ops-card {
        background:#fff; border:1px solid var(--ph-color-border); border-radius:14px;
        box-shadow:var(--ph-shadow-soft);
        min-width:0; max-width:100%; overflow:visible;
    }
    .ops-card-head {
        display:flex; justify-content:space-between; align-items:center; gap:10px;
        padding:10px 12px; border-bottom:1px solid var(--ph-color-border);
    }
    .ops-card-head h2 { margin:0; font-size:15px; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .ops-card-head span { color:var(--ph-color-text-soft); font-size:12px; }
    .ops-card-body { padding:10px 12px; min-width:0; overflow:visible; }
    .summary-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:8px; }
    .summary-card {
        display:grid; gap:5px; padding:10px 12px; border-radius:14px; border:1px solid #dbe3ef;
        background:#ffffff;
        text-decoration:none; color:inherit; transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .summary-card:hover { transform:translateY(-1px); box-shadow:var(--ph-shadow-card); border-color:var(--ph-color-border-strong); }
    .summary-card span { color:var(--ph-color-text-soft); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-family: var(--ph-font-heading); }
    .summary-card strong { font-size:19px; color:var(--ph-color-text); line-height:1; font-family: var(--ph-font-heading); }
    .summary-card small { color:var(--ph-color-text-soft); font-size:12px; }
    .summary-card.success { border-color:rgba(14,159,75,.18); background:var(--ph-color-success-soft); }
    .summary-card.warning { border-color:rgba(183,121,31,.18); background:var(--ph-color-warning-soft); }
    .summary-card.neutral { border-color:rgba(23,119,189,.18); background:var(--ph-color-info-soft); }
    .filter-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; }
    .filter-field { display:grid; gap:5px; }
    .filter-field label { font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .desktop-filter-toggle summary {
        list-style:none; cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:10px;
        padding:10px 12px; border-bottom:1px solid #e2e8f0;
    }
    .desktop-filter-toggle summary::-webkit-details-marker { display:none; }
    .desktop-filter-toggle summary h2 { margin:0; font-size:15px; color:#0f172a; }
    .desktop-filter-toggle summary span { color:#64748b; font-size:12px; }
    .desktop-filter-toggle:not([open]) summary { border-bottom:0; }
    .ops-input, .ops-select {
        width:100%; min-height:36px; border:1px solid #cbd5e1; border-radius:10px;
        padding:7px 10px; font-size:13px; color:#0f172a; background:#fff;
    }
    .filter-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .bulk-toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:8px 10px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; margin-bottom:10px; }
    .bulk-toolbar strong { color:#0f172a; font-size:13px; }
    .bulk-toolbar span { color:#64748b; font-size:12px; font-weight:700; }
    .bulk-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .bulk-col { width:38px; text-align:center; }
    .bulk-check { width:16px; height:16px; accent-color:#2563eb; }
    .mobile-chip-row { display:none; }
    .mobile-list-command { display:none; }
    .table-wrap {
        overflow-x:auto;
        overflow-y:visible;
        -webkit-overflow-scrolling:touch;
        overscroll-behavior-x:contain;
        width:100%;
        max-width:100%;
        min-width:0;
        border-radius:12px;
        padding-bottom:8px;
        margin-bottom:0;
    }
    .table-wrap::-webkit-scrollbar { height:8px; }
    .table-wrap::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
    .ops-table { width:max-content; min-width:100%; border-collapse:separate; border-spacing:0; table-layout:auto; }
    .ops-table th, .ops-table td { padding:8px 8px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; position:relative; }
    .ops-table tr:has(.ops-action-menu[open]),
    .ops-table tr.is-action-open { position:relative; z-index:60; }
    .ops-table th {
        background:#f8fafc; color:#64748b; font-size:11px;
        text-transform:uppercase; letter-spacing:.05em; font-weight:800;
    }
    .ops-table td { font-size:13px; color:#0f172a; background:#fff; }
    .cell-stack { display:grid; gap:4px; }
    .cell-subtle { color:#64748b; font-size:11px; line-height:1.35; }
    .badge {
        display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px;
        font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
    }
    .badge-row { display:flex; gap:5px; flex-wrap:wrap; }
    .cell-subtle.truncate-2 {
        display:-webkit-box;
        -webkit-line-clamp:2;
        -webkit-box-orient:vertical;
        overflow:hidden;
    }
    .row-actions { display:grid; gap:6px; }
    .row-actions .ops-btn, .row-actions .ops-btn-light, .row-actions .ops-btn-wa { min-height:30px; padding:6px 9px; font-size:11px; width:100%; justify-content:flex-start; }
    .order-col { width:118px; min-width:118px; }
    .date-col { width:110px; min-width:110px; }
    .customer-col { width:190px; min-width:190px; }
    .product-col { width:240px; min-width:240px; }
    .qty-col { width:70px; min-width:70px; text-align:center; }
    .amount-col { width:148px; min-width:148px; }
    .invoice-col { width:156px; min-width:156px; }
    .notes-col { width:220px; min-width:220px; }
    .notes-col .cell-subtle { white-space:normal; overflow-wrap:anywhere; }
    .actions-cell { position:static; z-index:1; width:144px; min-width:144px; text-align:center; background:#fff !important; box-shadow:none; overflow:visible; }
    .ops-table tr:has(.ops-action-menu[open]) .actions-cell,
    .ops-table tr.is-action-open .actions-cell { z-index:1000; }
    .ops-table th.actions-cell { z-index:4; background:#f8fafc !important; }
    .sale-inline-actions { display:grid; gap:7px; }
    .sale-inline-actions .ops-btn,
    .sale-inline-actions .ops-btn-light,
    .sale-inline-actions .ops-btn-wa,
    .sale-inline-actions form button {
        width:100%;
        min-height:30px;
        justify-content:center;
        padding:6px 9px;
        font-size:11px;
    }
    .sale-inline-actions form { margin:0; }
    .ops-action-menu { position:relative; display:inline-block; z-index:20; }
    .ops-action-menu summary { list-style:none; display:inline-flex; align-items:center; justify-content:center; width:40px; height:36px; border:1px solid var(--ph-color-border-strong); border-radius:999px; background:#fff; color:var(--ph-color-text); font-weight:900; cursor:pointer; user-select:none; }
    .ops-action-menu summary::-webkit-details-marker { display:none; }
    .ops-action-menu[open] summary { background:var(--ph-color-info-soft); border-color:rgba(23,119,189,.26); color:var(--ph-color-primary); }
    .ops-action-panel { position:absolute; right:0; top:calc(100% + 6px); z-index:999; display:grid; gap:6px; min-width:190px; padding:8px; border:1px solid var(--ph-color-border); border-radius:12px; background:#fff; box-shadow:0 18px 40px rgba(11,35,66,.16); text-align:left; }
    .empty-state { color:var(--ph-color-text-soft); font-size:13px; padding:18px 0; }
    @media (max-width: 1180px) {
        .ops-table { min-width:1080px; }
    }
    @media (max-width: 767px) {
        .sales-page { padding:4px 0 16px; gap:8px; }
        .sales-header { display:none; }
        .desktop-priority-panel { display:none !important; }
        .summary-grid, .filter-grid { grid-template-columns:1fr; }
        .filter-actions, .sales-actions { flex-direction:column; align-items:stretch; }
        .mobile-list-command {
            display:grid; gap:8px; padding:8px; border:1px solid var(--ph-color-border); border-radius:16px;
            background:#fff; box-shadow:var(--ph-shadow-soft);
        }
        .mobile-search-row { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; }
        .mobile-search-row .ops-input { min-height:40px; border-radius:12px; font-size:16px; }
        .mobile-search-row .ops-btn { min-height:40px; border-radius:12px; padding:7px 12px; }
        .mobile-stat-strip {
            display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:6px;
        }
        .mobile-stat-strip a {
            display:grid; gap:2px; min-width:0; padding:7px 8px; border:1px solid var(--ph-color-border); border-radius:12px;
            background:var(--ph-color-surface-soft); color:var(--ph-color-text); text-decoration:none;
        }
        .mobile-stat-strip span { font-size:9px; color:var(--ph-color-text-soft); font-weight:800; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mobile-stat-strip strong { font-size:clamp(12px, 4vw, 15px); line-height:1.15; min-width:0; overflow-wrap:anywhere; word-break:break-word; }
        .mobile-filter-toggle {
            border:1px solid var(--ph-color-border); border-radius:12px; background:var(--ph-color-surface-soft); overflow:hidden;
        }
        .mobile-filter-toggle summary {
            list-style:none; cursor:pointer; min-height:36px; display:flex; align-items:center; justify-content:space-between;
            padding:8px 10px; font-size:12px; font-weight:800; color:var(--ph-color-text);
        }
        .mobile-filter-toggle summary::-webkit-details-marker { display:none; }
        .mobile-filter-body { padding:0 10px 10px; display:grid; gap:8px; }
        .sales-page > .mobile-chip-row { display:none; }
        .mobile-chip-row {
            display:flex; gap:8px; overflow-x:auto; padding:2px 1px 4px;
            scrollbar-width:none;
        }
        .mobile-chip-row::-webkit-scrollbar { display:none; }
        .mobile-chip {
            flex:0 0 auto; display:inline-flex; align-items:center; justify-content:center;
            min-height:34px; padding:7px 11px; border-radius:999px; border:1px solid var(--ph-color-border-strong);
            background:#fff; color:var(--ph-color-text); text-decoration:none; font-size:12px; font-weight:800;
        }
        .mobile-chip.is-active { background:var(--ph-color-sidebar); color:#fff; border-color:var(--ph-color-sidebar); }
        .table-wrap { overflow:visible; padding-bottom:0; }
        .ops-table { min-width:0; border-collapse:separate; border-spacing:0 10px; }
        .ops-table thead { display:none; }
        .ops-table, .ops-table tbody, .ops-table tr, .ops-table td { display:block; width:100%; }
        .ops-table tr {
            border:1px solid var(--ph-color-border); border-radius:14px; background:#fff;
            box-shadow:var(--ph-shadow-soft); overflow:visible;
        }
        .ops-table td {
            display:grid; grid-template-columns:92px minmax(0, 1fr); gap:10px;
            padding:9px 12px; border-bottom:1px solid var(--ph-color-border); background:#fff;
        }
        .ops-table td:last-child { border-bottom:none; }
        .ops-table td::before {
            color:var(--ph-color-text-soft); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
        }
        .ops-table td::before { content:attr(data-label); }
        .actions-cell { position:static; width:auto; min-width:0; text-align:left; box-shadow:none; }
        .sale-inline-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:7px;
            margin-bottom:7px;
        }
        .sale-inline-actions > * { min-width:0; }
        .sale-inline-actions > *:last-child { grid-column:2; }
        .sale-inline-actions .ops-btn,
        .sale-inline-actions .ops-btn-light,
        .sale-inline-actions .ops-btn-wa,
        .sale-inline-actions form button {
            width:100%;
            min-height:40px;
            justify-content:center;
            padding:7px 10px;
            font-size:11px;
        }
        .sale-inline-actions form { margin:0; }
        .actions-cell {
            display:block;
        }
        .sale-inline-actions {
            display:grid;
            margin-bottom:0;
        }
        .ops-action-menu { display:block; width:100%; min-width:0; }
        .ops-action-menu summary { width:100%; justify-content:center; border-radius:12px; height:40px; }
        .ops-action-panel { position:static; min-width:0; margin-top:7px; box-shadow:none; }
        .row-actions { gap:7px; }
        .row-actions .ops-btn,
        .row-actions .ops-btn-light,
        .row-actions .ops-btn-wa { flex:1 1 calc(50% - 7px); min-width:0; }
        .notes-col .cell-subtle.truncate-2 {
            display:block;
            -webkit-line-clamp:unset;
            overflow:visible;
        }
    }
</style>

<div class="container sales-page rn-list-page">
    <div class="sales-header">
        <div>
            <div class="rx-eyebrow">Sales Desk</div>
            <h1>Sales</h1>
            <p>Track closures, invoices, and customer follow-up from one tighter list.</p>
        </div>

        <div class="sales-actions">
            @if($canCreateSales)
                <a href="{{ route('sales.create') }}" class="ops-btn">+ New Sale</a>
            @endif
        </div>
    </div>

    <div class="mobile-chip-row" aria-label="Sales quick filters">
        <a href="{{ $salesUrl(['payment_status' => 'pending']) }}" class="mobile-chip {{ $paymentStatus === 'pending' ? 'is-active' : '' }}">Unpaid</a>
        <a href="{{ $salesUrl(['payment_status' => 'partial']) }}" class="mobile-chip {{ $paymentStatus === 'partial' ? 'is-active' : '' }}">Partial</a>
        <a href="{{ $salesUrl(['payment_status' => 'paid']) }}" class="mobile-chip {{ $paymentStatus === 'paid' ? 'is-active' : '' }}">Paid</a>
        <a href="{{ $salesUrl(['from_date' => now()->toDateString(), 'to_date' => now()->toDateString()]) }}" class="mobile-chip {{ $fromDate === now()->toDateString() && $toDate === now()->toDateString() ? 'is-active' : '' }}">Today</a>
        <a href="{{ $salesUrl(['from_date' => now()->startOfMonth()->toDateString(), 'to_date' => now()->toDateString()]) }}" class="mobile-chip">This Month</a>
    </div>

    @if(session('success'))
        <div style="background:var(--ph-color-success-soft);color:var(--ph-color-success);border:1px solid rgba(14,159,75,.18);padding:11px 13px;border-radius:12px;">
            {{ session('success') }}
        </div>
    @endif

    <div id="sales-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
        <div class="mobile-filter-sheet-panel">
            <div class="mobile-filter-sheet-header">
                <div>
                    <h3>Sales Filters</h3>
                    <p>Keep search, payment state, and dates close on mobile.</p>
                </div>
                <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="sales-mobile-filters" aria-label="Close filters">×</button>
            </div>
            <div class="mobile-filter-sheet-body">
                <form method="GET" action="{{ route('sales.index') }}" class="mobile-sheet-form">
                    <input type="hidden" name="search" value="{{ $search }}">
                    <input type="hidden" name="sort_by" value="{{ $sortBy }}">
                    <div class="mobile-sheet-grid">
                        <div class="mobile-sheet-field">
                            <label for="mobile_payment_status">Payment</label>
                            <select id="mobile_payment_status" class="ops-select" name="payment_status">
                                <option value="">All Statuses</option>
                                <option value="pending" @selected($paymentStatus === 'pending')>Pending</option>
                                <option value="partial" @selected($paymentStatus === 'partial')>Partial</option>
                                <option value="paid" @selected($paymentStatus === 'paid')>Paid</option>
                                <option value="void" @selected($paymentStatus === 'void')>Void</option>
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_from_date">From</label>
                            <input id="mobile_from_date" class="ops-input" type="date" name="from_date" value="{{ $fromDate }}">
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_to_date">To</label>
                            <input id="mobile_to_date" class="ops-input" type="date" name="to_date" value="{{ $toDate }}">
                        </div>
                    </div>
                    <div class="mobile-sheet-actions">
                        <button type="submit" class="ops-btn">Apply</button>
                        <a href="{{ route('sales.index') }}" class="ops-btn-light">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="summary-grid desktop-priority-panel">
        <a href="{{ $salesUrl(['payment_status' => null]) }}" class="summary-card neutral rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
            </span>
            <span>Total Sales</span>
            <strong>{{ $totalSales }}</strong>
            <small>Current filtered sale records</small>
        </a>
        <a href="{{ $salesUrl(['payment_status' => 'paid']) }}" class="summary-card success rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
            </span>
            <span>Paid Orders</span>
            <strong>{{ $paidSales }}</strong>
            <small>Fully closed sale records</small>
        </a>
        <a href="{{ $salesUrl(['payment_status' => 'pending']) }}" class="summary-card warning rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><path d="m12 16 .01 0"/><circle cx="12" cy="12" r="9"/></svg>
            </span>
            <span>Pending Orders</span>
            <strong>{{ $pendingSales }}</strong>
            <small>Need follow-up or collection</small>
        </a>
        @if($canViewFinanceSummary)
        <a href="{{ $salesUrl(['payment_status' => null]) }}" class="summary-card rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14.5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
            <span>Sales Value</span>
            <strong style="font-size:18px;">{{ $currency($totalSalesAmount) }}</strong>
            <small>{{ $currency($paidSalesAmount) }} paid + {{ $currency($pendingSalesAmount) }} pending</small>
        </a>
        <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}" class="summary-card warning rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18v10H3z"/><path d="M7 15h5"/><path d="M17 11h.01"/></svg>
            </span>
            <span>Outstanding Sales Invoices</span>
            <strong style="font-size:18px;">{{ $currency($outstandingInvoiceAmount) }}</strong>
            <small>{{ $outstandingInvoiceCount }} open sales invoices</small>
        </a>
        <a href="{{ $salesUrl(['payment_status' => null]) }}" class="summary-card warning rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6h15l-2 8H8L6 6Z"/><path d="M6 6 5 3H2"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
            </span>
            <span>Unbilled Sales</span>
            <strong style="font-size:18px;">{{ $currency($unbilledSalesAmount) }}</strong>
            <small>{{ $unbilledSalesCount }} orders without invoice</small>
        </a>
        <a href="{{ $salesUrl(['payment_status' => null]) }}" class="summary-card danger rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19h16"/><path d="m5 15 4-4 4 3 6-8"/></svg>
            </span>
            <span>Pending Sales Amount</span>
            <strong style="font-size:18px;">{{ $currency($totalPendingSalesAmount) }}</strong>
            <small>Outstanding invoices + unbilled sales</small>
        </a>
        @endif
    </div>

    <div class="mobile-list-command" aria-label="Mobile sales controls">
        <form method="GET" action="{{ route('sales.index') }}" class="mobile-search-row">
            @foreach(request()->except(['search', 'page']) as $key => $value)
                @if(is_scalar($value) && $value !== '')
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <input class="ops-input" type="search" name="search" value="{{ $search }}" placeholder="Search customer, phone, product">
            <button type="submit" class="ops-btn">Search</button>
        </form>

        <div class="mobile-stat-strip" aria-label="Sales summary">
            <a href="{{ $salesUrl(['payment_status' => null]) }}"><span>Total</span><strong>{{ $totalSales }}</strong></a>
            <a href="{{ $salesUrl(['payment_status' => 'paid']) }}"><span>Paid</span><strong>{{ $paidSales }}</strong></a>
            <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}"><span>Invoice Due</span><strong>{{ $currency($outstandingInvoiceAmount) }}</strong></a>
            @if($canViewFinanceSummary)
                <a href="{{ $salesUrl(['payment_status' => null]) }}"><span>Pending</span><strong>{{ $currency($totalPendingSalesAmount) }}</strong></a>
            @else
                <a href="{{ $salesUrl(['from_date' => now()->toDateString(), 'to_date' => now()->toDateString()]) }}"><span>Today</span><strong>{{ $sales->count() }}</strong></a>
            @endif
        </div>

        <div class="mobile-chip-row" aria-label="Sales quick filters">
            <a href="{{ $salesUrl(['payment_status' => 'pending']) }}" class="mobile-chip {{ $paymentStatus === 'pending' ? 'is-active' : '' }}">Unpaid</a>
            <a href="{{ $salesUrl(['payment_status' => 'partial']) }}" class="mobile-chip {{ $paymentStatus === 'partial' ? 'is-active' : '' }}">Partial</a>
            <a href="{{ $salesUrl(['payment_status' => 'paid']) }}" class="mobile-chip {{ $paymentStatus === 'paid' ? 'is-active' : '' }}">Paid</a>
            <a href="{{ $salesUrl(['from_date' => now()->toDateString(), 'to_date' => now()->toDateString()]) }}" class="mobile-chip {{ $fromDate === now()->toDateString() && $toDate === now()->toDateString() ? 'is-active' : '' }}">Today</a>
        </div>

        <div class="mobile-action-toolbar" aria-label="Mobile sales filters and sorting">
            <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="sales-mobile-filters">
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
                        <a href="{{ $salesUrl(['sort_by' => $option['value']]) }}" class="mobile-sort-option {{ $sortBy === $option['value'] ? 'is-active' : '' }}">{{ $option['label'] }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <details class="ops-card desktop-priority-panel desktop-filter-toggle">
        <summary>
            <h2>Filters & Sorting</h2>
            <span>{{ $search || $customerId || $paymentStatus || $fromDate || $toDate ? 'Active' : 'Expand' }}</span>
        </summary>
        <div class="ops-card-body">
            <form method="GET" action="{{ route('sales.index') }}" style="display:grid; gap:12px;">
                <div class="filter-grid">
                    <div class="filter-field">
                        <label for="search">Search</label>
                        <input id="search" class="ops-input" type="text" name="search" value="{{ $search }}" placeholder="Customer, phone, WhatsApp, product, notes">
                    </div>

                    <div class="filter-field">
                        <label for="customer_id">Customer</label>
                        <select id="customer_id" class="ops-select" name="customer_id">
                            <option value="">All Customers</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected((string) $customerId === (string) $customer->id)>{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="payment_status">Payment</label>
                        <select id="payment_status" class="ops-select" name="payment_status">
                            <option value="">All Statuses</option>
                            <option value="pending" @selected($paymentStatus === 'pending')>Pending</option>
                            <option value="partial" @selected($paymentStatus === 'partial')>Partial</option>
                            <option value="paid" @selected($paymentStatus === 'paid')>Paid</option>
                            <option value="void" @selected($paymentStatus === 'void')>Void</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="from_date">From Date</label>
                        <input id="from_date" class="ops-input" type="date" name="from_date" value="{{ $fromDate }}">
                    </div>

                    <div class="filter-field">
                        <label for="to_date">To Date</label>
                        <input id="to_date" class="ops-input" type="date" name="to_date" value="{{ $toDate }}">
                    </div>

                    <div class="filter-field">
                        <label for="sort_by">Sort By</label>
                        <select id="sort_by" class="ops-select" name="sort_by">
                            <option value="priority" @selected($sortBy === 'priority')>Priority</option>
                            <option value="latest" @selected($sortBy === 'latest')>Latest First</option>
                            <option value="oldest" @selected($sortBy === 'oldest')>Oldest First</option>
                            <option value="customer_asc" @selected($sortBy === 'customer_asc')>Customer A-Z</option>
                            <option value="customer_desc" @selected($sortBy === 'customer_desc')>Customer Z-A</option>
                            <option value="amount_desc" @selected($sortBy === 'amount_desc')>Amount: High to Low</option>
                            <option value="amount_asc" @selected($sortBy === 'amount_asc')>Amount: Low to High</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="ops-btn">Apply Filters</button>
                    <a href="{{ route('sales.index') }}" class="ops-btn-light">Reset</a>
                </div>
            </form>
        </div>
    </details>

    <div class="ops-card rn-table-shell">
        <div class="ops-card-head">
            <h2>Sales List</h2>
            <span>{{ $sales->total() }} records</span>
        </div>
        <div class="ops-card-body">
            @if($sales->isEmpty())
                <div class="empty-state">No sales match this view right now. Adjust filters or create a new sale to reopen the pipeline.</div>
            @else
                @if($canReadInvoices)
                    <form id="saleInvoiceBulkForm" method="GET" action="{{ route('invoices.bulk.print') }}" target="_blank" class="bulk-toolbar">
                        @csrf
                        <div>
                            <strong>Invoice bulk actions</strong>
                            <span id="saleInvoiceSelectedCount">0 selected</span>
                        </div>
                        <div class="bulk-actions">
                            <button type="submit" class="ops-btn-light" data-sale-bulk-action="{{ route('invoices.bulk.print') }}" data-sale-bulk-method="GET" data-sale-bulk-target="_blank">Bulk PDF / Print</button>
                            <button type="submit" class="ops-btn-light" data-sale-bulk-action="{{ route('invoices.export.csv') }}" data-sale-bulk-method="GET" data-sale-bulk-target="_self">Export Selected CSV</button>
                            <button type="submit" class="ops-btn-light" data-sale-bulk-action="{{ route('invoices.bulk.action') }}" data-sale-bulk-method="POST" data-sale-bulk-task="mark_paid" data-sale-bulk-target="_self">Mark Paid</button>
                            <button type="submit" class="ops-btn-light" data-sale-bulk-action="{{ route('invoices.bulk.action') }}" data-sale-bulk-method="POST" data-sale-bulk-task="void" data-sale-bulk-target="_self" data-sale-confirm="Void selected invoices?">Void</button>
                        </div>
                    </form>
                @endif

                <div class="table-wrap">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                @if($canReadInvoices)
                                    <th class="bulk-col"><input type="checkbox" class="bulk-check" id="selectAllSaleInvoices" aria-label="Select all sale invoices"></th>
                                @endif
                                <th class="order-col">Sales Order</th>
                                <th class="date-col">Date</th>
                                <th class="customer-col">Customer</th>
                                <th class="product-col">Product</th>
                                <th class="qty-col">Qty</th>
                                @if($canViewFinance)<th class="amount-col">Amount</th>@endif
                                <th class="invoice-col">Invoice</th>
                                <th class="notes-col">Notes</th>
                                <th class="actions-cell">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sales as $sale)
                                @php
                                    $whatsAppNumber = WhatsAppHelper::resolveCustomerNumber($sale->customer);
                                    $whatsAppUrl = WhatsAppHelper::chatUrl($whatsAppNumber, WhatsAppHelper::saleFollowUp($sale));
                                    $paymentBadge = $salePaymentBadge($sale->payment_status);
                                    $deliverySummary = $deliveryAssignmentLabel($sale->deliveryRecord ?? null);
                                    $canManagePayments = $canCreatePayments && !in_array($sale->payment_status, ['paid', 'void', 'cancelled'], true);
                                @endphp
                                <tr>
                                    @php($isAutoGeneratedRentalSale = (bool) ($sale->auto_generated_from_rental ?? false))
                                    @if($canReadInvoices)
                                        <td class="bulk-col" data-label="Select">
                                            <input type="checkbox" class="bulk-check sale-invoice-check" value="{{ $sale->id }}" data-invoice-id="{{ $sale->linked_invoice_id }}" aria-label="Select sale #{{ $sale->id }}">
                                        </td>
                                    @endif
                                    <td class="order-col" data-label="Order">
                                        <div class="cell-stack">
                                            @if(\Illuminate\Support\Facades\Route::has('sales.show'))
                                                <a href="{{ route('sales.show', $sale) }}" class="rn-record-link">#{{ $sale->id }}</a>
                                            @else
                                                <strong>#{{ $sale->id }}</strong>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="date-col" data-label="Date">
                                        <span class="cell-subtle">{{ $sale->sale_date ? \Carbon\Carbon::parse($sale->sale_date)->format('d M Y') : '-' }}</span>
                                    </td>
                                    <td class="customer-col" data-label="Customer">
                                        <div class="cell-stack">
                                            @if(\Illuminate\Support\Facades\Route::has('customers.show') && $sale->customer)
                                                <a href="{{ route('customers.show', $sale->customer) }}" class="rn-record-link">{{ $sale->customer->name }}</a>
                                            @else
                                                <strong>{{ $sale->customer->name ?? 'N/A' }}</strong>
                                            @endif
                                            <span class="cell-subtle">{{ $sale->customer->phone ?? 'No phone' }}</span>
                                            @if($sale->rental)
                                                <span class="cell-subtle">Rental #{{ $sale->rental->id }} linked</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="product-col" data-label="Product">
                                        <div class="cell-stack">
                                            @if(\Illuminate\Support\Facades\Route::has('products.show') && $sale->product)
                                                <a href="{{ route('products.show', $sale->product) }}" class="rn-record-link">{{ $sale->product->name }}</a>
                                            @else
                                                <strong>{{ $sale->product->name ?? 'N/A' }}</strong>
                                            @endif
                                            <div class="badge-row">
                                                <span class="badge" style="{{ $gstTone($sale->tax_calculation_mode ?? 'exclusive') }}">
                                                    {{ ($sale->tax_calculation_mode ?? 'exclusive') === 'inclusive' ? 'GST Inc' : 'GST Exc' }}
                                                </span>
                                                @if($sale->asset)
                                                    <span class="badge" style="background:#ecfdf5;color:#166534;">Asset Linked</span>
                                                @endif
                                                @if($sale->rental)
                                                    <span class="badge" style="background:#fff7ed;color:#c2410c;">Rental Linked</span>
                                                @endif
                                            </div>
                                            <span class="cell-subtle">
                                                @if($sale->asset)
                                                    @if(\Illuminate\Support\Facades\Route::has('assets.show'))
                                                        <a href="{{ route('assets.show', $sale->asset) }}" class="rn-record-link-subtle">{{ $sale->asset->serial_number ?: $sale->asset->asset_name ?: ('Asset #' . $sale->asset->id) }}</a>
                                                    @else
                                                        {{ $sale->asset->serial_number ?: $sale->asset->asset_name ?: ('Asset #' . $sale->asset->id) }}
                                                    @endif
                                                @elseif($sale->rental)
                                                    Linked to {{ $sale->rental->product?->name ?? ('Rental #' . $sale->rental->id) }}
                                                @else
                                                    Product sale entry
                                                @endif
                                            </span>
                                            @if(!$isAutoGeneratedRentalSale && $hasSaleDeliverySupport)
                                                <span class="cell-subtle">Del: {{ $deliverySummary }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="qty-col" data-label="Qty">{{ $sale->quantity }}</td>
                                    @if($canViewFinance)
                                    <td class="amount-col" data-label="Amount / Payment">
                                        <div class="cell-stack">
                                            <strong>{{ $currency($sale->sale_amount) }}</strong>
                                            <span class="badge rn-badge {{ $paymentBadge['class'] }}" style="{{ $paymentBadge['tone'] }}">
                                                {{ $paymentBadge['label'] }}
                                            </span>
                                            <span class="cell-subtle">
                                                Rate {{ $currency($sale->unit_price ?? 0) }}
                                                @if((float) ($sale->discount_amount ?? 0) > 0)
                                                    • Disc {{ $currency($sale->discount_amount ?? 0) }}
                                                @endif
                                                @if((float) $sale->resolvedShippingCharges() > 0)
                                                    • Ship {{ $currency($sale->resolvedShippingCharges()) }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                    @endif
                                    <td class="invoice-col" data-label="Invoice">
                                        <div class="cell-stack">
                                            <div class="badge-row">
                                                <span class="badge rn-badge {{ $sale->linked_invoice_id ? 'rn-badge-success' : 'rn-badge-draft' }}" style="{{ $invoiceTone((bool) $sale->linked_invoice_id) }}">
                                                    {{ $sale->linked_invoice_id ? 'Invoice Ready' : 'No Invoice' }}
                                                </span>
                                            </div>
                                            <span class="cell-subtle">
                                                GST {{ number_format((float) ($sale->tax_percentage ?? 0), 2) }}%
                                                @if($sale->linked_invoice_id)
                                                    • #{{ $sale->linked_invoice_id }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>
                                    <td class="notes-col" data-label="Notes">
                                        <div class="cell-stack">
                                            <span class="cell-subtle truncate-2">{{ $sale->notes ?: '-' }}</span>
                                            @if($isAutoGeneratedRentalSale && $sale->rental)
                                                <span class="cell-subtle">Auto-generated from rental #{{ $sale->rental->id }}</span>
                                            @elseif($sale->rental)
                                                <span class="cell-subtle">Customer-linked rental sale</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="actions-cell" data-label="Actions">
                                        <div class="sale-inline-actions">
                                            <a href="{{ route('sales.show', $sale) }}" class="ops-btn-light">View</a>
                                            @if($sale->linked_invoice_id)
                                                <a href="{{ route('invoices.show', $sale->linked_invoice_id) }}" class="ops-btn-light">Invoice</a>
                                            @elseif($canUpdateSales)
                                                <form action="{{ route('sales.invoice', $sale) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="ops-btn-light">Invoice</button>
                                                </form>
                                            @endif
                                            @if($whatsAppUrl)
                                                <a href="{{ $whatsAppUrl }}" target="_blank" class="ops-btn-wa" title="WhatsApp follow-up" aria-label="WhatsApp follow-up">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                                    <span>WA</span>
                                                </a>
                                            @endif
                                            <details class="ops-action-menu">
                                                <summary aria-label="Sale actions">More</summary>
                                            <div class="ops-action-panel row-actions">
                                            @if(!$isAutoGeneratedRentalSale && $canCreateDeliveries && $hasSaleDeliverySupport)
                                                @if($sale->relationLoaded('deliveryRecord') && $sale->deliveryRecord)
                                                    <a href="{{ route('deliveries.show', $sale->deliveryRecord) }}" class="ops-btn-light">View Delivery</a>
                                                @else
                                                    <a href="{{ route('deliveries.create', ['sale_id' => $sale->id, 'type' => 'delivery']) }}" class="ops-btn-light">Assign Delivery</a>
                                                @endif
                                            @endif
                                            @if(!$isAutoGeneratedRentalSale && $canUpdateSales)
                                                <a href="{{ route('sales.edit', $sale) }}" class="ops-btn-light">Edit Sale</a>
                                            @endif
                                            @if($sale->rental && $canReadRentals && \Illuminate\Support\Facades\Route::has('rentals.show'))
                                                <a href="{{ route('rentals.show', $sale->rental) }}" class="ops-btn-light">View Linked Rental</a>
                                            @endif
                                            @if($canManagePayments)
                                                <form action="{{ route('sales.markPaid', $sale) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="ops-btn-light">Mark Paid</button>
                                                </form>
                                                <a href="{{ route('sales.show', $sale) }}#sale-billing-actions" class="ops-btn-light">Record Payment</a>
                                            @endif
                                            @if(!$isAutoGeneratedRentalSale && $canUpdateSales && $sale->payment_status !== 'void')
                                                <form action="{{ route('sales.void', $sale) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="ops-btn-light" onclick="return confirm('Void this sale?')">Void Sale</button>
                                                </form>
                                            @endif
                                            @if(!$isAutoGeneratedRentalSale && $canDeleteSales)
                                                <form action="{{ route('sales.destroy', $sale) }}" method="POST" style="margin:0;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="ops-btn-light" onclick="return confirm('Delete this sale record?')">Delete</button>
                                                </form>
                                            @endif
                                            </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:12px;">
                    {{ $sales->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@if($canCreateSales)
    @include('partials.mobile-fab', ['href' => route('sales.create'), 'label' => 'Add Sale'])
@endif
@push('scripts')
<script>
    (() => {
        const closeMenus = (except = null) => {
            document.querySelectorAll('.sales-page .ops-action-menu[open]').forEach((menu) => {
                if (menu !== except) {
                    menu.removeAttribute('open');
                    menu.closest('tr')?.classList.remove('is-action-open');
                }
            });
        };

        document.querySelectorAll('.sales-page .ops-action-menu').forEach((menu) => {
            menu.addEventListener('toggle', () => {
                const row = menu.closest('tr');

                if (menu.open) {
                    closeMenus(menu);
                    row?.classList.add('is-action-open');
                } else {
                    row?.classList.remove('is-action-open');
                }
            });
        });

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.sales-page .ops-action-menu')) {
                closeMenus();
            }
        });

        const bulkForm = document.getElementById('saleInvoiceBulkForm');
        const selectAll = document.getElementById('selectAllSaleInvoices');
        const rowChecks = Array.from(document.querySelectorAll('.sale-invoice-check'));
        const countEl = document.getElementById('saleInvoiceSelectedCount');

        const updateSelectedCount = () => {
            const selected = rowChecks.filter((checkbox) => checkbox.checked).length;
            const invoiceEligible = rowChecks.filter((checkbox) => checkbox.checked && checkbox.dataset.invoiceId).length;

            if (countEl) {
                countEl.textContent = `${selected} selected, ${invoiceEligible} with invoices`;
            }

            if (selectAll) {
                selectAll.checked = selected > 0 && selected === rowChecks.length;
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

        document.querySelectorAll('[data-sale-bulk-action]').forEach((button) => {
            button.addEventListener('click', (event) => {
                const selectedInvoiceIds = rowChecks
                    .filter((checkbox) => checkbox.checked && checkbox.dataset.invoiceId)
                    .map((checkbox) => checkbox.dataset.invoiceId);

                if (!bulkForm || selectedInvoiceIds.length === 0) {
                    event.preventDefault();
                    alert('Select at least one row with a linked invoice first. Rows without invoices cannot use invoice bulk actions yet.');
                    return;
                }

                bulkForm.querySelectorAll('input[name="invoice_ids[]"]').forEach((input) => input.remove());
                bulkForm.querySelectorAll('input[name="bulk_action"]').forEach((input) => input.remove());
                selectedInvoiceIds.forEach((invoiceId) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'invoice_ids[]';
                    input.value = invoiceId;
                    bulkForm.appendChild(input);
                });

                bulkForm.action = button.dataset.saleBulkAction;
                bulkForm.method = button.dataset.saleBulkMethod || 'GET';
                bulkForm.target = button.dataset.saleBulkTarget || '_self';

                if (button.dataset.saleBulkTask) {
                    const confirmMessage = button.dataset.saleConfirm || 'Apply this bulk action to selected invoices?';

                    if (!window.confirm(confirmMessage)) {
                        event.preventDefault();
                        return;
                    }

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'bulk_action';
                    actionInput.value = button.dataset.saleBulkTask;
                    bulkForm.appendChild(actionInput);
                }
            });
        });

        updateSelectedCount();
    })();
</script>
@endpush
@endsection
