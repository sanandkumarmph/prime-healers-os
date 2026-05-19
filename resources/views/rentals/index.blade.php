@extends('layouts.app')

@section('content')
@php
    use App\Support\WhatsAppHelper;

    $currentUser = auth()->user();
    $canCreateRentals = $currentUser?->canAccessModule('rentals', 'create') ?? false;
    $canUpdateRentals = $currentUser?->canAccessModule('rentals', 'update') ?? false;
    $canDeleteRentals = $currentUser?->canAccessModule('rentals', 'delete') ?? false;
    $canCreateDeliveries = $currentUser?->canAccessModule('deliveries', 'create') ?? false;
    $canUpdateDeliveries = $currentUser?->canAccessModule('deliveries', 'update') ?? false;
    $canReadInvoices = $currentUser?->canAccessModule('invoices', 'read') ?? false;
    $canSeeRentalFinance = $currentUser?->canSeeRentalFinance() ?? false;
    $canViewFinanceSummary = $currentUser?->isSuperAdmin() ?? false;
    $currency = fn ($value) => "\u{20B9}" . number_format((float) $value, 2);
    $queryWithoutPage = collect(request()->query())->except('page')->all();
    $rentalUrl = function (array $overrides = []) use ($queryWithoutPage) {
        $query = array_merge($queryWithoutPage, $overrides);

        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return route('rentals.index', $query);
    };

    $statusBadge = function (?string $status) {
        return match ($status) {
            'active', 'completed' => 'background:#dcfce7;color:#166534;',
            'returned' => 'background:#dbeafe;color:#1d4ed8;',
            'in_progress' => 'background:#fef3c7;color:#b45309;',
            'overdue' => 'background:#fee2e2;color:#b91c1c;',
            'delivery_pending' => 'background:#fff7ed;color:#c2410c;',
            'hotlisted' => 'background:#7f1d1d;color:#fee2e2;',
            'cancelled' => 'background:#f1f5f9;color:#64748b;',
            'pending', 'assigned' => 'background:#e2e8f0;color:#334155;',
            default => 'background:#f8fafc;color:#475569;',
        };
    };
    $rentalPaymentBadge = function (?string $invoicePaymentStatus, ?int $linkedInvoiceId, float $outstandingBalance, float $invoiceTotalAmount, float $orderTotalAmount) {
        $normalizedStatus = strtolower(trim((string) $invoicePaymentStatus));

        if ($normalizedStatus === 'paid') {
            return ['label' => 'Paid', 'tone' => 'background:#dcfce7;color:#166534;', 'class' => 'rn-badge-success'];
        }

        if ($normalizedStatus === 'partial') {
            return ['label' => 'Partial', 'tone' => 'background:#fef3c7;color:#b45309;', 'class' => 'rn-badge-warning'];
        }

        if (in_array($normalizedStatus, ['unpaid', 'overdue', 'pending', 'draft'], true)) {
            return ['label' => 'Pending', 'tone' => 'background:#fee2e2;color:#b91c1c;', 'class' => 'rn-badge-danger'];
        }

        $hasLinkedInvoice = ! empty($linkedInvoiceId);
        $hasOpenBalance = $outstandingBalance > 0.009;
        $effectiveTotal = $invoiceTotalAmount > 0.009 ? $invoiceTotalAmount : $orderTotalAmount;
        $isPartial = $hasOpenBalance && $effectiveTotal > 0.009 && $outstandingBalance < $effectiveTotal;

        if ($hasLinkedInvoice && $isPartial) {
            return ['label' => 'Partial', 'tone' => 'background:#fef3c7;color:#b45309;', 'class' => 'rn-badge-warning'];
        }

        if ($hasLinkedInvoice && ! $hasOpenBalance) {
            return ['label' => 'Paid', 'tone' => 'background:#dcfce7;color:#166534;', 'class' => 'rn-badge-success'];
        }

        return ['label' => 'Pending', 'tone' => 'background:#fee2e2;color:#b91c1c;', 'class' => 'rn-badge-danger'];
    };
    $taskAssignmentLabel = function ($task, string $fallback = 'Not assigned') {
        if (! $task) {
            return $fallback;
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
            default => $fallback,
        };
    };
    $mobileSortOptions = [
        ['value' => 'latest', 'label' => 'Newest First'],
        ['value' => 'oldest', 'label' => 'Oldest First'],
        ['value' => 'amount_desc', 'label' => 'Amount High-Low'],
        ['value' => 'amount_asc', 'label' => 'Amount Low-High'],
        ['value' => 'priority', 'label' => 'Priority'],
    ];
    $currentMobileSortLabel = collect($mobileSortOptions)->firstWhere('value', $sortBy)['label'] ?? 'Newest First';
    $hasActiveFilters = filled($search) || filled($status) || filled($deliveryStatus) || filled($pickupStatus) || filled($warehouseId) || filled($city) || filled($vendorId) || filled($fromDate) || filled($toDate) || $sortBy !== 'priority';
    $activeFilterChips = collect([
        filled($search) ? 'Search: ' . $search : null,
        filled($status) ? 'Rental: ' . ucfirst(str_replace('_', ' ', $status)) : null,
        filled($deliveryStatus) ? 'Delivery: ' . ucfirst(str_replace('_', ' ', $deliveryStatus)) : null,
        filled($pickupStatus) ? 'Pickup: ' . ucfirst(str_replace('_', ' ', $pickupStatus)) : null,
        filled($warehouseId) ? 'Warehouse selected' : null,
        filled($city) ? 'City: ' . $city : null,
        filled($vendorId) ? 'Assignee selected' : null,
        filled($fromDate) ? 'From: ' . $fromDate : null,
        filled($toDate) ? 'To: ' . $toDate : null,
        $sortBy !== 'priority' ? 'Sort: ' . $currentMobileSortLabel : null,
    ])->filter()->values();
@endphp

