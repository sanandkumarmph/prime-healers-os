@extends('layouts.app')

@section('content')
@php
    $currentUser = auth()->user();
    $canCreateInvoices = $currentUser?->canAccessModule('invoices', 'create') ?? false;
    $canUpdateInvoices = $currentUser?->canAccessModule('invoices', 'update') ?? false;
    $canDeleteInvoices = $currentUser?->canAccessModule('invoices', 'delete') ?? false;
    $canCreatePayments = $currentUser?->canAccessModule('payments', 'create') ?? false;
    $canViewFinance = $currentUser?->canViewFinance() ?? false;
    $totalInvoices = (int) ($invoiceStats['totalInvoices'] ?? $invoices->total());
    $paidInvoices = (int) ($invoiceStats['paidInvoices'] ?? 0);
    $openInvoices = (int) ($invoiceStats['openInvoices'] ?? 0);
    $overdueInvoices = (int) ($invoiceStats['overdueInvoices'] ?? 0);
    $outstandingAmount = (float) ($invoiceStats['outstandingAmount'] ?? 0);
    $totalBilled = (float) ($invoiceStats['totalBilled'] ?? 0);
    $collectedThisMonth = (float) ($invoiceStats['collectedThisMonth'] ?? 0);
    $collectedToday = (float) ($invoiceStats['collectedToday'] ?? 0);
    $overdueAmount = (float) ($invoiceStats['overdueAmount'] ?? 0);
    $largestInvoice = (float) ($invoiceStats['largestInvoice'] ?? ($invoices->max('total_amount') ?? 0));
    $averageInvoiceValue = $totalInvoices > 0 ? ($totalBilled / max(1, $totalInvoices)) : 0;
    $currency = fn ($value, $decimals = 0) => '&#8377;' . number_format((float) $value, $decimals);
    $invoiceIcon = function (string $name): string {
        return match ($name) {
            'filter' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18"/><path d="M7 12h10"/><path d="M10 19h4"/></svg>',
            'download' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
            'plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
            'eye' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'card' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/></svg>',
            'file' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg>',
            'more' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>',
            'rupee' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13h5a5 5 0 0 0 0-10"/><path d="m6 13 8 8"/></svg>',
            'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
            'alert' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/></svg>',
        };
    };
    $invoiceUrl = function (array $overrides = []) use ($search, $status, $customerId, $city, $fromDate, $toDate, $perPage) {
        return route('invoices.index', array_filter(array_merge([
            'search' => $search ?: null,
            'status' => $status ?: null,
            'customer_id' => $customerId ?: null,
            'city' => $city ?: null,
            'from_date' => $fromDate ?: null,
            'to_date' => $toDate ?: null,
            'per_page' => $perPage ?: null,
        ], $overrides), fn ($value) => $value !== null && $value !== ''));
    };
    $hasActiveFilters = filled($search) || filled($status) || filled($customerId) || filled($city) || filled($fromDate) || filled($toDate);
    $activeFilterChips = collect([
        filled($search) ? 'Search: ' . $search : null,
        filled($status) ? 'Status: ' . ucfirst(str_replace('_', ' ', $status)) : null,
        filled($customerId) ? 'Customer selected' : null,
        filled($city) ? 'City: ' . $city : null,
        filled($fromDate) ? 'From: ' . $fromDate : null,
        filled($toDate) ? 'To: ' . $toDate : null,
    ])->filter()->values();
    $comparisonLabel = function (string $key) use ($invoiceStats): string {
        $value = $invoiceStats[$key] ?? null;
        if (is_numeric($value)) {
            $prefix = (float) $value >= 0 ? 'Up ' : 'Down ';
            return $prefix . number_format(abs((float) $value), 0) . '% vs last month';
        }

        return 'No prior comparison';
    };
    $agingBuckets = collect([
        '0-30 Days' => ['amount' => 0.0, 'tone' => 'green'],
        '31-60 Days' => ['amount' => 0.0, 'tone' => 'amber'],
        '61-90 Days' => ['amount' => 0.0, 'tone' => 'orange'],
        '90+ Days' => ['amount' => 0.0, 'tone' => 'red'],
    ]);
    foreach ($invoices->getCollection() as $agingInvoice) {
        $balance = (float) ($agingInvoice->balance_amount ?? 0);
        if ($balance <= 0) {
            continue;
        }

        $days = optional($agingInvoice->due_date)->isPast()
            ? max(0, optional($agingInvoice->due_date)->diffInDays(now()))
            : 0;
        $bucket = $days <= 30 ? '0-30 Days' : ($days <= 60 ? '31-60 Days' : ($days <= 90 ? '61-90 Days' : '90+ Days'));
        $currentBucket = $agingBuckets->get($bucket);
        $currentBucket['amount'] += $balance;
        $agingBuckets->put($bucket, $currentBucket);
    }
    $agingTotal = max(1, (float) $agingBuckets->sum('amount'));
@endphp

