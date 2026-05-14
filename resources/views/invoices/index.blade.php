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
            .invoice-summary-strip {
                display: none;
            }

            .invoice-toolbar-actions {
                justify-content: flex-end;
                width: 100%;
            }

            .invoice-filter-card {
                padding: 8px;
                border-radius: 14px;
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

            .invoice-table-wrap {
                overflow-x: auto;
            }

            .invoice-table {
                min-width: 860px;
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
    </style>

    <div class="invoice-toolbar">
        <div>
            <div class="rx-eyebrow" style="margin-bottom:8px;">Finance Ledger</div>
            <h1>Invoices</h1>
            <p>Compact ledger with bulk selection, quick filters, GST visibility, and row-level actions.</p>
        </div>
        <div class="invoice-toolbar-actions">
            <a href="{{ route('invoices.export.csv', request()->query()) }}" class="invoice-btn soft">Export CSV</a>
            @if($canCreateInvoices)
                <a href="{{ route('invoices.create') }}" class="invoice-btn primary">+ Create Invoice</a>
            @endif
        </div>
    </div>

    <div class="invoice-summary-strip">
        <a href="{{ $invoiceUrl(['status' => null]) }}" class="invoice-summary-tile">
            <span>Total</span>
            <strong>{{ $totalInvoices }}</strong>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'paid']) }}" class="invoice-summary-tile">
            <span>Paid</span>
            <strong>{{ $paidInvoices }}</strong>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'open']) }}" class="invoice-summary-tile">
            <span>Open / Overdue</span>
            <strong>{{ $openInvoices }}</strong>
        </a>
        <a href="{{ $invoiceUrl(['status' => 'open']) }}" class="invoice-summary-tile">
            <span>Outstanding Invoices (All)</span>
            <strong>@if($canViewFinance)&#8377;{{ number_format($outstandingAmount, 0) }}@else Restricted @endif</strong>
        </a>
    </div>

    <details class="invoice-filter-card invoice-filter-toggle">
        <summary>Filter / Sort <span>{{ ($search ?? '') || ($status ?? '') || ($customerId ?? '') || ($city ?? '') || ($fromDate ?? '') || ($toDate ?? '') ? 'Active' : 'Expand' }}</span></summary>
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
                <a href="{{ route('invoices.index') }}" class="invoice-btn">Reset</a>
            </div>
        </form>
    </details>

    @if(session('success'))
        <div class="invoice-alert success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="invoice-alert error">{{ session('error') }}</div>
    @endif

    <div class="invoice-list-shell rn-table-shell">
        <form id="invoiceBulkForm" method="POST" action="{{ route('invoices.bulk.print') }}" target="_blank">
            @csrf
            <div class="invoice-list-top">
                <div>
                    <strong>{{ $totalInvoices }} invoice{{ $totalInvoices === 1 ? '' : 's' }}</strong>
                    <span class="invoice-selected-count" id="invoiceSelectedCount">0 selected</span>
                </div>
                <div class="invoice-bulk-actions">
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
                                <th style="width:54px;">Actions</th>
                                <th>GST</th>
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
                                        <details class="invoice-action-menu">
                                            <summary aria-label="Invoice actions">...</summary>
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
                                    </td>
                                    <td>
                                        <strong>@if($canViewFinance)&#8377;{{ number_format($gstTotal, 2) }}@else Restricted @endif</strong>
                                        <div class="invoice-muted">
                                            CGST {{ number_format((float) $invoice->cgst_amount, 2) }} /
                                            SGST {{ number_format((float) $invoice->sgst_amount, 2) }} /
                                            IGST {{ number_format((float) $invoice->igst_amount, 2) }}
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

            function updateSelectedCount() {
                const selected = rowChecks.filter((checkbox) => checkbox.checked).length;
                if (countEl) {
                    countEl.textContent = selected + ' selected';
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