<style>
    .rentals-page { display:grid; gap:10px; width:100%; max-width:100%; min-width:0; margin:0 auto; padding:6px 0 18px; box-sizing:border-box; overflow:hidden; }
    .rentals-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; background:#fff; border:1px solid var(--ph-color-border); border-radius:18px; padding:14px 16px; box-shadow:var(--ph-shadow-soft); min-width:0; }
    .rentals-header h1 { margin:0; font-size:26px; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .rentals-header p { margin:5px 0 0; color:var(--ph-color-text-soft); font-size:13px; max-width:760px; }
    .rentals-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; min-width:0; }
    .ops-btn, .ops-btn-light, .ops-btn-success, .ops-btn-danger, .ops-btn-wa {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        min-height:32px; padding:7px 11px; border-radius:10px; border:1px solid transparent;
        text-decoration:none; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap;
    }
    .ops-btn { background:var(--ph-color-primary); color:#fff; border-color:var(--ph-color-primary); }
    .ops-btn-light { background:#fff; color:var(--ph-color-text); border-color:var(--ph-color-border-strong); }
    .ops-btn-success { background:var(--ph-color-success); color:#fff; }
    .ops-btn-wa { background:var(--ph-color-success-soft); color:var(--ph-color-success); border-color:rgba(14,159,75,.18); min-width:34px; padding:7px 10px; }
    .ops-btn-danger { background:var(--ph-color-danger); color:#fff; }
    .ops-card {
        background:#fff; border:1px solid var(--ph-color-border); border-radius:14px;
        box-shadow:var(--ph-shadow-soft);
        min-width:0;
        max-width:100%;
    }
    .ops-card-head {
        display:flex; justify-content:space-between; align-items:center; gap:10px;
        padding:10px 12px; border-bottom:1px solid var(--ph-color-border);
    }
    .ops-card-head h2 { margin:0; font-size:15px; color:var(--ph-color-text); font-family: var(--ph-font-heading); }
    .ops-card-head span { color:var(--ph-color-text-soft); font-size:12px; }
    .ops-card-body { padding:10px 12px; min-width:0; }
    .summary-grid { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:8px; }
    .summary-card {
        display:grid; gap:4px; padding:9px 11px; border-radius:14px; border:1px solid #dbe3ef;
        background:#ffffff;
        text-decoration:none; color:inherit; transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        min-height:88px;
    }
    .summary-card:hover { transform:translateY(-1px); box-shadow:var(--ph-shadow-card); border-color:var(--ph-color-border-strong); }
    .summary-card span { color:var(--ph-color-text-soft); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-family: var(--ph-font-heading); }
    .summary-card strong { font-size:19px; color:var(--ph-color-text); line-height:1; font-family: var(--ph-font-heading); }
    .summary-card small { color:var(--ph-color-text-soft); font-size:12px; }
    .summary-card.accent-active { border-color:rgba(14,159,75,.18); background:var(--ph-color-success-soft); }
    .summary-card.accent-alert { border-color:rgba(179,13,35,.18); background:var(--ph-color-danger-soft); }
    .summary-card.accent-warning { border-color:rgba(183,121,31,.18); background:var(--ph-color-warning-soft); }
    .summary-card.accent-neutral { border-color:rgba(23,119,189,.18); background:var(--ph-color-info-soft); }
    .filter-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
    .filter-field { display:grid; gap:5px; }
    .filter-field label { font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .desktop-search-shell {
        display:grid;
        gap:10px;
        padding:12px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        box-shadow:var(--ph-shadow-soft);
    }
    .desktop-search-form {
        display:grid;
        grid-template-columns:minmax(0, 1fr) auto auto;
        gap:8px;
        align-items:end;
    }
    .desktop-search-field { display:grid; gap:6px; min-width:0; }
    .desktop-search-field label { font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .desktop-filter-chip-row { display:flex; gap:8px; flex-wrap:wrap; }
    .desktop-filter-chip {
        display:inline-flex; align-items:center; min-height:30px; padding:6px 10px;
        border-radius:999px; border:1px solid #dbe3ef; background:#fff; color:#334155; font-size:12px; font-weight:700;
    }
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
    .quick-filter-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .quick-filter-chip {
        display:inline-flex; align-items:center; justify-content:center; min-height:32px; padding:6px 10px;
        border-radius:999px; border:1px solid #cbd5e1; background:#fff; color:#334155; text-decoration:none;
        font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
    }
    .quick-filter-chip.is-active { background:#0f172a; color:#fff; border-color:#0f172a; }
    .mobile-chip-row { display:none; }
    .mobile-list-command { display:none; }
    .alert-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:12px; }
    .alert-list { display:grid; gap:8px; }
    .alert-item {
        display:flex; justify-content:space-between; align-items:center; gap:8px;
        border:1px solid #e2e8f0; border-radius:12px; padding:9px 10px; background:#fff;
    }
    .alert-item strong { display:block; color:#0f172a; font-size:13px; }
    .alert-item span { color:#64748b; font-size:12px; }
    .table-wrap { overflow-x:auto; overflow-y:visible; width:100%; max-width:100%; min-width:0; border-radius:12px; }
    .table-wrap::-webkit-scrollbar { height:8px; }
    .table-wrap::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
    .ops-table { width:100%; border-collapse:separate; border-spacing:0; min-width:1280px; table-layout:auto; }
    .ops-table th, .ops-table td { padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; position:relative; }
    .ops-table tr:has(.ops-action-menu[open]) { position:relative; z-index:60; }
    .ops-table th {
        position:sticky; top:0; background:#f8fafc; color:#64748b; font-size:11px;
        text-transform:uppercase; letter-spacing:.05em; font-weight:800; z-index:1;
    }
    .ops-table td { font-size:13px; color:#0f172a; background:#fff; line-height:1.45; }
    .ops-table tbody tr.rental-row td { background:#fff; transition:box-shadow .18s ease, background-color .18s ease, transform .18s ease; }
    .ops-table tbody tr.rental-row:hover td { background:#fbfdff; }
    .ops-table tbody tr.rental-row td:first-child { border-left:1px solid #e2e8f0; border-top-left-radius:12px; border-bottom-left-radius:12px; }
    .ops-table tbody tr.rental-row td:last-child { border-right:1px solid #e2e8f0; border-top-right-radius:12px; border-bottom-right-radius:12px; }
    .ops-table tbody tr.rental-row td { border-top:1px solid #e2e8f0; }
    .ops-table tbody tr.rental-row.row-overdue td { background:#fff7f7; border-color:#fecaca; }
    .ops-table tbody tr.rental-row.row-overdue:hover td { background:#fff1f2; }
    .ops-table tbody tr.rental-row.row-due-today td { background:#fffdf3; }
    .ops-table tbody tr.rental-row.row-due-soon td { background:#fffcf4; }
    .serial-col { width:54px; min-width:54px; color:#64748b; font-weight:700; }
    .rental-id-col { width:120px; min-width:120px; }
    .customer-col { width:240px; min-width:240px; }
    .items-col { width:210px; min-width:210px; }
    .date-col { width:116px; min-width:116px; }
    .status-col { width:190px; min-width:190px; }
    .balance-col { width:130px; min-width:130px; }
    .cell-title { display:flex; align-items:flex-start; gap:8px; }
    .cell-title.has-wa { justify-content:space-between; }
    .cell-title strong { display:block; font-size:13px; color:#0f172a; }
    .cell-subtle { display:block; color:#64748b; font-size:12px; line-height:1.45; }
    .cell-subtle.is-danger { color:#b91c1c; font-weight:700; }
    .cell-subtle.is-warning { color:#b45309; font-weight:700; }
    .customer-contact-actions { display:flex; align-items:flex-start; gap:8px; flex:0 0 auto; padding-top:1px; }
    .product-wrap { white-space:normal; word-break:break-word; overflow-wrap:anywhere; }
    .items-cell { min-width:220px; }
    .items-line {
        display:flex; align-items:center; gap:6px; flex-wrap:wrap;
    }
    .items-primary { max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .item-qty {
        display:inline-flex; align-items:center; justify-content:center; min-height:20px; padding:0 7px;
        border-radius:999px; background:var(--ph-color-info-soft); color:var(--ph-color-primary); font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
    }
    .date-pill {
        display:inline-flex; align-items:center; gap:6px; padding:5px 8px; border-radius:999px; font-size:11px; font-weight:700;
    }
    .date-pill.is-due-today { background:var(--ph-color-warning-soft); color:var(--ph-color-warning); border:1px solid rgba(183,121,31,.22); }
    .date-pill.is-overdue { background:var(--ph-color-danger-soft); color:var(--ph-color-danger); border:1px solid rgba(179,13,35,.18); }
    .date-pill.is-due-soon { background:var(--ph-color-warning-soft); color:var(--ph-color-warning); border:1px solid rgba(183,121,31,.18); }
    .badge {
        display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px;
        font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
    }
    .cell-stack { display:grid; gap:5px; }
    .cell-stack.compact { gap:3px; }
    .cell-inline { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .row-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start; }
    .row-actions-primary { display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start; }
    .row-actions-secondary { display:flex; align-items:flex-start; gap:6px; }
    .row-actions .ops-btn,
    .row-actions .ops-btn-light,
    .row-actions .ops-btn-success,
    .row-actions .ops-btn-danger,
    .row-actions .ops-btn-wa { min-height:30px; padding:6px 9px; font-size:11px; justify-content:flex-start; }
    .row-actions-primary .ops-btn,
    .row-actions-primary .ops-btn-light,
    .row-actions-primary .ops-btn-wa,
    .row-actions-primary .ops-btn-success,
    .row-actions-primary .ops-btn-danger { min-width:0; }
    .action-icon {
        width:14px; height:14px; flex:0 0 14px;
    }
    .actions-cell { position:sticky; right:0; z-index:8; width:280px; min-width:280px; text-align:left; background:#fff !important; box-shadow:-12px 0 20px rgba(11,35,66,.08); overflow:visible; }
    .ops-table tr:has(.ops-action-menu[open]) .actions-cell { z-index:1000; }
    .ops-table th.actions-cell { z-index:4; background:var(--ph-color-surface-soft) !important; }
    .ops-action-menu { position:relative; display:inline-block; z-index:20; }
    .ops-action-menu summary { list-style:none; display:inline-flex; align-items:center; justify-content:center; width:40px; height:36px; border:1px solid var(--ph-color-border-strong); border-radius:999px; background:#fff; color:var(--ph-color-text); font-weight:900; cursor:pointer; user-select:none; }
    .ops-action-menu summary::-webkit-details-marker { display:none; }
    .ops-action-menu[open] summary { background:var(--ph-color-info-soft); border-color:rgba(23,119,189,.26); color:var(--ph-color-primary); }
    .ops-action-panel { position:absolute; right:48px; top:0; z-index:999; display:grid; gap:6px; min-width:190px; padding:8px; border:1px solid var(--ph-color-border); border-radius:12px; background:#fff; box-shadow:0 18px 40px rgba(11,35,66,.16); text-align:left; }
    .hotlist-note { color:var(--ph-color-danger); font-size:11px; font-weight:700; }
    .empty-state { color:var(--ph-color-text-soft); font-size:13px; padding:18px 0; }
    @media (max-width: 1180px) {
        .summary-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
        .ops-table { min-width:1200px; }
    }
    @media (max-width: 820px) {
        .ops-table { min-width:1120px; }
    }
    @media (max-width: 767px) {
        .rentals-page { padding:4px 0 16px; gap:8px; }
        .rentals-header { display:none; }
        .desktop-priority-panel { display:none !important; }
        .desktop-search-shell { display:none; }
        .summary-grid, .filter-grid { grid-template-columns:1fr; }
        .filter-actions, .rentals-actions { flex-direction:column; align-items:stretch; }
        .quick-filter-bar { display:none; }
        .mobile-list-command {
            display:grid; gap:8px; padding:8px; border:1px solid var(--ph-color-border); border-radius:16px;
            background:#fff; box-shadow:var(--ph-shadow-soft);
        }
        .mobile-search-row { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; }
        .mobile-search-row .ops-input { min-height:40px; border-radius:12px; font-size:16px; }
        .mobile-search-row .ops-btn { min-height:40px; border-radius:12px; padding:7px 12px; }
        .mobile-stat-strip {
            display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:6px;
        }
        .mobile-stat-strip a {
            display:grid; gap:2px; min-width:0; padding:7px 8px; border:1px solid var(--ph-color-border); border-radius:12px;
            background:var(--ph-color-surface-soft); color:var(--ph-color-text); text-decoration:none;
        }
        .mobile-stat-strip span { font-size:9px; color:var(--ph-color-text-soft); font-weight:800; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mobile-stat-strip strong { font-size:16px; line-height:1; }
        .mobile-filter-toggle {
            border:1px solid var(--ph-color-border); border-radius:12px; background:var(--ph-color-surface-soft); overflow:hidden;
        }
        .mobile-filter-toggle summary {
            list-style:none; cursor:pointer; min-height:36px; display:flex; align-items:center; justify-content:space-between;
            padding:8px 10px; font-size:12px; font-weight:800; color:var(--ph-color-text);
        }
        .mobile-filter-toggle summary::-webkit-details-marker { display:none; }
        .mobile-filter-body { padding:0 10px 10px; display:grid; gap:8px; }
        .mobile-filter-body .filter-grid { gap:8px; }
        .rentals-page > .mobile-chip-row { display:none; }
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
        .table-wrap { overflow-x:visible; }
        .ops-table { min-width:0; border-collapse:separate; border-spacing:0 10px; }
        .ops-table thead { display:none; }
        .ops-table, .ops-table tbody, .ops-table tr, .ops-table td { display:block; width:100%; }
        .ops-table tr {
            border:1px solid var(--ph-color-border); border-radius:14px; background:#fff;
            box-shadow:var(--ph-shadow-soft); overflow:hidden;
        }
        .ops-table tbody tr.rental-row td,
        .ops-table tbody tr.rental-row td:first-child,
        .ops-table tbody tr.rental-row td:last-child { border-left:none; border-right:none; border-top:none; border-radius:0; }
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
        .ops-action-menu { display:block; width:100%; }
        .ops-action-menu summary { width:100%; justify-content:center; border-radius:12px; }
        .ops-action-panel { position:static; min-width:0; margin-top:7px; box-shadow:none; }
        .row-actions { gap:7px; }
        .row-actions-primary {
            display:grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:7px;
            width:100%;
        }
        .row-actions-primary .ops-btn,
        .row-actions-primary .ops-btn-light,
        .row-actions-primary .ops-btn-success,
        .row-actions-primary .ops-btn-danger,
        .row-actions-primary .ops-btn-wa {
            min-width:0;
            width:100%;
            min-height:44px;
            padding:0 8px;
            border-radius:12px;
            justify-content:center;
        }
        .row-actions-primary .mobile-utility-btn {
            padding:0;
            aspect-ratio:1 / 1;
        }
        .row-actions-primary .mobile-utility-btn span {
            display:none;
        }
        .row-actions-primary .mobile-utility-btn svg {
            width:18px;
            height:18px;
            flex:0 0 18px;
        }
        .row-actions-primary form,
        .row-actions-primary > a:not(.mobile-utility-btn) {
            grid-column:span 2;
        }
        .row-actions-secondary { display:flex; flex-wrap:wrap; gap:7px; }
        .serial-col { width:auto; min-width:0; }
    }
</style>

<div class="container rentals-page rn-list-page">
    <div class="rentals-header">
        <div>
            <div class="rx-eyebrow">Rental Ops</div>
            <h1>Rentals</h1>
            <p>Manage active, overdue, and completed rentals.</p>
        </div>

        <div class="rentals-actions">
            <a href="{{ route('rentals.export.csv', $queryWithoutPage) }}" class="ops-btn-light">Export CSV</a>
            @if($canCreateRentals)
                <a href="{{ route('rentals.create') }}" class="ops-btn">+ New Rental</a>
            @endif
        </div>
    </div>

    <div class="mobile-chip-row" aria-label="Rental quick filters">
        <a href="{{ $rentalUrl(['status' => 'active', 'filter' => null]) }}" class="mobile-chip {{ $status === 'active' ? 'is-active' : '' }}">Active</a>
        <a href="{{ $rentalUrl(['status' => null, 'filter' => 'returns_due_today']) }}" class="mobile-chip {{ $filter === 'returns_due_today' ? 'is-active' : '' }}">Due Today</a>
        <a href="{{ $rentalUrl(['status' => null, 'filter' => 'overdue']) }}" class="mobile-chip {{ $filter === 'overdue' ? 'is-active' : '' }}">Overdue</a>
        <a href="{{ $rentalUrl(['status' => 'returned', 'filter' => null]) }}" class="mobile-chip {{ $status === 'returned' ? 'is-active' : '' }}">Returned</a>
    </div>

    @if(session('success'))
        <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:11px 13px;border-radius:12px;">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:11px 13px;border-radius:12px;">
            {{ session('error') }}
        </div>
    @endif

    <div id="rentals-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
        <div class="mobile-filter-sheet-panel">
            <div class="mobile-filter-sheet-header">
                <div>
                    <h3>Rental Filters</h3>
                    <p>Apply filters without losing your place in the list.</p>
                </div>
                <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="rentals-mobile-filters" aria-label="Close filters">×</button>
            </div>
            <div class="mobile-filter-sheet-body">
                <form method="GET" action="{{ route('rentals.index') }}" class="mobile-sheet-form">
                    @if($filter)
                        <input type="hidden" name="filter" value="{{ $filter }}">
                    @endif
                    <input type="hidden" name="search" value="{{ $search }}">
                    <input type="hidden" name="sort_by" value="{{ $sortBy }}">
                    <div class="mobile-sheet-grid">
                        <div class="mobile-sheet-field">
                            <label for="mobile_status">Rental Status</label>
                            <select id="mobile_status" class="ops-select" name="status">
                                <option value="">All</option>
                                <option value="active" @selected($status === 'active')>Active</option>
                                <option value="delivery_pending" @selected($status === 'delivery_pending')>Delivery Pending</option>
                                <option value="returned" @selected($status === 'returned')>Returned</option>
                                <option value="overdue" @selected($status === 'overdue')>Overdue</option>
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_delivery_status">Delivery</label>
                            <select id="mobile_delivery_status" class="ops-select" name="delivery_status">
                                <option value="">All</option>
                                <option value="pending" @selected($deliveryStatus === 'pending')>Pending</option>
                                <option value="assigned" @selected($deliveryStatus === 'assigned')>Assigned</option>
                                <option value="in_progress" @selected($deliveryStatus === 'in_progress')>Out</option>
                                <option value="completed" @selected($deliveryStatus === 'completed')>Delivered</option>
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
                        <a href="{{ route('rentals.index') }}" class="ops-btn-light">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="summary-grid desktop-priority-panel">
        <a href="{{ $rentalUrl(['status' => 'live', 'filter' => null]) }}" class="summary-card accent-active rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1Z"/></svg>
            </span>
            <span>Active Rentals</span>
            <strong>{{ $activeRentals }}</strong>
            <small>{{ $currentRentals }} current + {{ $overdueCount }} overdue</small>
        </a>
        <a href="{{ $rentalUrl(['status' => null, 'filter' => 'ending_soon']) }}" class="summary-card accent-warning rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><path d="m12 16 .01 0"/><path d="M10.3 3.4 1.82 18a2 2 0 0 0 1.73 3h16.9a2 2 0 0 0 1.73-3L13.7 3.4a2 2 0 0 0-3.4 0Z"/></svg>
            </span>
            <span>Ending Soon</span>
            <strong>{{ $endingSoonCount }}</strong>
            <small>Due within alert window</small>
        </a>
        <a href="{{ $rentalUrl(['status' => 'overdue', 'filter' => 'overdue']) }}" class="summary-card accent-alert rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><path d="m12 16 .01 0"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
            </span>
            <span>Overdue</span>
            <strong>{{ $overdueCount }}</strong>
            <small>Delivered and past end date</small>
        </a>
        <a href="{{ $rentalUrl(['status' => null, 'filter' => null]) }}" class="summary-card accent-neutral rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16"/><path d="M4 18h10"/><path d="M4 6h16"/></svg>
            </span>
            <span>Total Rentals</span>
            <strong>{{ $totalRentals }}</strong>
            <small>Filtered rentals excluding cancelled</small>
        </a>
        <a href="{{ $rentalUrl(['status' => 'returned', 'filter' => null]) }}" class="summary-card accent-neutral rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>
            </span>
            <span>Returned Rentals</span>
            <strong>{{ $returnedRentals }}</strong>
            <small>Closed rental orders</small>
        </a>
        <a href="{{ $rentalUrl(['status' => null, 'filter' => null]) }}" class="summary-card accent-warning rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="m10 12 2 2 4-4"/></svg>
            </span>
            <span>Follow-up Queue</span>
            <strong>{{ $renewalQueueCount }}</strong>
            <small>Needs renewal or collection follow-up</small>
        </a>
        <a href="{{ $rentalUrl(['status' => null, 'filter' => null]) }}#renewal-workspace" class="summary-card accent-warning rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
            </span>
            <span>Unbilled Renewals</span>
            <strong>{{ $unbilledRenewalCount }}</strong>
            <small>{{ $currency($unbilledRenewalAmount) }} awaiting invoice</small>
        </a>
        <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}" class="summary-card accent-alert rn-summary-link">
            <span class="rn-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </span>
            <span>Unpaid Renewal Invoices</span>
            <strong>{{ $unpaidRenewalCount }}</strong>
            <small>{{ $currency($unpaidRenewalAmount) }} pending collection</small>
        </a>
    </div>

    <div class="mobile-list-command" aria-label="Mobile rental controls">
        <form method="GET" action="{{ route('rentals.index') }}" class="mobile-search-row">
            @foreach(request()->except(['search', 'page']) as $key => $value)
                @if(is_scalar($value) && $value !== '')
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <input class="ops-input" type="search" name="search" value="{{ $search }}" placeholder="Search rentals, phone, product">
            <button type="submit" class="ops-btn">Search</button>
        </form>

        <div class="mobile-stat-strip" aria-label="Rental summary">
            <a href="{{ $rentalUrl(['status' => null, 'filter' => null]) }}"><span>Total</span><strong>{{ $totalRentals }}</strong></a>
            <a href="{{ $rentalUrl(['status' => 'active', 'filter' => null]) }}"><span>Active</span><strong>{{ $activeRentals }}</strong></a>
            <a href="{{ $rentalUrl(['status' => null, 'filter' => 'overdue']) }}"><span>Overdue</span><strong>{{ $overdueCount }}</strong></a>
            <a href="{{ $rentalUrl(['status' => null, 'filter' => 'ending_soon']) }}"><span>Renew</span><strong>{{ $renewalQueueCount }}</strong></a>
            <a href="{{ $rentalUrl(['status' => null, 'filter' => null]) }}#renewal-workspace"><span>Unbilled</span><strong>{{ $unbilledRenewalCount }}</strong></a>
            <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}"><span>Renewal Due</span><strong>{{ $unpaidRenewalCount }}</strong></a>
        </div>

        <div class="mobile-chip-row" aria-label="Rental quick filters">
            <a href="{{ $rentalUrl(['status' => 'active', 'filter' => null]) }}" class="mobile-chip {{ $status === 'active' ? 'is-active' : '' }}">Active</a>
            <a href="{{ $rentalUrl(['status' => null, 'filter' => 'returns_due_today']) }}" class="mobile-chip {{ $filter === 'returns_due_today' ? 'is-active' : '' }}">Due Today</a>
            <a href="{{ $rentalUrl(['status' => null, 'filter' => 'overdue']) }}" class="mobile-chip {{ $filter === 'overdue' ? 'is-active' : '' }}">Overdue</a>
            <a href="{{ $rentalUrl(['status' => 'returned', 'filter' => null]) }}" class="mobile-chip {{ $status === 'returned' ? 'is-active' : '' }}">Returned</a>
        </div>

        <div class="mobile-action-toolbar" aria-label="Mobile rental filters and sorting">
            <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="rentals-mobile-filters">
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
                        <a href="{{ $rentalUrl(['sort_by' => $option['value']]) }}" class="mobile-sort-option {{ $sortBy === $option['value'] ? 'is-active' : '' }}">{{ $option['label'] }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="desktop-search-shell">
        <form method="GET" action="{{ route('rentals.index') }}" class="desktop-search-form">
            @foreach(request()->except(['search', 'page']) as $key => $value)
                @if(is_scalar($value) && $value !== '')
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <div class="desktop-search-field">
                <label for="desktop-rental-search">Search rentals</label>
                <input id="desktop-rental-search" class="ops-input" type="search" name="search" value="{{ $search }}" placeholder="Search customer, phone, product, asset serial">
            </div>
            <button type="submit" class="ops-btn">Search</button>
            <a href="{{ route('rentals.index') }}" class="ops-btn-light" data-filter-clear="rentals-index">Clear Filters</a>
        </form>
        @if($hasActiveFilters)
            <div class="desktop-filter-chip-row">
                @foreach($activeFilterChips as $chip)
                    <span class="desktop-filter-chip">{{ $chip }}</span>
                @endforeach
            </div>
        @endif
    </div>

    <details class="ops-card desktop-priority-panel desktop-filter-toggle" data-filter-panel data-filter-panel-key="rentals-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" @if($hasActiveFilters) open @endif>
        <summary>
            <h2>Search & Filters</h2>
            <span>{{ $hasActiveFilters ? 'Filters Active · ' . $activeFilterChips->count() : 'Expand advanced filters' }}</span>
        </summary>
        <div class="ops-card-body">
            <form method="GET" action="{{ route('rentals.index') }}" style="display:grid; gap:12px;">
                @if($filter)
                    <input type="hidden" name="filter" value="{{ $filter }}">
                @endif

                <div class="filter-grid">
                    <div class="filter-field">
                        <label for="search">Search</label>
                        <input id="search" class="ops-input" type="text" name="search" value="{{ $search }}" placeholder="Customer, phone, product, asset serial">
                    </div>

                    <div class="filter-field">
                        <label for="status">Rental Status</label>
                        <select id="status" class="ops-select" name="status">
                            <option value="">All</option>
                            <option value="live" @selected($status === 'live')>Live (Current + Overdue)</option>
                            <option value="active" @selected($status === 'active')>Active</option>
                            <option value="delivery_pending" @selected($status === 'delivery_pending')>Delivery Pending</option>
                            <option value="returned" @selected($status === 'returned')>Returned</option>
                            <option value="overdue" @selected($status === 'overdue')>Overdue</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="delivery_status">Delivery</label>
                        <select id="delivery_status" class="ops-select" name="delivery_status">
                            <option value="">All</option>
                            <option value="pending" @selected($deliveryStatus === 'pending')>Pending</option>
                            <option value="assigned" @selected($deliveryStatus === 'assigned')>Assigned</option>
                            <option value="in_progress" @selected($deliveryStatus === 'in_progress')>Out for Delivery</option>
                            <option value="completed" @selected($deliveryStatus === 'completed')>Delivered</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="pickup_status">Pickup</label>
                        <select id="pickup_status" class="ops-select" name="pickup_status">
                            <option value="">All</option>
                            <option value="pending" @selected($pickupStatus === 'pending')>Pending</option>
                            <option value="assigned" @selected($pickupStatus === 'assigned')>Assigned</option>
                            <option value="in_progress" @selected($pickupStatus === 'in_progress')>Out for Pickup</option>
                            <option value="completed" @selected($pickupStatus === 'completed')>Picked Up</option>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="dispatch_warehouse_id">Warehouse</label>
                        <select id="dispatch_warehouse_id" class="ops-select" name="dispatch_warehouse_id">
                            <option value="">All Warehouses</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((string) $warehouseId === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="city">City</label>
                        <select id="city" class="ops-select" name="city">
                            <option value="">All Cities</option>
                            @foreach($cities as $cityOption)
                                <option value="{{ $cityOption }}" @selected($city === $cityOption)>{{ $cityOption }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="vendor_id">Vendor / Assignee</label>
                        <select id="vendor_id" class="ops-select" name="vendor_id">
                            <option value="">All Assignable Staff</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) $vendorId === (string) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
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
                            <option value="end_date_asc" @selected($sortBy === 'end_date_asc')>End Date: Earliest</option>
                            <option value="end_date_desc" @selected($sortBy === 'end_date_desc')>End Date: Latest</option>
                            <option value="customer_asc" @selected($sortBy === 'customer_asc')>Customer A-Z</option>
                            <option value="customer_desc" @selected($sortBy === 'customer_desc')>Customer Z-A</option>
                            <option value="amount_desc" @selected($sortBy === 'amount_desc')>Amount: High to Low</option>
                            <option value="amount_asc" @selected($sortBy === 'amount_asc')>Amount: Low to High</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="ops-btn">Apply Filters</button>
                    <a href="{{ route('rentals.index') }}" class="ops-btn-light" data-filter-clear="rentals-index">Reset</a>
                    <a href="{{ route('rentals.index', array_merge($queryWithoutPage, ['filter' => 'ending_soon', 'status' => null])) }}" target="_blank" class="ops-btn-light">Ending Soon</a>
                    <a href="{{ route('rentals.index', array_merge($queryWithoutPage, ['filter' => 'overdue', 'status' => null])) }}" target="_blank" class="ops-btn-light">Overdue</a>
                </div>
            </form>
        </div>
    </details>

    <div class="ops-card rn-table-shell">
        <div class="ops-card-head">
            <h2>Rental List</h2>
            <span>{{ $rentals->total() }} records - one row per rental</span>
        </div>
        <div class="ops-card-body">
            <div class="quick-filter-bar desktop-priority-panel" aria-label="Quick rental filters">
                <a href="{{ $rentalUrl(['status' => null, 'filter' => 'overdue']) }}" class="quick-filter-chip {{ $filter === 'overdue' ? 'is-active' : '' }}">Overdue</a>
                <a href="{{ $rentalUrl(['status' => null, 'filter' => 'returns_due_today']) }}" class="quick-filter-chip {{ $filter === 'returns_due_today' ? 'is-active' : '' }}">Today</a>
                <a href="{{ $rentalUrl(['status' => 'active', 'filter' => null]) }}" class="quick-filter-chip {{ $status === 'active' ? 'is-active' : '' }}">Active</a>
                <a href="{{ $rentalUrl(['status' => 'returned', 'filter' => null]) }}" class="quick-filter-chip {{ $status === 'returned' ? 'is-active' : '' }}">Completed</a>
            </div>

            @if($rentals->isEmpty())
                <div class="empty-state">No rentals match this view right now. Adjust filters or create a new rental to keep operations moving.</div>
            @else
                @if($canReadInvoices)
                    <form id="rentalInvoiceBulkForm" method="GET" action="{{ route('invoices.bulk.print') }}" target="_blank" class="bulk-toolbar">
                        @csrf
                        <div>
                            <strong>Invoice bulk actions</strong>
                            <span id="rentalInvoiceSelectedCount">0 selected</span>
                        </div>
                        <div class="bulk-actions">
                            <button type="submit" class="ops-btn-light" data-rental-bulk-action="{{ route('invoices.bulk.print') }}" data-rental-bulk-method="GET" data-rental-bulk-target="_blank">Bulk PDF / Print</button>
                            <button type="submit" class="ops-btn-light" data-rental-bulk-action="{{ route('invoices.export.csv') }}" data-rental-bulk-method="GET" data-rental-bulk-target="_self">Export Selected CSV</button>
                            <button type="submit" class="ops-btn-light" data-rental-bulk-action="{{ route('invoices.bulk.action') }}" data-rental-bulk-method="POST" data-rental-bulk-task="mark_paid" data-rental-bulk-target="_self">Mark Paid</button>
                            <button type="submit" class="ops-btn-danger" data-rental-bulk-action="{{ route('invoices.bulk.action') }}" data-rental-bulk-method="POST" data-rental-bulk-task="void" data-rental-bulk-target="_self" data-rental-confirm="Void selected invoices?">Void</button>
                        </div>
                    </form>
                @endif

                <div class="table-wrap">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th class="bulk-col"><input type="checkbox" class="bulk-check" id="selectAllRentalRows" aria-label="Select all rentals"></th>
                                <th class="serial-col">#</th>
                                <th class="rental-id-col">Rental ID</th>
                                <th class="customer-col">Customer</th>
                                <th class="items-col">Items</th>
                                <th class="date-col">Start Date</th>
                                <th class="date-col">Due Date</th>
                                <th class="status-col">Status</th>
                                @if($canSeeRentalFinance)<th class="balance-col">Balance</th>@endif
                                <th class="actions-cell">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rentals as $rental)
                                @php
                                    $deliveryStatusValue = $rental->deliveryStatus();
                                    $pickupStatusValue = $rental->pickupStatus();
                                    $operationalStatus = $rental->operationalStatus();
                                    $isHotlisted = $rental->shouldHotlist();
                                    $latestReminderAt = $rental->latestReminderSentAt();
                                    $reminderType = $isHotlisted || $operationalStatus === 'overdue' ? 'overdue' : 'renewal';
                                    $activeAssets = $rental->activeRentalAssets ?? collect();
                                    $assetSummary = $activeAssets->pluck('asset.serial_number')->filter()->implode(', ');
                                    $whatsAppUrl = route('rentals.reminders.open', ['rental' => $rental, 'type' => $reminderType]);
                                    $renewalWhatsAppUrl = route('rentals.reminders.open', ['rental' => $rental, 'type' => 'renewal']);
                                    $totalAmount = (float) ($rental->rental_amount ?? 0) + (float) ($rental->deposit_amount ?? 0) + (float) ($rental->transport_amount ?? 0) + (float) ($rental->other_amount ?? 0);
                                    $pickupAssignUrl = route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']);
                                    $deliveryAssignee = $taskAssignmentLabel($rental->deliveryRecord, $rental->deliveryStaff->name ?? 'Not assigned');
                                    $pickupAssignee = $taskAssignmentLabel($rental->pickupRecord, $rental->pickupStaff->name ?? 'Not assigned');
                                    $hasOpenDeliveryTask = in_array($deliveryStatusValue, ['pending', 'in_progress'], true);
                                    $hasOpenPickupTask = in_array($pickupStatusValue, ['pending', 'in_progress'], true);
                                    $rowNumber = method_exists($rentals, 'firstItem') && $rentals->firstItem()
                                        ? $rentals->firstItem() + $loop->index
                                        : $loop->iteration;
                                    $customerPhoneHref = $rental->phone ? 'tel:' . preg_replace('/\s+/', '', $rental->phone) : null;
                                    $endDate = $rental->end_date;
                                    $isDueToday = $endDate?->isToday() ?? false;
                                    $isOverdueDate = $operationalStatus === 'overdue' || $isHotlisted;
                                    $isEndingSoonDate = !$isOverdueDate && $endDate && $endDate->isFuture() && now()->diffInDays($endDate, false) <= 2;
                                    $rentalItems = $rental->rentalItems ?? collect();
                                    $displayItems = $rentalItems->isNotEmpty()
                                        ? $rentalItems->map(function ($item) {
                                            $name = $item->product?->name ?? 'Rental item';
                                            $qty = (int) ($item->quantity ?? 1);

                                            return ['name' => $name, 'qty' => $qty];
                                        })
                                        : collect([['name' => $rental->product->name ?? 'Product not linked', 'qty' => (int) ($rental->quantity ?? 1)]]);
                                    $primaryItem = $displayItems->first();
                                    $extraItemCount = max(0, $displayItems->count() - 1);
                                    $outstandingBalance = (float) ($rental->outstanding_invoice_balance ?? 0);
                                    $linkedInvoiceId = (int) ($rental->linked_invoice_id ?? 0);
                                    $linkedInvoiceStatus = $rental->linked_invoice_payment_status ?? null;
                                    $linkedInvoiceTotalAmount = (float) ($rental->linked_invoice_total_amount ?? 0);
                                    $paymentBadge = $rentalPaymentBadge($linkedInvoiceStatus, $linkedInvoiceId, $outstandingBalance, $linkedInvoiceTotalAmount, $totalAmount);
                                    $balanceDisplay = $paymentBadge['label'] === 'Paid'
                                        ? 0
                                        : max(0, $linkedInvoiceId > 0 ? $outstandingBalance : $totalAmount);
                                @endphp
                                <tr class="rental-row {{ $isOverdueDate ? 'row-overdue' : ($isDueToday ? 'row-due-today' : ($isEndingSoonDate ? 'row-due-soon' : '')) }}">
                                    <td class="bulk-col" data-label="Select">
                                        <input type="checkbox" class="bulk-check rental-row-check {{ $canReadInvoices ? 'rental-invoice-check' : '' }}" value="{{ $rental->id }}" data-invoice-id="{{ $rental->linked_invoice_id }}" aria-label="Select rental #{{ $rental->id }}">
                                    </td>
                                    <td class="serial-col" data-label="Sl No.">
                                        {{ $rowNumber }}
                                    </td>
                                    <td class="rental-id-col" data-label="Rental ID">
                                        <div class="cell-stack compact">
                                            @if(\Illuminate\Support\Facades\Route::has('rentals.show'))
                                                <a href="{{ route('rentals.show', $rental) }}" class="rn-record-link">#{{ $rental->id }}</a>
                                            @else
                                                <strong>#{{ $rental->id }}</strong>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="customer-col" data-label="Customer">
                                        <div class="cell-title has-wa">
                                            <div style="min-width:0;">
                                                @if(\Illuminate\Support\Facades\Route::has('customers.show') && $rental->customer)
                                                    <a href="{{ route('customers.show', $rental->customer) }}" class="rn-record-link">{{ $rental->customer_name }}</a>
                                                @else
                                                    <strong>{{ $rental->customer_name }}</strong>
                                                @endif
                                                <span class="cell-subtle">
                                                    {{ $rental->phone ?: 'No phone' }}
                                                    @if($rental->customer?->city)
                                                        - {{ $rental->customer->city }}
                                                    @endif
                                                </span>
                                            </div>
                                            @if(WhatsAppHelper::resolveCustomerNumber($rental->customer))
                                                <div class="customer-contact-actions">
                                                <a href="{{ $whatsAppUrl }}" target="_blank" class="rn-wa-action" title="WhatsApp {{ $rental->customer_name }}" aria-label="WhatsApp {{ $rental->customer_name }}">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                                    <span class="rn-wa-action-label">WhatsApp</span>
                                                </a>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="items-col items-cell" data-label="Items">
                                        <div class="cell-stack compact">
                                            <div class="items-line">
                                                @if(\Illuminate\Support\Facades\Route::has('products.show') && $rental->product)
                                                    <a href="{{ route('products.show', $rental->product) }}" class="rn-record-link product-wrap items-primary">{{ $primaryItem['name'] }}</a>
                                                @else
                                                    <strong class="product-wrap items-primary">{{ $primaryItem['name'] }}</strong>
                                                @endif
                                                <span class="item-qty">Qty {{ $primaryItem['qty'] }}</span>
                                            </div>
                                            @if($extraItemCount > 0)
                                                <span class="cell-subtle">+ {{ $extraItemCount }} more item{{ $extraItemCount === 1 ? '' : 's' }}</span>
                                            @else
                                                <span class="cell-subtle">Single item</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="date-col" data-label="Start Date">
                                        <div class="cell-stack compact">
                                            <strong>{{ optional($rental->start_date)->format('d M Y') }}</strong>
                                        </div>
                                    </td>
                                    <td class="date-col" data-label="Due Date">
                                        <div class="cell-stack compact">
                                            <strong>{{ optional($rental->end_date)->format('d M Y') }}</strong>
                                            @if($isOverdueDate)
                                                <span class="date-pill is-overdue">Overdue</span>
                                            @elseif($isDueToday)
                                                <span class="date-pill is-due-today">Due Today</span>
                                            @elseif($isEndingSoonDate && $endDate?->isTomorrow())
                                                <span class="date-pill is-due-soon">Due Tomorrow</span>
                                            @elseif($isEndingSoonDate)
                                                <span class="cell-subtle is-warning">Ending soon</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="status-col" data-label="Status">
                                        <div class="cell-stack compact">
                                            <div class="cell-inline">
                                                <span class="badge rn-badge {{ $isHotlisted ? 'rn-badge-danger' : ($operationalStatus === 'returned' ? 'rn-badge-success' : ($operationalStatus === 'overdue' ? 'rn-badge-danger' : ($operationalStatus === 'active' ? 'rn-badge-active' : ($operationalStatus === 'cancelled' ? 'rn-badge-muted' : 'rn-badge-warning')))) }}" style="{{ $statusBadge($isHotlisted ? 'hotlisted' : $operationalStatus) }}">
                                                    {{ $isHotlisted ? 'Hotlisted' : ucfirst(str_replace('_', ' ', $operationalStatus)) }}
                                                </span>
                                                <span class="badge rn-badge {{ $deliveryStatusValue === 'completed' ? 'rn-badge-success' : ($deliveryStatusValue === 'in_progress' ? 'rn-badge-active' : ($deliveryStatusValue === 'cancelled' ? 'rn-badge-muted' : 'rn-badge-warning')) }}" style="{{ $statusBadge($deliveryStatusValue) }}">
                                                    D {{ ucfirst(str_replace('_', ' ', $deliveryStatusValue ?: 'pending')) }}
                                                </span>
                                                <span class="badge rn-badge {{ $pickupStatusValue === 'completed' ? 'rn-badge-success' : ($pickupStatusValue === 'in_progress' ? 'rn-badge-active' : ($pickupStatusValue === 'cancelled' ? 'rn-badge-muted' : 'rn-badge-warning')) }}" style="{{ $statusBadge($pickupStatusValue) }}">
                                                    P {{ ucfirst(str_replace('_', ' ', $pickupStatusValue ?: 'pending')) }}
                                                </span>
                                            </div>
                                            <span class="cell-subtle">Del: {{ $deliveryAssignee }}</span>
                                            <span class="cell-subtle">Pick: {{ $pickupAssignee }}</span>
                                            @if($isHotlisted)
                                                <span class="hotlist-note">Overdue more than 5 days with unpaid dues. Prioritize pickup.</span>
                                            @endif
                                        </div>
                                    </td>
                                    @if($canSeeRentalFinance)
                                    <td class="balance-col" data-label="Balance">
                                        <div class="cell-stack compact">
                                            <strong style="{{ $outstandingBalance > 0 ? 'color:#b91c1c;' : '' }}">{{ $currency($balanceDisplay) }}</strong>
                                            <span class="badge rn-badge {{ $paymentBadge['class'] }}" style="{{ $paymentBadge['tone'] }}">
                                                {{ $paymentBadge['label'] }}
                                            </span>
                                        </div>
                                    </td>
                                    @endif
                                    <td class="actions-cell" data-label="Actions">
                                        <div class="row-actions">
                                            <div class="row-actions-primary">
                                                <a href="{{ route('rentals.show', $rental) }}" class="ops-btn-light mobile-utility-btn" title="View rental" aria-label="View rental">
                                                    <svg class="action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.06 12.35a1 1 0 0 1 0-.7C3.49 7.75 7.23 5 12 5s8.51 2.75 9.94 6.65a1 1 0 0 1 0 .7C20.51 16.25 16.77 19 12 19s-8.51-2.75-9.94-6.65Z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <span>View</span>
                                                </a>
                                                @if($customerPhoneHref)
                                                    <a href="{{ $customerPhoneHref }}" class="ops-btn-light mobile-utility-btn" title="Call customer" aria-label="Call customer">
                                                        <svg class="action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.89.33 1.77.63 2.62a2 2 0 0 1-.45 2.11L8 9.91a16 16 0 0 0 6.09 6.09l1.46-1.29a2 2 0 0 1 2.11-.45c.85.3 1.73.51 2.62.63A2 2 0 0 1 22 16.92z"/></svg>
                                                        <span>Call</span>
                                                    </a>
                                                @endif
                                                @if(WhatsAppHelper::resolveCustomerNumber($rental->customer))
                                                    <a href="{{ $whatsAppUrl }}" target="_blank" class="ops-btn-wa mobile-utility-btn" title="WhatsApp {{ $rental->customer_name }}" aria-label="WhatsApp {{ $rental->customer_name }}">
                                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                                        <span>WhatsApp</span>
                                                    </a>
                                                @endif
                                                @if($canUpdateRentals && $rental->canRenew())
                                                    <a href="{{ route('rentals.show', $rental) }}#renewal-workspace" class="ops-btn-light">
                                                        <svg class="action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3.16-6.84"/><path d="M21 3v6h-6"/></svg>
                                                        <span>Extend</span>
                                                    </a>
                                                @endif
                                                @if($canUpdateDeliveries && $pickupStatusValue === 'pending' && $rental->pickupRecord)
                                                    <form method="POST" action="{{ route('deliveries.in_progress', $rental->pickupRecord) }}" style="margin:0;">
                                                        @csrf
                                                        @method('PUT')
                                                        <button type="submit" class="ops-btn-light">
                                                            <svg class="action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h13l5 5v5a2 2 0 0 1-2 2h-1"/><path d="M8 19H6a2 2 0 0 1-2-2V7"/><circle cx="8" cy="19" r="2"/><circle cx="17" cy="19" r="2"/></svg>
                                                            <span>Pickup</span>
                                                        </button>
                                                    </form>
                                                @elseif($canUpdateDeliveries && $pickupStatusValue === 'in_progress' && $rental->pickupRecord)
                                                    <form method="POST" action="{{ route('deliveries.complete', $rental->pickupRecord) }}" style="margin:0;">
                                                        @csrf
                                                        @method('PUT')
                                                        <button type="submit" class="ops-btn-success">
                                                            <svg class="action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20 6-11 11-5-5"/></svg>
                                                            <span>Pickup</span>
                                                        </button>
                                                    </form>
                                                @elseif($isHotlisted && $canCreateDeliveries && !$hasOpenPickupTask && $deliveryStatusValue === 'completed')
                                                    <a href="{{ $pickupAssignUrl }}" class="ops-btn-danger">
                                                        <svg class="action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h13l5 5v5a2 2 0 0 1-2 2h-1"/><path d="M8 19H6a2 2 0 0 1-2-2V7"/><circle cx="8" cy="19" r="2"/><circle cx="17" cy="19" r="2"/></svg>
                                                        <span>Pickup</span>
                                                    </a>
                                                @endif
                                            </div>

                                            <div class="row-actions-secondary">
                                                <details class="ops-action-menu">
                                                    <summary aria-label="Rental actions">...</summary>
                                                    <div class="ops-action-panel row-actions">

                                            @if($canUpdateRentals && !in_array($rental->status, ['returned', 'cancelled'], true))
                                                <a href="{{ route('rentals.edit', $rental) }}" class="ops-btn-light">Edit</a>
                                            @endif

                                            @if($canUpdateRentals && !in_array($rental->status, ['returned', 'cancelled'], true))
                                                <form method="POST" action="{{ route('rentals.cancel', $rental) }}" style="margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="ops-btn-danger" onclick="return confirm('Cancel this rental? This keeps the record for audit history.');">Cancel</button>
                                                </form>
                                            @endif

                                            @if($canDeleteRentals)
                                                <form method="POST" action="{{ route('rentals.destroy', $rental) }}" style="margin:0;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="ops-btn-danger" onclick="return confirm('Delete this rental order permanently? This will be blocked if invoices, payments, deliveries, renewals, or linked sales exist.');">Delete</button>
                                                </form>
                                            @endif

                                            @if($canCreateDeliveries && $rental->status !== 'cancelled' && !$hasOpenDeliveryTask)
                                                <a href="{{ route('deliveries.create', ['rental_id' => $rental->id]) }}" class="ops-btn-light">Assign</a>
                                            @endif

                                            @if($canUpdateRentals && $rental->canRenew())
                                                <form method="POST" action="{{ route('rentals.quick-renew', $rental) }}" style="margin:0;" data-quick-renew-form data-renewal-days="{{ $rental->suggestedRenewalDays() }}">
                                                    @csrf
                                                    <button type="submit" class="ops-btn-light">Quick Renew</button>
                                                </form>
                                            @endif

                                            @if(WhatsAppHelper::resolveCustomerNumber($rental->customer))
                                                <a href="{{ $renewalWhatsAppUrl }}" target="_blank" class="ops-btn-wa">Renewal WA</a>
                                            @endif

                                            @if($isHotlisted && $canCreateDeliveries && !$hasOpenPickupTask && $deliveryStatusValue === 'completed')
                                                <a href="{{ $pickupAssignUrl }}" class="ops-btn-danger">Initiate Pickup</a>
                                            @endif

                                            @if($canUpdateDeliveries && $deliveryStatusValue === 'pending' && $rental->deliveryRecord)
                                                <form method="POST" action="{{ route('deliveries.in_progress', $rental->deliveryRecord) }}" style="margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="ops-btn-light">Start D</button>
                                                </form>
                                            @elseif($canUpdateDeliveries && $deliveryStatusValue === 'in_progress' && $rental->deliveryRecord)
                                                <form method="POST" action="{{ route('deliveries.complete', $rental->deliveryRecord) }}" style="margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="ops-btn-success">Complete D</button>
                                                </form>
                                            @endif

                                            @if($canUpdateDeliveries && $pickupStatusValue === 'pending' && $rental->pickupRecord)
                                                <form method="POST" action="{{ route('deliveries.in_progress', $rental->pickupRecord) }}" style="margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="ops-btn-light">Start P</button>
                                                </form>
                                            @elseif($canUpdateDeliveries && $pickupStatusValue === 'in_progress' && $rental->pickupRecord)
                                                <form method="POST" action="{{ route('deliveries.complete', $rental->pickupRecord) }}" style="margin:0;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="ops-btn-success">Complete P</button>
                                                </form>
                                            @endif
                                                    </div>
                                                </details>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:12px;">
                    {{ $rentals->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@if($canCreateRentals)
    @include('partials.mobile-fab', ['href' => route('rentals.create'), 'label' => 'Add Rental'])
@endif
@endsection

@push('scripts')
<script>
    (() => {
        document.querySelectorAll('[data-quick-renew-form]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const days = form.dataset.renewalDays || '';
                const message = days
                    ? `This rental will be extended by ${days} day${Number(days) === 1 ? '' : 's'}. Continue?`
                    : 'This rental will be renewed immediately. Continue?';

                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        const bulkForm = document.getElementById('rentalInvoiceBulkForm');
        const selectAll = document.getElementById('selectAllRentalRows');
        const rowChecks = Array.from(document.querySelectorAll('.rental-row-check'));
        const invoiceChecks = rowChecks.filter((checkbox) => checkbox.classList.contains('rental-invoice-check'));
        const countEl = document.getElementById('rentalInvoiceSelectedCount');

        const updateSelectedCount = () => {
            const selected = rowChecks.filter((checkbox) => checkbox.checked).length;
            const invoiceEligible = invoiceChecks.filter((checkbox) => checkbox.checked && checkbox.dataset.invoiceId).length;

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

        document.querySelectorAll('[data-rental-bulk-action]').forEach((button) => {
            button.addEventListener('click', (event) => {
                const selectedInvoiceIds = invoiceChecks
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

                bulkForm.action = button.dataset.rentalBulkAction;
                bulkForm.method = button.dataset.rentalBulkMethod || 'GET';
                bulkForm.target = button.dataset.rentalBulkTarget || '_self';

                if (button.dataset.rentalBulkTask) {
                    const confirmMessage = button.dataset.rentalConfirm || 'Apply this bulk action to selected invoices?';

                    if (!window.confirm(confirmMessage)) {
                        event.preventDefault();
                        return;
                    }

                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'bulk_action';
                    actionInput.value = button.dataset.rentalBulkTask;
                    bulkForm.appendChild(actionInput);
                }
            });
        });

        updateSelectedCount();
    })();
</script>
@endpush