<div class="invoice-ledger rn-list-page">
    <style>
        .invoice-ledger {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            display: grid;
            gap: 12px;
            min-width: 0;
            overflow: visible;
            box-sizing: border-box;
        }

        .invoice-toolbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            border: 1px solid var(--ph-color-border);
            border-radius: 18px;
            background: #ffffff;
            box-shadow: var(--ph-shadow-soft);
            overflow: visible;
            min-width: 0;
        }

        .invoice-toolbar > div:first-child {
            min-width: 0;
        }

        .invoice-toolbar h1 {
            margin: 0 0 4px;
            color: var(--ph-color-text);
            font-size: 26px;
            letter-spacing: -0.03em;
            line-height: 1.05;
            font-family: var(--ph-font-heading);
        }

        .invoice-toolbar p {
            margin: 0;
            color: var(--ph-color-text-soft);
            font-size: 13px;
            line-height: 1.45;
            max-width: 100%;
        }

        .invoice-toolbar-actions,
        .invoice-bulk-actions,
        .invoice-filter-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .invoice-toolbar-actions {
            justify-content: flex-end;
            flex: 0 1 auto;
            max-width: 100%;
        }

        .invoice-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--ph-color-border-strong);
            background: #ffffff;
            color: var(--ph-color-text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
            max-width: 100%;
        }

        .invoice-btn.primary {
            border-color: var(--ph-color-primary);
            background: var(--ph-color-primary);
            color: #ffffff;
        }

        .invoice-btn.soft {
            border-color: rgba(23,119,189,.18);
            background: var(--ph-color-info-soft);
            color: var(--ph-color-primary);
        }

        .invoice-btn.danger {
            border-color: rgba(179,13,35,.18);
            background: var(--ph-color-danger-soft);
            color: var(--ph-color-danger);
        }

        .invoice-summary-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 8px;
            min-width: 0;
        }

        .invoice-summary-tile {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            min-width: 0;
            padding: 9px 11px;
            border: 1px solid var(--ph-color-border);
            border-radius: 14px;
            background: #ffffff;
            color: var(--ph-color-text);
            text-decoration: none;
        }

        .invoice-summary-tile span {
            display: block;
            color: var(--ph-color-text-soft);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-family: var(--ph-font-heading);
        }

        .invoice-summary-tile strong {
            display: block;
            margin-top: 2px;
            font-size: 16px;
            line-height: 1;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .invoice-filter-card {
            padding: 12px;
            border: 1px solid var(--ph-color-border);
            border-radius: 16px;
            background: #ffffff;
        }
        .invoice-search-shell {
            display:grid;
            gap:10px;
            padding:12px;
            border:1px solid var(--ph-color-border);
            border-radius:16px;
            background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
            box-shadow:var(--ph-shadow-soft);
        }
        .invoice-search-form {
            display:grid;
            grid-template-columns:minmax(0, 1fr) auto auto;
            gap:8px;
            align-items:end;
        }
        .invoice-chip-row { display:flex; gap:8px; flex-wrap:wrap; }
        .invoice-chip {
            display:inline-flex; align-items:center; min-height:30px; padding:6px 10px;
            border-radius:999px; border:1px solid var(--ph-color-border); background:#fff; color:var(--ph-color-text); font-size:12px; font-weight:700;
        }

        .invoice-filter-toggle { padding: 0; }
        .invoice-filter-toggle summary {
            list-style: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px;
            padding: 12px; color: var(--ph-color-text); font-size: 15px; font-weight: 900; font-family: var(--ph-font-heading);
        }
        .invoice-filter-toggle summary::-webkit-details-marker { display: none; }
        .invoice-filter-toggle summary span { color: var(--ph-color-text-soft); font-size: 12px; font-weight: 700; }
        .invoice-filter-toggle form { padding: 0 12px 12px; }

        .invoice-filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 8px;
            align-items: end;
        }

        .invoice-field {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .invoice-field label {
            color: var(--ph-color-text-soft);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .invoice-input,
        .invoice-select {
            width: 100%;
            min-height: 36px;
            padding: 8px 10px;
            border: 1px solid var(--ph-color-border-strong);
            border-radius: 10px;
            background: #ffffff;
            color: var(--ph-color-text);
            font-size: 13px;
            box-sizing: border-box;
        }

        .invoice-list-shell {
            overflow: visible;
            border: 1px solid var(--ph-color-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: var(--ph-shadow-soft);
        }

        .invoice-list-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--ph-color-border);
            flex-wrap: wrap;
            overflow: visible;
            min-width: 0;
        }

        .invoice-selected-count {
            color: var(--ph-color-text-soft);
            font-size: 12px;
            font-weight: 800;
        }

        .invoice-table-wrap {
            width: 100%;
            overflow-x: hidden;
            overflow-y: visible;
            padding-bottom: 170px;
            margin-bottom: -170px;
            scrollbar-color: #94a3b8 var(--ph-color-border);
            scrollbar-width: thin;
        }

        .invoice-table-wrap::-webkit-scrollbar {
            height: 12px;
        }

        .invoice-table-wrap::-webkit-scrollbar-track {
            background: var(--ph-color-border);
            border-radius: 999px;
        }

        .invoice-table-wrap::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 999px;
        }

        .invoice-table {
            width: 100%;
            min-width: 860px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .invoice-table th {
            padding: 8px 6px;
            background: var(--ph-color-surface-soft);
            color: var(--ph-color-text-soft);
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-align: left;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .invoice-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #eef2f7;
            color: #0f172a;
            font-size: 13px;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .invoice-table th:nth-child(1),
        .invoice-table td:nth-child(1) { width: 34px; }
        .invoice-table th:nth-child(2),
        .invoice-table td:nth-child(2) { width: 116px; }
        .invoice-table th:nth-child(3),
        .invoice-table td:nth-child(3) { width: 172px; }
        .invoice-table th:nth-child(4),
        .invoice-table td:nth-child(4) { width: 86px; }
        .invoice-table th:nth-child(5),
        .invoice-table td:nth-child(5) { width: 88px; }
        .invoice-table th:nth-child(6),
        .invoice-table td:nth-child(6) { width: 108px; }
        .invoice-table th:nth-child(7),
        .invoice-table td:nth-child(7) { width: 96px; }
        .invoice-table th:nth-child(8),
        .invoice-table td:nth-child(8) {
            width: 52px;
            overflow: visible;
            position: sticky;
            right: 120px;
            z-index: 6;
            background: #ffffff;
            box-shadow: -8px 0 14px rgba(15, 23, 42, 0.06);
        }

        .invoice-table th:nth-child(8) {
            z-index: 3;
            background: #f8fafc;
        }
        .invoice-table th:nth-child(9),
        .invoice-table td:nth-child(9) {
            width: 120px;
            position: sticky;
            right: 0;
            z-index: 5;
            background: #ffffff;
        }
        .invoice-table th:nth-child(9) {
            z-index: 3;
            background: #f8fafc;
        }
        .invoice-table tr:has(.invoice-action-menu[open]),
        .invoice-table tr.is-action-open {
            position: relative;
            z-index: 60;
        }
        .invoice-table tr:has(.invoice-action-menu[open]) td,
        .invoice-table tr.is-action-open td {
            overflow: visible;
        }
        .invoice-table tr:has(.invoice-action-menu[open]) td:nth-child(8),
        .invoice-table tr.is-action-open td:nth-child(8) {
            z-index: 1000;
        }

        .invoice-table td:nth-child(3),
        .invoice-table td:nth-child(9) {
            white-space: normal;
        }

        .invoice-table tr:hover td {
            background: #f8fbff;
        }

        .invoice-check {
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
        }

        .invoice-number-link {
            color: #0f172a;
            font-weight: 900;
            text-decoration: none;
        }

        .invoice-muted {
            color: #64748b;
            font-size: 11px;
        }

        .invoice-money {
            font-weight: 900;
            text-align: right;
        }

        .invoice-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .status-paid { background: #dcfce7; color: #166534; }
        .status-partial { background: #ffedd5; color: #9a3412; }
        .status-overdue,
        .status-unpaid,
        .status-draft { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f1f5f9; color: #64748b; }

        .invoice-action-menu {
            position: relative;
            display: inline-block;
            z-index: 20;
        }

        .invoice-action-menu summary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
            font-size: 18px;
            font-weight: 900;
            list-style: none;
        }

        .invoice-action-menu summary::-webkit-details-marker {
            display: none;
        }

        .invoice-action-menu[open] summary {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }

        .invoice-action-panel {
            position: absolute;
            right: 38px;
            top: 0;
            z-index: 999;
            display: grid;
            min-width: 180px;
            padding: 6px;
            border: 1px solid #dbe3ef;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 20px 46px rgba(15, 23, 42, 0.18);
        }

        .invoice-action-panel a,
        .invoice-action-panel button {
            display: flex;
            align-items: center;
            width: 100%;
            min-height: 34px;
            padding: 8px 10px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #334155;
            text-align: left;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .invoice-action-panel a:hover,
        .invoice-action-panel button:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .invoice-empty {
            padding: 34px 20px;
            color: #64748b;
            text-align: center;
        }

        .invoice-alert {
            padding: 11px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
        }

        .invoice-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .invoice-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .mobile-list-command,
        .invoice-mobile-list {
            display: none;
        }

        @media (max-width: 980px) {
            .invoice-toolbar {
                grid-template-columns: 1fr;
            }

            .invoice-toolbar-actions {
                justify-content: flex-start;
            }

            .invoice-summary-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .invoice-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .invoice-ledger {
                gap: 8px;
            }

            .invoice-toolbar {
                padding: 10px 12px;
                border-radius: 14px;
            }

            .invoice-toolbar h1 {
                font-size: 21px;
            }

            .invoice-toolbar p,
            .invoice-summary-strip,
            .invoice-search-shell,
            .invoice-filter-toggle {
                display: none;
            }

            .invoice-toolbar-actions {
                justify-content: flex-end;
                width: 100%;
            }

            .mobile-list-command {
                display: grid;
                gap: 8px;
                padding: 10px;
                border: 1px solid var(--ph-color-border);
                border-radius: 16px;
                background: #ffffff;
                box-shadow: var(--ph-shadow-soft);
            }

            .mobile-command-search {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 8px;
            }

            .mobile-command-search .invoice-input {
                min-height: 40px;
                border-radius: 12px;
                font-size: 16px;
            }

            .mobile-stat-strip {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            .mobile-stat-strip a {
                display: grid;
                gap: 2px;
                min-width: 0;
                padding: 8px 9px;
                border: 1px solid var(--ph-color-border);
                border-radius: 12px;
                background: var(--ph-color-surface-soft);
                color: var(--ph-color-text);
                text-decoration: none;
            }

            .mobile-stat-strip span {
                font-size: 9px;
                color: var(--ph-color-text-soft);
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .05em;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .mobile-stat-strip strong {
                font-size: clamp(12px, 4vw, 15px);
                line-height: 1.15;
                min-width: 0;
                overflow-wrap: anywhere;
                word-break: break-word;
            }

            .invoice-mobile-chip-row {
                display: flex;
                gap: 8px;
                overflow-x: auto;
                padding: 2px 1px 4px;
                scrollbar-width: none;
            }

            .invoice-mobile-chip-row::-webkit-scrollbar { display:none; }

            .invoice-mobile-chip {
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

            .invoice-mobile-chip.is-active {
                background: var(--ph-color-sidebar);
                color: #fff;
                border-color: var(--ph-color-sidebar);
            }

            .invoice-filter-card {
                padding: 8px;
                border-radius: 14px;
            }
            .invoice-search-form {
                grid-template-columns: 1fr;
            }

            .invoice-filter-grid {
                grid-template-columns: 1fr;
            }

            .invoice-filter-card .invoice-field:not(:first-child) {
                display: none;
            }

            .invoice-filter-actions {
                margin-top: 8px;
            }

            .invoice-filter-actions .invoice-btn:not(.primary) {
                display: none;
            }

            .invoice-list-top {
                align-items: flex-start;
                flex-direction: column;
            }

            .invoice-bulk-actions {
                width: 100%;
            }

            .invoice-bulk-actions .invoice-btn {
                flex: 1;
            }

            .invoice-table-wrap { display: none; }
            .invoice-mobile-list { display: grid; gap: 10px; }
            .invoice-mobile-card {
                display: grid;
                gap: 10px;
                padding: 12px;
                border: 1px solid var(--ph-color-border);
                border-radius: 16px;
                background: #fff;
                box-shadow: var(--ph-shadow-soft);
            }
            .invoice-mobile-top {
                width: 100%;
                display: flex;
                align-items: flex-start;
                gap: 10px;
            }
            .invoice-mobile-check {
                width: 16px;
                height: 16px;
                margin-top: 4px;
                accent-color: #2563eb;
                flex: 0 0 auto;
            }
            .invoice-mobile-main {
                min-width: 0;
                display: grid;
                gap: 6px;
                flex: 1 1 auto;
            }
            .invoice-mobile-heading {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 10px;
            }
            .invoice-mobile-number {
                color: #0f172a;
                font-size: 15px;
                font-weight: 900;
                text-decoration: none;
            }
            .invoice-mobile-customer {
                color: #64748b;
                font-size: 12px;
                line-height: 1.35;
            }
            .invoice-mobile-metrics {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .invoice-mobile-metric {
                display: grid;
                gap: 3px;
                padding: 8px 10px;
                border: 1px solid var(--ph-color-border);
                border-radius: 12px;
                background: var(--ph-color-surface-soft);
            }
            .invoice-mobile-metric span {
                color: var(--ph-color-text-soft);
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .06em;
            }
            .invoice-mobile-metric strong {
                color: var(--ph-color-text);
                font-size: 14px;
                line-height: 1.2;
            }
            .invoice-mobile-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                color: #64748b;
                font-size: 11px;
            }
            .invoice-mobile-actions {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                align-items: start;
            }
            .invoice-mobile-actions .invoice-btn {
                min-height: 38px;
                border-radius: 12px;
            }

            .invoice-table th:nth-child(8),
            .invoice-table td:nth-child(8),
            .invoice-table th:nth-child(9),
            .invoice-table td:nth-child(9) {
                position: static;
                right: auto;
                box-shadow: none;
            }

            .invoice-action-menu {
                width: 100%;
                display: flex;
                justify-content: flex-start;
            }

            .invoice-action-panel {
                right: 0;
                top: calc(100% + 6px);
            }
        }

        /* Compact invoice command center overrides */
        .invoice-breadcrumb{display:flex;gap:7px;align-items:center;color:#64748b;font-size:12px;font-weight:800;margin-bottom:6px}
        .invoice-icon-btn{gap:7px}.invoice-icon-btn svg,.invoice-tile-icon svg,.invoice-row-actions svg,.invoice-side-action svg,.invoice-action-icon svg{width:16px;height:16px;display:block}
        .invoice-summary-strip{grid-template-columns:repeat(6,minmax(126px,1fr));gap:10px}
        .invoice-summary-tile{display:grid;grid-template-columns:36px minmax(0,1fr);grid-template-rows:auto auto auto;justify-content:flex-start;align-items:center;padding:10px 12px;min-height:76px;box-shadow:var(--ph-shadow-soft)}
        .invoice-summary-tile .invoice-tile-icon{grid-row:1/4;width:34px;height:34px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eef2ff;color:#4f46e5}
        .invoice-summary-tile strong{font-size:18px}.invoice-summary-tile small{font-size:11px;color:#64748b;font-weight:700}.invoice-summary-tile>span:not(.invoice-tile-icon){font-size:10px}
        .invoice-summary-tile.tone-green .invoice-tile-icon{background:#dcfce7;color:#16a34a}.invoice-summary-tile.tone-blue .invoice-tile-icon{background:#dbeafe;color:#2563eb}.invoice-summary-tile.tone-red .invoice-tile-icon{background:#fee2e2;color:#dc2626}.invoice-summary-tile.tone-amber .invoice-tile-icon{background:#ffedd5;color:#d97706}.invoice-summary-tile.tone-purple .invoice-tile-icon{background:#ede9fe;color:#7c3aed}
        .invoice-chip{color:inherit;text-decoration:none}.invoice-chip.active{background:#4f46e5;color:#fff;border-color:#4f46e5}
        .invoice-workspace-grid{display:grid;grid-template-columns:minmax(0,1fr) 292px;gap:12px;align-items:start;min-width:0}.invoice-workspace-grid>*{min-width:0}
        .invoice-finance-panel{display:grid;gap:12px;position:sticky;top:88px}.invoice-side-card{padding:12px;border:1px solid var(--ph-color-border);border-radius:16px;background:#fff;box-shadow:var(--ph-shadow-soft)}
        .invoice-side-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px}.invoice-side-head strong{font-size:15px;color:#0f172a}.invoice-side-head span{font-size:11px;color:#16a34a;background:#dcfce7;border-radius:999px;padding:4px 8px;font-weight:900}
        .invoice-side-list{display:grid}.invoice-side-list div{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #edf2f7;font-size:13px}.invoice-side-list span{color:#64748b}.invoice-side-list strong{color:#0f172a}.invoice-side-list .danger{color:#dc2626}.invoice-side-list .success{color:#16a34a}
        .invoice-side-actions{display:grid;gap:8px}.invoice-side-action{min-height:38px;border:1px solid var(--ph-color-border);border-radius:12px;background:#fff;color:#334155;text-decoration:none;display:flex;align-items:center;gap:8px;padding:8px 10px;font-size:13px;font-weight:800;cursor:pointer}
        .invoice-table-wrap{overflow-x:auto;padding-bottom:18px;margin-bottom:0}.invoice-table{min-width:980px;table-layout:auto}.invoice-table td{padding:7px 8px;font-size:12px}.invoice-table th{padding:7px 8px;font-size:10px}.invoice-table td:nth-child(8){white-space:normal}.invoice-table td:nth-child(9){overflow:visible}
        .invoice-table th:nth-child(8),.invoice-table td:nth-child(8),.invoice-table th:nth-child(9),.invoice-table td:nth-child(9){position:static;right:auto;box-shadow:none;background:inherit;width:auto}.invoice-table td:nth-child(9){background:#fff}
        .invoice-gst-compact strong{font-size:12px}.invoice-gst-compact .invoice-muted{line-height:1.35}.invoice-row-actions{display:flex;gap:5px;align-items:center;justify-content:flex-end;overflow:visible}
        .invoice-action-icon{width:32px;height:32px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;padding:0;cursor:pointer;flex:0 0 auto}.invoice-action-icon.pay{color:#16a34a;background:#f0fdf4}.invoice-action-icon.pdf{color:#2563eb;background:#eff6ff}
        .invoice-action-menu summary{width:32px;height:32px;border-radius:10px;font-size:0}.invoice-action-menu summary svg{width:16px;height:16px}
        @media(max-width:1180px){.invoice-summary-strip{grid-template-columns:repeat(3,minmax(0,1fr))}.invoice-workspace-grid{grid-template-columns:1fr}.invoice-finance-panel{position:static;grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:640px){.invoice-finance-panel{display:none}.invoice-icon-btn span{display:none}.invoice-toolbar-actions{flex-wrap:nowrap}.invoice-btn{min-height:38px}.invoice-chip-row{overflow-x:auto;flex-wrap:nowrap}.invoice-mobile-actions{display:flex;gap:8px;align-items:center}.invoice-mobile-actions .invoice-action-menu{width:auto}.invoice-mobile-actions .invoice-action-panel{right:0;top:calc(100% + 6px)}.invoice-mobile-meta span:nth-child(n+3){display:none}}

        /* Invoice aging and compact rail refinements */
        .invoice-summary-tile small{display:flex;align-items:center;gap:4px;color:#64748b;font-size:10px;line-height:1.15;white-space:normal}.invoice-summary-tile.tone-red small{color:#dc2626}
        .invoice-finance-panel{gap:10px;top:74px}.invoice-side-card{padding:10px;border-radius:14px}.invoice-side-list div{padding:6px 0;font-size:12px}.invoice-side-head{margin-bottom:6px}.invoice-side-actions{gap:7px}.invoice-side-action{min-height:36px;padding:8px 10px;font-size:12px;border-radius:10px}
        .invoice-aging-body{display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;align-items:center}.invoice-aging-donut{width:86px;height:86px;border-radius:999px;background:conic-gradient(#16a34a 0 var(--p1),#f59e0b var(--p1) var(--p2),#fb7185 var(--p2) var(--p3),#ef4444 var(--p3) 100%);display:grid;place-items:center;position:relative;text-align:center}.invoice-aging-donut:before{content:'';position:absolute;inset:11px;border-radius:999px;background:#fff}.invoice-aging-donut strong,.invoice-aging-donut span{position:relative;z-index:1}.invoice-aging-donut strong{font-size:13px;line-height:1.05}.invoice-aging-donut span{font-size:10px;color:#64748b;font-weight:800}.invoice-aging-list{display:grid;gap:6px}.invoice-aging-row{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:11px}.invoice-aging-row span{display:inline-flex;align-items:center;gap:6px;color:#475569}.invoice-aging-row i{width:8px;height:8px;border-radius:999px;background:#16a34a}.invoice-aging-row.tone-amber i{background:#f59e0b}.invoice-aging-row.tone-orange i{background:#fb7185}.invoice-aging-row.tone-red i{background:#ef4444}.invoice-aging-row strong{font-size:11px;color:#0f172a;text-align:right}.invoice-aging-row em{font-style:normal;color:#64748b;font-weight:700}
        @media(max-width:1180px){.invoice-aging-body{grid-template-columns:80px minmax(0,1fr)}.invoice-aging-donut{width:76px;height:76px}.invoice-finance-panel{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:820px){.invoice-finance-panel{grid-template-columns:1fr 1fr}.invoice-aging-card{grid-column:1/-1}}</style>

    <div class="invoice-toolbar">
        <div>
            <div class="invoice-breadcrumb">Home <span>/</span> Finance <span>/</span> Invoices</div>
            <h1>Invoices</h1>
            <p>Manage invoices, payments, and outstanding balances.</p>
        </div>
        <div class="invoice-toolbar-actions">
            <button type="button" class="invoice-btn invoice-icon-btn" data-invoice-filter-trigger title="Filters" aria-label="Open invoice filters">{!! $invoiceIcon('filter') !!}<span>Filters</span></button>
            <a href="{{ route('invoices.export.csv', request()->query()) }}" class="invoice-btn soft invoice-icon-btn">{!! $invoiceIcon('download') !!}<span>Export CSV</span></a>
            @if($canCreateInvoices)
                <a href="{{ route('invoices.create') }}" class="invoice-btn primary invoice-icon-btn">{!! $invoiceIcon('plus') !!}<span>Create Invoice</span></a>
            @endif
        </div>
    </div>

    <div id="invoices-mobile-filters" class="mobile-filter-sheet" data-mobile-filter-sheet hidden>
        <div class="mobile-filter-sheet-panel">
            <div class="mobile-filter-sheet-header">
                <div>
                    <h3>Invoice Filters</h3>
                    <p>Keep status, dates, and rows close on mobile.</p>
                </div>
                <button type="button" class="mobile-filter-sheet-close" data-mobile-sheet-close="invoices-mobile-filters" aria-label="Close invoice filters">&times;</button>
            </div>
            <div class="mobile-filter-sheet-body">
                <form method="GET" action="{{ route('invoices.index') }}" class="mobile-sheet-form">
                    <input type="hidden" name="search" value="{{ $search ?? '' }}">
                    <div class="mobile-sheet-grid">
                        <div class="mobile-sheet-field">
                            <label for="mobile_invoice_status">Status</label>
                            <select id="mobile_invoice_status" class="invoice-select" name="status">
                                <option value="">All statuses</option>
                                <option value="draft" @selected(($status ?? '') === 'draft')>Draft</option>
                                <option value="open" @selected(($status ?? '') === 'open')>Open / Partial / Overdue</option>
                                <option value="unpaid" @selected(($status ?? '') === 'unpaid')>Unpaid</option>
                                <option value="partial" @selected(($status ?? '') === 'partial')>Partial</option>
                                <option value="paid" @selected(($status ?? '') === 'paid')>Paid</option>
                                <option value="overdue" @selected(($status ?? '') === 'overdue')>Overdue</option>
                                <option value="cancelled" @selected(($status ?? '') === 'cancelled')>Cancelled</option>
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_invoice_customer">Customer</label>
                            <select id="mobile_invoice_customer" class="invoice-select" name="customer_id">
                                <option value="">All customers</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) ($customerId ?? '') === (string) $customer->id)>{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_invoice_city">City</label>
                            <select id="mobile_invoice_city" class="invoice-select" name="city">
                                <option value="">All cities</option>
                                @foreach(($cities ?? collect()) as $cityOption)
                                    <option value="{{ $cityOption }}" @selected(($city ?? '') === $cityOption)>{{ $cityOption }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_invoice_from">From</label>
                            <input id="mobile_invoice_from" class="invoice-input" type="date" name="from_date" value="{{ $fromDate ?? '' }}">
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_invoice_to">To</label>
                            <input id="mobile_invoice_to" class="invoice-input" type="date" name="to_date" value="{{ $toDate ?? '' }}">
                        </div>
                        <div class="mobile-sheet-field">
                            <label for="mobile_invoice_per_page">Show</label>
                            <select id="mobile_invoice_per_page" class="invoice-select" name="per_page">
                                @foreach(($perPageOptions ?? [20, 50, 100, 250, 500]) as $option)
                                    <option value="{{ $option }}" @selected((int) ($perPage ?? 20) === (int) $option)>{{ $option }} per page</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mobile-sheet-actions">
                        <button type="submit" class="invoice-btn primary">Apply</button>
                        <a href="{{ route('invoices.index') }}" class="invoice-btn">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="mobile-list-command" aria-label="Mobile invoice controls">
        <div class="mobile-search-tools">
            <form method="GET" action="{{ route('invoices.index') }}" class="mobile-command-search">
                @foreach(request()->except(['search', 'page']) as $key => $value)
                    @if(is_scalar($value) && $value !== '')
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input id="mobile-invoice-search" class="invoice-input" type="search" name="search" value="{{ $search ?? '' }}" placeholder="Search invoice, customer, phone">
            </form>
            <div class="mobile-action-toolbar {{ $hasActiveFilters ? 'has-active-filters' : '' }}" aria-label="Mobile invoice filters">
                <button type="button" class="mobile-toolbar-btn" data-mobile-filter-open="invoices-mobile-filters" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}" aria-label="Open invoice filters">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>
                </button>
            </div>
        </div>
        <div class="mobile-stat-strip" aria-label="Invoice summary">
            <a href="{{ $invoiceUrl(['status' => null]) }}"><span>Total</span><strong>{{ $totalInvoices }}</strong></a>
            <a href="{{ $invoiceUrl(['status' => 'paid']) }}"><span>Paid</span><strong>{{ $paidInvoices }}</strong></a>
            <a href="{{ $invoiceUrl(['status' => 'open']) }}"><span>Open</span><strong>{{ $openInvoices }}</strong></a>
            <a href="{{ $invoiceUrl(['status' => 'overdue']) }}"><span>Overdue</span><strong>{{ $overdueInvoices }}</strong></a>
        </div>
        <div class="invoice-mobile-chip-row" aria-label="Invoice quick filters">
            <a href="{{ $invoiceUrl(['status' => null]) }}" class="invoice-mobile-chip {{ blank($status) ? 'is-active' : '' }}">All</a>
            <a href="{{ $invoiceUrl(['status' => 'open']) }}" class="invoice-mobile-chip {{ ($status ?? '') === 'open' ? 'is-active' : '' }}">Open</a>
            <a href="{{ $invoiceUrl(['status' => 'paid']) }}" class="invoice-mobile-chip {{ ($status ?? '') === 'paid' ? 'is-active' : '' }}">Paid</a>
            <a href="{{ $invoiceUrl(['status' => 'overdue']) }}" class="invoice-mobile-chip {{ ($status ?? '') === 'overdue' ? 'is-active' : '' }}">Overdue</a>
        </div>
    </div>

    <div class="invoice-summary-strip">
        <a href="{{ $invoiceUrl(['status' => null]) }}" class="invoice-summary-tile tone-purple">
            <span class="invoice-tile-icon">{!! $invoiceIcon('file') !!}</span><span>Total Invoices</span>
            <strong>{{ $totalInvoices }}</strong><small>{{ $comparisonLabel('totalInvoicesChangePercent') }}</small>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'paid']) }}" class="invoice-summary-tile tone-green">
            <span class="invoice-tile-icon">{!! $invoiceIcon('check') !!}</span><span>Paid Invoices</span>
            <strong>{{ $paidInvoices }}</strong><small>{{ $comparisonLabel('paidInvoicesChangePercent') }}</small>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'open']) }}" class="invoice-summary-tile tone-blue">
            <span class="invoice-tile-icon">{!! $invoiceIcon('file') !!}</span><span>Open Invoices</span>
            <strong>{{ $openInvoices }}</strong><small>{{ $comparisonLabel('openInvoicesChangePercent') }}</small>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'overdue']) }}" class="invoice-summary-tile tone-red">
            <span class="invoice-tile-icon">{!! $invoiceIcon('alert') !!}</span><span>Overdue</span>
            <strong>{{ $overdueInvoices }}</strong><small>{{ $comparisonLabel('overdueInvoicesChangePercent') }}</small>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'open']) }}" class="invoice-summary-tile tone-amber">
            <span class="invoice-tile-icon">{!! $invoiceIcon('rupee') !!}</span><span>Outstanding</span>
            <strong>@if($canViewFinance){!! $currency($outstandingAmount) !!}@else Restricted @endif</strong><small>{{ $comparisonLabel('outstandingAmountChangePercent') }}</small>
        </a>
        <div class="invoice-summary-tile tone-green">
            <span class="invoice-tile-icon">{!! $invoiceIcon('card') !!}</span><span>Collected Month</span>
            <strong>@if($canViewFinance){!! $currency($collectedThisMonth) !!}@else Restricted @endif</strong><small>{{ $comparisonLabel('collectedThisMonthChangePercent') }}</small>
        </div>
    </div>

    <div class="invoice-search-shell">
        <form method="GET" action="{{ route('invoices.index') }}" class="invoice-search-form">
            <input type="hidden" name="status" value="{{ $status ?? '' }}">
            <input type="hidden" name="customer_id" value="{{ $customerId ?? '' }}">
            <input type="hidden" name="city" value="{{ $city ?? '' }}">
            <input type="hidden" name="from_date" value="{{ $fromDate ?? '' }}">
            <input type="hidden" name="to_date" value="{{ $toDate ?? '' }}">
            <input type="hidden" name="per_page" value="{{ $perPage ?? 20 }}">
            <div class="invoice-field">
                <label for="invoice-search-primary">Search invoices</label>
                <input id="invoice-search-primary" class="invoice-input" type="search" name="search" value="{{ $search ?? '' }}" placeholder="Search invoice, customer, phone, GSTIN, rental, sale, or reference">
            </div>
            <button type="submit" class="invoice-btn primary">Search</button>
            <a href="{{ route('invoices.index') }}" class="invoice-btn" data-filter-clear="invoices-index">Clear Filters</a>
        </form>
        <div class="invoice-chip-row invoice-quick-chip-row" aria-label="Invoice quick filters">
            <a href="{{ $invoiceUrl(['status' => null]) }}" class="invoice-chip {{ blank($status) ? 'active' : '' }}">All</a>
            <a href="{{ $invoiceUrl(['status' => 'open']) }}" class="invoice-chip {{ ($status ?? '') === 'open' ? 'active' : '' }}">Open</a>
            <a href="{{ $invoiceUrl(['status' => 'overdue']) }}" class="invoice-chip {{ ($status ?? '') === 'overdue' ? 'active' : '' }}">Overdue</a>
            <a href="{{ $invoiceUrl(['status' => 'paid']) }}" class="invoice-chip {{ ($status ?? '') === 'paid' ? 'active' : '' }}">Paid</a>
            <a href="{{ $invoiceUrl(['status' => 'partial']) }}" class="invoice-chip {{ ($status ?? '') === 'partial' ? 'active' : '' }}">Partial</a>
            <a href="{{ $invoiceUrl(['from_date' => now()->toDateString(), 'to_date' => now()->toDateString()]) }}" class="invoice-chip">Today</a>
            <a href="{{ $invoiceUrl(['from_date' => now()->startOfMonth()->toDateString(), 'to_date' => now()->endOfMonth()->toDateString()]) }}" class="invoice-chip">This Month</a>
            <a href="{{ $invoiceUrl(['sort' => 'largest']) }}" class="invoice-chip">Largest</a>
        </div>
        @if($hasActiveFilters)
            <div class="invoice-chip-row">
                @foreach($activeFilterChips as $chip)
                    <span class="invoice-chip">{{ $chip }}</span>
                @endforeach
            </div>
        @endif
    </div>

    <details class="invoice-filter-card invoice-filter-toggle" data-filter-panel data-filter-panel-key="invoices-index" data-filter-active="{{ $hasActiveFilters ? 'true' : 'false' }}">
        <summary>Filters <span>{{ $hasActiveFilters ? 'Active - ' . $activeFilterChips->count() : 'Advanced' }}</span></summary>
        <form method="GET" action="{{ route('invoices.index') }}">
            <div class="invoice-filter-grid">
                <div class="invoice-field">
                    <label for="search">Search</label>
                    <input id="search" class="invoice-input" type="search" name="search" value="{{ $search ?? '' }}" placeholder="Invoice, customer, phone, GSTIN, reference">
                </div>
                <div class="invoice-field">
                    <label for="status">Status</label>
                    <select id="status" class="invoice-select" name="status">
                        <option value="">All statuses</option>
                        <option value="draft" @selected(($status ?? '') === 'draft')>Draft</option>
                        <option value="open" @selected(($status ?? '') === 'open')>Open / Partial / Overdue</option>
                        <option value="unpaid" @selected(($status ?? '') === 'unpaid')>Unpaid</option>
                        <option value="partial" @selected(($status ?? '') === 'partial')>Partial</option>
                        <option value="paid" @selected(($status ?? '') === 'paid')>Paid</option>
                        <option value="overdue" @selected(($status ?? '') === 'overdue')>Overdue</option>
                        <option value="cancelled" @selected(($status ?? '') === 'cancelled')>Cancelled</option>
                    </select>
                </div>
                <div class="invoice-field">
                    <label for="customer_id">Customer</label>
                    <select id="customer_id" class="invoice-select" name="customer_id">
                        <option value="">All customers</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((string) ($customerId ?? '') === (string) $customer->id)>{{ $customer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="invoice-field">
                    <label for="city">City</label>
                    <select id="city" class="invoice-select" name="city">
                        <option value="">All cities</option>
                        @foreach(($cities ?? collect()) as $cityOption)
                            <option value="{{ $cityOption }}" @selected(($city ?? '') === $cityOption)>{{ $cityOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="invoice-field">
                    <label for="from_date">From</label>
                    <input id="from_date" class="invoice-input" type="date" name="from_date" value="{{ $fromDate ?? '' }}">
                </div>
                <div class="invoice-field">
                    <label for="to_date">To</label>
                    <input id="to_date" class="invoice-input" type="date" name="to_date" value="{{ $toDate ?? '' }}">
                </div>
                <div class="invoice-field">
                    <label for="per_page">Rows per page</label>
                    <select id="per_page" class="invoice-select" name="per_page">
                        @foreach(($perPageOptions ?? [20, 50, 100, 250, 500]) as $option)
                            <option value="{{ $option }}" @selected((int) ($perPage ?? 20) === (int) $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="invoice-filter-actions" style="margin-top:8px;">
                <button type="submit" class="invoice-btn primary">Apply Filters</button>
                <a href="{{ route('invoices.index') }}" class="invoice-btn" data-filter-clear="invoices-index">Reset</a>
            </div>
        </form>
    </details>

    @if(session('success'))
        <div class="invoice-alert success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="invoice-alert error">{{ session('error') }}</div>
    @endif

    <div class="invoice-workspace-grid">
    <div class="invoice-list-shell rn-table-shell">
        <form id="invoiceBulkForm" method="POST" action="{{ route('invoices.bulk.print') }}" target="_blank">
            @csrf
            <div class="invoice-list-top">
                <div>
                    <strong>{{ $totalInvoices }} invoice{{ $totalInvoices === 1 ? '' : 's' }}</strong>
                    <span class="invoice-selected-count" id="invoiceSelectedCount">0 selected</span>
                </div>
                <div class="invoice-bulk-actions" id="invoiceBulkActions" hidden>
                    <button type="submit" class="invoice-btn" data-bulk-action="{{ route('invoices.bulk.print') }}">Bulk PDF / Print</button>
                    <button type="submit" class="invoice-btn soft" data-bulk-action="{{ route('invoices.bulk.export.csv') }}">Export Selected CSV</button>
                    @if($canDeleteInvoices)
                        <button type="button" class="invoice-btn danger" id="bulkDeleteInvoices">Bulk Delete</button>
                    @endif
                </div>
            </div>

            @if($invoices->isEmpty())
                <div class="invoice-empty">No invoices match this view right now. Adjust filters or create a new invoice to continue.</div>
            @else
                <div class="invoice-mobile-list" aria-label="Invoice mobile list">
                    @foreach($invoices as $invoice)
                        @php
                            $statusClass = match ($invoice->payment_status) {
                                'paid' => 'rn-badge-success',
                                'partial' => 'rn-badge-warning',
                                'overdue' => 'rn-badge-danger',
                                'cancelled' => 'rn-badge-muted',
                                default => in_array($invoice->status, ['draft'], true) ? 'rn-badge-draft' : 'rn-badge-danger',
                            };
                            $statusLabel = $invoice->payment_status === 'partial'
                                ? 'Partial'
                                : strtoupper($invoice->payment_status ?: ($invoice->status ?: 'draft'));
                            $gstTotal = (float) $invoice->cgst_amount + (float) $invoice->sgst_amount + (float) $invoice->igst_amount;
                        @endphp
                        <article class="invoice-mobile-card">
                            <div class="invoice-mobile-top">
                                <input type="checkbox" class="invoice-mobile-check invoice-row-check" name="invoice_ids[]" value="{{ $invoice->id }}" aria-label="Select invoice {{ $invoice->invoice_number }}">
                                <div class="invoice-mobile-main">
                                    <div class="invoice-mobile-heading">
                                        <div>
                                            <a href="{{ route('invoices.show', $invoice->id) }}" class="invoice-mobile-number">{{ $invoice->invoice_number }}</a>
                                            <div class="invoice-mobile-customer">{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'N/A') }}{{ ($invoice->bill_to_phone ?: ($invoice->customer->phone ?? '')) ? ' - ' . ($invoice->bill_to_phone ?: ($invoice->customer->phone ?? '')) : '' }}</div>
                                        </div>
                                        <span class="invoice-status-badge rn-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                                    </div>
                                    <div class="invoice-mobile-metrics">
                                        <div class="invoice-mobile-metric">
                                            <span>Date</span>
                                            <strong>{{ optional($invoice->invoice_date)->format('d/m/Y') ?: 'No date' }}</strong>
                                        </div>
                                        <div class="invoice-mobile-metric">
                                            <span>Due</span>
                                            <strong>{{ optional($invoice->due_date)->format('d/m/Y') ?: 'N/A' }}</strong>
                                        </div>
                                        <div class="invoice-mobile-metric">
                                            <span>Amount</span>
                                            <strong>@if($canViewFinance)&#8377;{{ number_format($invoice->total_amount, 2) }}@else Restricted @endif</strong>
                                        </div>
                                        <div class="invoice-mobile-metric">
                                            <span>Balance</span>
                                            <strong>@if($canViewFinance)&#8377;{{ number_format($invoice->balance_amount, 2) }}@else Restricted @endif</strong>
                                        </div>
                                    </div>
                                    <div class="invoice-mobile-meta">
                                        <span>GST @if($canViewFinance)&#8377;{{ number_format($gstTotal, 2) }}@else Restricted @endif</span>
                                        <span>CGST {{ number_format((float) $invoice->cgst_amount, 2) }}</span>
                                        <span>SGST {{ number_format((float) $invoice->sgst_amount, 2) }}</span>
                                        <span>IGST {{ number_format((float) $invoice->igst_amount, 2) }}</span>
                                    </div>
                                    <div class="invoice-mobile-actions">
                                        <a href="{{ route('invoices.show', $invoice->id) }}" class="invoice-action-icon" title="View invoice" aria-label="View invoice">{!! $invoiceIcon('eye') !!}</a>
                                        @if($canCreatePayments && !in_array($invoice->payment_status, ['paid', 'cancelled'], true) && $invoice->status !== 'cancelled')
                                            <button type="submit" form="markPaidInvoice{{ $invoice->id }}" class="invoice-action-icon pay" title="Record payment" aria-label="Record payment" onclick="return confirm('Mark this invoice as paid?')">{!! $invoiceIcon('card') !!}</button>
                                        @endif
                                        <a href="{{ route('invoices.print', $invoice->id) }}" target="_blank" class="invoice-action-icon pdf" title="Download PDF" aria-label="Download PDF">{!! $invoiceIcon('download') !!}</a>
                                        <details class="invoice-action-menu">
                                            <summary aria-label="More invoice actions" title="More invoice actions">{!! $invoiceIcon('more') !!}</summary>
                                            <div class="invoice-action-panel">
                                                <a href="{{ route('invoices.show', $invoice->id) }}">View</a>
                                                @if($canUpdateInvoices)
                                                    <a href="{{ route('invoices.edit', $invoice->id) }}">Edit</a>
                                                @endif
                                                <a href="{{ route('invoices.print', $invoice->id) }}" target="_blank">Download / Print PDF</a>
                                                @if($canCreatePayments && !in_array($invoice->payment_status, ['paid', 'cancelled'], true) && $invoice->status !== 'cancelled')
                                                    <button type="submit" form="markPaidInvoice{{ $invoice->id }}" onclick="return confirm('Mark this invoice as paid?')">Mark Paid</button>
                                                @endif
                                                @if($canUpdateInvoices && $invoice->status !== 'cancelled' && $invoice->payment_status !== 'cancelled')
                                                    <button type="submit" form="voidInvoice{{ $invoice->id }}" onclick="return confirm('Void this invoice?')">Void Invoice</button>
                                                @endif
                                                @if($canDeleteInvoices)
                                                    <button type="submit" form="deleteInvoice{{ $invoice->id }}" onclick="return confirm('Delete this invoice permanently? Payments will remain as payment records but will be unlinked from this invoice.');">Delete Invoice</button>
                                                @endif
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="invoice-table-wrap">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th style="width:42px;"><input type="checkbox" class="invoice-check" id="selectAllInvoices" aria-label="Select all invoices"></th>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th class="invoice-money">Amount</th>
                                <th class="invoice-money">Balance</th>
                                <th>GST</th>
                                <th style="width:142px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $invoice)
                                @php
                                    $statusClass = match ($invoice->payment_status) {
                                        'paid' => 'rn-badge-success',
                                        'partial' => 'rn-badge-warning',
                                        'overdue' => 'rn-badge-danger',
                                        'cancelled' => 'rn-badge-muted',
                                        default => in_array($invoice->status, ['draft'], true) ? 'rn-badge-draft' : 'rn-badge-danger',
                                    };
                                    $statusLabel = $invoice->payment_status === 'partial'
                                        ? 'Partial'
                                        : strtoupper($invoice->payment_status ?: ($invoice->status ?: 'draft'));
                                    $gstTotal = (float) $invoice->cgst_amount + (float) $invoice->sgst_amount + (float) $invoice->igst_amount;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" class="invoice-check invoice-row-check" name="invoice_ids[]" value="{{ $invoice->id }}" aria-label="Select invoice {{ $invoice->invoice_number }}">
                                    </td>
                                    <td>
                                        <a href="{{ route('invoices.show', $invoice->id) }}" class="invoice-number-link">{{ $invoice->invoice_number }}</a>
                                        <div class="invoice-muted">{{ optional($invoice->invoice_date)->format('d/m/Y') ?: 'No date' }}</div>
                                    </td>
                                    <td>
                                        <strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'N/A') }}</strong>
                                        <div class="invoice-muted">{{ $invoice->bill_to_phone ?: ($invoice->customer->phone ?? '') }}</div>
                                    </td>
                                    <td><span class="invoice-status-badge rn-badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
                                    <td>{{ optional($invoice->due_date)->format('d/m/Y') ?: 'N/A' }}</td>
                                    <td class="invoice-money">@if($canViewFinance)&#8377;{{ number_format($invoice->total_amount, 2) }}@else Restricted @endif</td>
                                    <td class="invoice-money">@if($canViewFinance)&#8377;{{ number_format($invoice->balance_amount, 2) }}@else Restricted @endif</td>
                                    <td>
                                        <div class="invoice-gst-compact">
                                            <strong>@if($canViewFinance)GST &#8377;{{ number_format($gstTotal, 2) }}@else Restricted @endif</strong>
                                            <div class="invoice-muted">
                                                @if((float) $invoice->cgst_amount > 0 || (float) $invoice->sgst_amount > 0)
                                                    CGST &#8377;{{ number_format((float) $invoice->cgst_amount, 2) }} / SGST &#8377;{{ number_format((float) $invoice->sgst_amount, 2) }}
                                                @endif
                                                @if((float) $invoice->igst_amount > 0)
                                                    IGST &#8377;{{ number_format((float) $invoice->igst_amount, 2) }}
                                                @endif
                                                @if($gstTotal <= 0)
                                                    No GST
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="invoice-row-actions">
                                            <a href="{{ route('invoices.show', $invoice->id) }}" class="invoice-action-icon" title="View invoice" aria-label="View invoice">{!! $invoiceIcon('eye') !!}</a>
                                            @if($canCreatePayments && !in_array($invoice->payment_status, ['paid', 'cancelled'], true) && $invoice->status !== 'cancelled')
                                                <button type="submit" form="markPaidInvoice{{ $invoice->id }}" class="invoice-action-icon pay" title="Record payment" aria-label="Record payment" onclick="return confirm('Mark this invoice as paid?')">{!! $invoiceIcon('card') !!}</button>
                                            @endif
                                            <a href="{{ route('invoices.print', $invoice->id) }}" target="_blank" class="invoice-action-icon pdf" title="Download PDF" aria-label="Download PDF">{!! $invoiceIcon('download') !!}</a>
                                            <details class="invoice-action-menu">
                                                <summary aria-label="More invoice actions" title="More invoice actions">{!! $invoiceIcon('more') !!}</summary>
                                                <div class="invoice-action-panel">
                                                    <a href="{{ route('invoices.show', $invoice->id) }}">View</a>
                                                    @if($canUpdateInvoices)
                                                        <a href="{{ route('invoices.edit', $invoice->id) }}">Edit</a>
                                                    @endif
                                                    <a href="{{ route('invoices.print', $invoice->id) }}" target="_blank">Download / Print PDF</a>
                                                    @if($canCreatePayments && !in_array($invoice->payment_status, ['paid', 'cancelled'], true) && $invoice->status !== 'cancelled')
                                                        <button type="submit" form="markPaidInvoice{{ $invoice->id }}" onclick="return confirm('Mark this invoice as paid?')">Mark Paid</button>
                                                    @endif
                                                    @if($canUpdateInvoices && $invoice->status !== 'cancelled' && $invoice->payment_status !== 'cancelled')
                                                        <button type="submit" form="voidInvoice{{ $invoice->id }}" onclick="return confirm('Void this invoice?')">Void Invoice</button>
                                                    @endif
                                                    @if($canDeleteInvoices)
                                                        <button type="submit" form="deleteInvoice{{ $invoice->id }}" onclick="return confirm('Delete this invoice permanently? Payments will remain as payment records but will be unlinked from this invoice.');">Delete Invoice</button>
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
            @endif
        </form>
    </div>

    <aside class="invoice-finance-panel" aria-label="Invoice finance summary">
        <div class="invoice-side-card">
            <div class="invoice-side-head"><strong>Finance Summary</strong><span>Live</span></div>
            <div class="invoice-side-list">
                <div><span>Outstanding</span><strong>@if($canViewFinance){!! $currency($outstandingAmount) !!}@else Restricted @endif</strong></div>
                <div><span>Overdue</span><strong class="danger">@if($canViewFinance){!! $currency($overdueAmount) !!}@else Restricted @endif</strong></div>
                <div><span>Collected Today</span><strong class="success">@if($canViewFinance){!! $currency($collectedToday) !!}@else Restricted @endif</strong></div>
                <div><span>Collected Month</span><strong class="success">@if($canViewFinance){!! $currency($collectedThisMonth) !!}@else Restricted @endif</strong></div>
                <div><span>Largest Invoice</span><strong>@if($canViewFinance){!! $currency($largestInvoice) !!}@else Restricted @endif</strong></div>
                <div><span>Average Invoice</span><strong>@if($canViewFinance){!! $currency($averageInvoiceValue) !!}@else Restricted @endif</strong></div>
            </div>
        </div>
        <div class="invoice-side-card">
            <div class="invoice-side-head"><strong>Quick Actions</strong></div>
            <div class="invoice-side-actions">
                @if($canCreatePayments)<button type="button" class="invoice-side-action" data-invoice-filter-trigger>{!! $invoiceIcon('card') !!}<span>Find Invoice to Pay</span></button>@endif
                <a href="{{ route('invoices.export.csv', request()->query()) }}" class="invoice-side-action">{!! $invoiceIcon('download') !!}<span>Export All</span></a>
                @if($canCreateInvoices)<a href="{{ route('invoices.create') }}" class="invoice-side-action">{!! $invoiceIcon('plus') !!}<span>Create Invoice</span></a>@endif
                <button type="button" class="invoice-side-action" data-invoice-filter-trigger>{!! $invoiceIcon('filter') !!}<span>Filter Invoices</span></button>
            </div>
        </div>
        <div class="invoice-side-card invoice-aging-card">
            <div class="invoice-side-head"><strong>Outstanding by Aging</strong><span>Balance</span></div>
            <div class="invoice-aging-body">
                <div class="invoice-aging-donut" style="--p1: {{ round(($agingBuckets->get('0-30 Days')['amount'] / $agingTotal) * 100, 1) }}%; --p2: {{ round((($agingBuckets->get('0-30 Days')['amount'] + $agingBuckets->get('31-60 Days')['amount']) / $agingTotal) * 100, 1) }}%; --p3: {{ round((($agingBuckets->get('0-30 Days')['amount'] + $agingBuckets->get('31-60 Days')['amount'] + $agingBuckets->get('61-90 Days')['amount']) / $agingTotal) * 100, 1) }}%;">
                    <strong>@if($canViewFinance){!! $currency($agingBuckets->sum('amount')) !!}@else -- @endif</strong>
                    <span>Total</span>
                </div>
                <div class="invoice-aging-list">
                    @foreach($agingBuckets as $label => $bucket)
                        @php $agingPercent = $agingBuckets->sum('amount') > 0 ? round(($bucket['amount'] / max(1, $agingBuckets->sum('amount'))) * 100) : 0; @endphp
                        <div class="invoice-aging-row tone-{{ $bucket['tone'] }}">
                            <span><i></i>{{ $label }}</span>
                            <strong>@if($canViewFinance){!! $currency($bucket['amount']) !!} <em>{{ $agingPercent }}%</em>@else Restricted @endif</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </aside>
    </div>

    @if(method_exists($invoices, 'links'))
        <div class="ph-card" style="padding:14px 16px;">
            {{ $invoices->links() }}
        </div>
    @endif

    @foreach($invoices as $invoice)
        @if($canCreatePayments && !in_array($invoice->payment_status, ['paid', 'cancelled'], true) && $invoice->status !== 'cancelled')
            <form id="markPaidInvoice{{ $invoice->id }}" method="POST" action="{{ route('invoices.markPaid', $invoice->id) }}" style="display:none;">
                @csrf
            </form>
        @endif

        @if($canUpdateInvoices && $invoice->status !== 'cancelled' && $invoice->payment_status !== 'cancelled')
            <form id="voidInvoice{{ $invoice->id }}" method="POST" action="{{ route('invoices.void', $invoice->id) }}" style="display:none;">
                @csrf
                @method('PUT')
            </form>
        @endif

        @if($canDeleteInvoices)
            <form id="deleteInvoice{{ $invoice->id }}" method="POST" action="{{ route('invoices.destroy', $invoice->id) }}" style="display:none;">
                @csrf
                @method('DELETE')
            </form>
        @endif
    @endforeach

    @if($canDeleteInvoices)
        <form id="invoiceBulkDeleteForm" method="POST" action="{{ route('invoices.bulk.delete') }}" style="display:none;">
            @csrf
        </form>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const bulkForm = document.getElementById('invoiceBulkForm');
            const bulkDeleteForm = document.getElementById('invoiceBulkDeleteForm');
            const bulkDeleteButton = document.getElementById('bulkDeleteInvoices');
            const selectAll = document.getElementById('selectAllInvoices');
            const rowChecks = Array.from(document.querySelectorAll('.invoice-row-check'));
            const countEl = document.getElementById('invoiceSelectedCount');
            const bulkActions = document.getElementById('invoiceBulkActions');

            function updateSelectedCount() {
                const selected = rowChecks.filter((checkbox) => checkbox.checked).length;
                if (countEl) {
                    countEl.textContent = selected + ' selected';
                }
                if (bulkActions) {
                    bulkActions.hidden = selected === 0;
                }
                if (selectAll) {
                    selectAll.checked = selected > 0 && selected === rowChecks.length;
                    selectAll.indeterminate = selected > 0 && selected < rowChecks.length;
                }
            }

            selectAll?.addEventListener('change', function () {
                rowChecks.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                updateSelectedCount();
            });

            const invoiceFilterPanel = document.querySelector('[data-filter-panel-key="invoices-index"]');
            if (invoiceFilterPanel) {
                invoiceFilterPanel.open = false;
                try {
                    localStorage.setItem('rentnexis:filter-panel:invoices-index', 'closed');
                } catch (error) {}
            }

            document.querySelectorAll('[data-invoice-filter-trigger]').forEach((button) => {
                button.addEventListener('click', function () {
                    const panel = document.querySelector('[data-filter-panel-key="invoices-index"]');
                    if (!panel) return;
                    panel.open = !panel.open;
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
            rowChecks.forEach((checkbox) => checkbox.addEventListener('change', updateSelectedCount));

            document.querySelectorAll('[data-bulk-action]').forEach((button) => {
                button.addEventListener('click', function (event) {
                    const selected = rowChecks.filter((checkbox) => checkbox.checked).length;
                    if (selected === 0) {
                        event.preventDefault();
                        alert('Select at least one invoice first.');
                        return;
                    }

                    bulkForm.action = button.dataset.bulkAction;
                    bulkForm.method = 'POST';
                    bulkForm.target = button.dataset.bulkAction.includes('/export/csv') ? '_self' : '_blank';
                });
            });

            bulkDeleteButton?.addEventListener('click', function () {
                const selectedChecks = rowChecks.filter((checkbox) => checkbox.checked);

                if (!bulkDeleteForm || selectedChecks.length === 0) {
                    alert('Select at least one invoice first.');
                    return;
                }

                if (!window.confirm(`Delete ${selectedChecks.length} selected invoice(s) permanently? Payments will remain as payment records but will be unlinked from deleted invoices.`)) {
                    return;
                }

                bulkDeleteForm.querySelectorAll('input[name="invoice_ids[]"]').forEach((input) => input.remove());

                selectedChecks.forEach((checkbox) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'invoice_ids[]';
                    input.value = checkbox.value;
                    bulkDeleteForm.appendChild(input);
                });

                bulkDeleteForm.submit();
            });

            document.addEventListener('click', function (event) {
                document.querySelectorAll('.invoice-action-menu[open]').forEach((menu) => {
                    if (!menu.contains(event.target)) {
                        menu.removeAttribute('open');
                        menu.closest('tr')?.classList.remove('is-action-open');
                    }
                });
            });

            document.querySelectorAll('.invoice-action-menu').forEach((menu) => {
                menu.addEventListener('toggle', function () {
                    const row = menu.closest('tr');

                    if (menu.open) {
                        document.querySelectorAll('.invoice-action-menu[open]').forEach((otherMenu) => {
                            if (otherMenu !== menu) {
                                otherMenu.removeAttribute('open');
                                otherMenu.closest('tr')?.classList.remove('is-action-open');
                            }
                        });

                        row?.classList.add('is-action-open');
                    } else {
                        row?.classList.remove('is-action-open');
                    }
                });
            });



            updateSelectedCount();
        });
    </script>
</div>
@endsection
