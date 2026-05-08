@extends('layouts.app')

@section('content')
@php
    $statusClass = match ($invoice->payment_status) {
        'paid' => 'status-paid',
        'partial' => 'status-partial',
        'cancelled' => 'status-draft',
        default => in_array($invoice->status, ['draft'], true) ? 'status-draft' : 'status-unpaid',
    };

    $statusLabel = $invoice->payment_status === 'partial'
        ? 'Partially Paid'
        : ucfirst($invoice->payment_status ?: ($invoice->status ?: 'draft'));

    $organizationAddress = collect([
        $invoice->organization->address ?? null,
        trim(collect([
            $invoice->organization->city ?? null,
            $invoice->organization->state ?? null,
            $invoice->organization->pincode ?? null,
        ])->filter()->implode(', ')),
        $invoice->organization->country ?? null,
    ])->filter()->implode("\n");

    $billingAddress = collect([
        $invoice->bill_to_address,
        trim(collect([
            $invoice->bill_to_city,
            $invoice->bill_to_state,
            $invoice->bill_to_state_code ? '(' . $invoice->bill_to_state_code . ')' : null,
            $invoice->bill_to_pincode,
        ])->filter()->implode(' ')),
    ])->filter()->implode("\n");

    $shippingAddress = collect([
        $invoice->ship_to_address,
        trim(collect([
            $invoice->ship_to_city,
            $invoice->ship_to_state,
            $invoice->ship_to_state_code ? '(' . $invoice->ship_to_state_code . ')' : null,
            $invoice->ship_to_pincode,
        ])->filter()->implode(' ')),
    ])->filter()->implode("\n");

    $organizationInitials = strtoupper(substr($invoice->organization->name ?? 'OR', 0, 2));
    $displayRentalPeriod = $invoice->inferredRentalPeriod();
    $canDeletePayments = auth()->user()?->canAccessModule('payments', 'delete') ?? false;
    $invoiceSourceTypes = $invoice->items->pluck('source_type')->filter()->unique();
    $hasRentalLines = $invoice->rentalRenewal || $invoiceSourceTypes->contains('rental');
    $hasSaleLines = $invoiceSourceTypes->contains('sale');
    $invoiceTitle = $hasRentalLines && !$hasSaleLines
        ? 'Rental Invoice'
        : ($hasSaleLines && !$hasRentalLines ? 'Sale Invoice' : 'Tax Invoice');
    $subjectLine = $invoice->rentalRenewal
        ? 'Monthly Rental Renewal'
        : ($hasRentalLines
            ? 'Rental Charges'
            : ($hasSaleLines ? 'Equipment Sale' : ($invoice->items->first()?->display_description ?: 'Invoice Charges')));
    $shipMatchesBilling = trim(strtolower(implode('|', [
        $invoice->bill_to_name ?: ($invoice->customer->name ?? ''),
        $invoice->bill_to_phone ?: '',
        $billingAddress,
    ]))) === trim(strtolower(implode('|', [
        $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? '')),
        $invoice->ship_to_phone ?: ($invoice->bill_to_phone ?: ''),
        $shippingAddress ?: $billingAddress,
    ])));
@endphp

<div style="max-width:1180px; margin:0 auto;">
    <style>
        .invoice-view-shell {
            display: grid;
            gap: 22px;
        }

        .invoice-hero {
            background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
            border: 1px solid #dbe7f3;
            border-radius: 28px;
            padding: 30px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .invoice-hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            padding-bottom: 26px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .invoice-brand {
            display: flex;
            align-items: flex-start;
            gap: 22px;
            max-width: 66%;
        }

        .invoice-logo {
            width: 118px;
            height: 118px;
            border-radius: 20px;
            background: #ffffff;
            border: 1px solid #dbe7f3;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .invoice-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .invoice-logo-fallback {
            font-size: 34px;
            font-weight: 700;
            color: #0f766e;
            letter-spacing: 0.05em;
        }

        .invoice-brand h1 {
            margin: 0 0 8px;
            font-size: 22px;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        .invoice-brand-meta {
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
        }

        .invoice-title-panel {
            min-width: 290px;
            text-align: right;
        }

        .invoice-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
            border-radius: 999px;
            background: #ecfeff;
            color: #0f766e;
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .invoice-title-panel h2 {
            margin: 16px 0 8px;
            font-size: 40px;
            line-height: 1;
            letter-spacing: -0.04em;
            color: #0f172a;
        }

        .invoice-subtitle {
            margin: 0;
            color: #64748b;
            font-size: 15px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 16px;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .status-partial {
            background: #ffedd5;
            color: #9a3412;
        }

        .status-unpaid,
        .status-draft {
            background: #fee2e2;
            color: #991b1b;
        }

        .invoice-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .invoice-actions p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .invoice-action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .invoice-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .invoice-button:hover {
            transform: translateY(-1px);
        }

        .invoice-button-secondary {
            background: #ffffff;
            color: #0f172a;
            border: 1px solid #cbd5e1;
        }

        .invoice-button-primary {
            background: #0f172a;
            color: #ffffff;
            border: 1px solid #0f172a;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.15);
        }

        .invoice-meta-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
        }

        .invoice-meta-card,
        .invoice-address-card,
        .invoice-table-card,
        .invoice-note-card,
        .invoice-summary-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
        }

        .invoice-meta-card {
            padding: 18px;
        }

        .invoice-meta-label {
            display: block;
            margin-bottom: 8px;
            color: #64748b;
            font-size: 11px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .invoice-meta-value {
            color: #0f172a;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.5;
            word-break: break-word;
        }

        .invoice-address-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .invoice-address-card {
            padding: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .invoice-section-tag {
            display: inline-flex;
            align-items: center;
            margin-bottom: 14px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 700;
        }

        .invoice-contact-name {
            margin: 0 0 10px;
            font-size: 20px;
            color: #0f172a;
        }

        .invoice-contact-line {
            margin: 4px 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.65;
            white-space: pre-line;
        }

        .invoice-table-card {
            overflow: hidden;
        }

        .invoice-table-wrap {
            overflow-x: auto;
        }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
        }

        .invoice-table thead th {
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: left;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        .invoice-table tbody td {
            padding: 16px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
            color: #334155;
            font-size: 14px;
        }

        .invoice-table tbody tr:last-child td {
            border-bottom: none;
        }

        .invoice-table .text-right {
            text-align: right;
        }

        .invoice-item-title {
            display: block;
            margin-bottom: 6px;
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
        }

        .invoice-item-subline {
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .invoice-bottom-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 22px;
            align-items: start;
        }

        .invoice-notes-stack {
            display: grid;
            gap: 16px;
        }

        .invoice-note-card {
            padding: 20px 22px;
        }

        .invoice-note-title {
            margin: 0 0 10px;
            color: #64748b;
            font-size: 12px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .invoice-note-body {
            margin: 0;
            color: #475569;
            font-size: 14px;
            line-height: 1.7;
            white-space: pre-line;
        }

        .invoice-summary-card {
            padding: 24px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .invoice-summary-card h3 {
            margin: 0 0 18px;
            font-size: 18px;
            color: #0f172a;
        }

        .invoice-subject-card {
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
            color: #334155;
            font-size: 14px;
        }

        .invoice-subject-card strong {
            margin-right: 10px;
            color: #64748b;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .invoice-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 8px 0;
            color: #334155;
            font-size: 14px;
        }

        .invoice-summary-row strong {
            color: #0f172a;
        }

        .invoice-summary-divider {
            border: 0;
            border-top: 1px solid #e2e8f0;
            margin: 12px 0;
        }

        .invoice-grand-total {
            margin-top: 8px;
            padding: 16px 18px;
            border-radius: 18px;
            background: #0f172a;
        }

        .invoice-grand-total .invoice-summary-row,
        .invoice-grand-total .invoice-summary-row strong {
            color: #ffffff;
        }

        @media (max-width: 1100px) {
            .invoice-meta-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .invoice-hero-top,
            .invoice-actions,
            .invoice-bottom-grid {
                grid-template-columns: none;
                display: block;
            }

            .invoice-brand,
            .invoice-title-panel {
                max-width: 100%;
                width: 100%;
            }

            .invoice-title-panel {
                text-align: left;
                margin-top: 22px;
            }

            .invoice-logo {
                width: 96px;
                height: 96px;
            }

            .invoice-brand h1 {
                font-size: 20px;
            }

            .invoice-actions {
                margin-top: 22px;
            }

            .invoice-action-buttons {
                margin-top: 12px;
            }

            .invoice-meta-grid,
            .invoice-address-grid {
                grid-template-columns: 1fr 1fr;
            }

            .invoice-summary-card {
                margin-top: 20px;
            }
        }

        @media (max-width: 640px) {
            .invoice-hero {
                padding: 20px;
                border-radius: 20px;
            }

            .invoice-brand {
                flex-direction: column;
            }

            .invoice-logo {
                width: 88px;
                height: 88px;
            }

            .invoice-brand h1 {
                font-size: 18px;
            }

            .invoice-action-buttons {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                width: 100%;
            }

            .invoice-action-buttons > * {
                width: 100%;
                min-width: 0;
            }

            .invoice-meta-grid,
            .invoice-address-grid {
                grid-template-columns: 1fr;
            }

            .invoice-table thead {
                display: none;
            }

            .invoice-table,
            .invoice-table tbody,
            .invoice-table tr,
            .invoice-table td {
                display: block;
                width: 100%;
            }

            .invoice-table tbody tr {
                border-bottom: 1px solid #e2e8f0;
            }

            .invoice-table tbody td {
                border-bottom: none;
                padding: 10px 16px;
            }

            .invoice-table tbody td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 4px;
                color: #64748b;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .invoice-table .text-right {
                text-align: left;
            }
        }

        @media (max-width: 420px) {
            .invoice-action-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="invoice-view-shell">
        <div class="invoice-hero">
            <div class="invoice-hero-top">
                <div class="invoice-brand">
                    <div class="invoice-logo">
                        @if($invoice->organization && $invoice->organization->logo)
                            <img src="{{ asset('storage/' . $invoice->organization->logo) }}" alt="Logo">
                        @else
                            <span class="invoice-logo-fallback">{{ $organizationInitials }}</span>
                        @endif
                    </div>

                    <div>
                        <h1>{{ $invoice->organization->name ?? 'Organization' }}</h1>
                        <div class="invoice-brand-meta">
                            @if($organizationAddress)
                                <div style="white-space: pre-line;">{{ $organizationAddress }}</div>
                            @endif
                            @if($invoice->organization?->phone)
                                <div>Phone: {{ $invoice->organization->phone }}</div>
                            @endif
                            @if($invoice->organization?->email)
                                <div>Email: {{ $invoice->organization->email }}</div>
                            @endif
                            <div>GSTIN: {{ $invoice->organization->gst_number ?: 'N/A' }}</div>
                        </div>
                    </div>
                </div>

                <div class="invoice-title-panel">
                    <span class="invoice-pill">{{ strtoupper($invoiceTitle) }}</span>
                    <h2>{{ $invoiceTitle }}</h2>
                    <p class="invoice-subtitle">Invoice #{{ $invoice->invoice_number }} · {{ $subjectLine }}</p>
                    <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                </div>
            </div>

            <div class="invoice-actions">
                <p>Review invoice details, line items, and outstanding balance before printing or editing.</p>

                <div class="invoice-action-buttons">
                    <a href="{{ route('invoices.edit', $invoice->id) }}" class="invoice-button invoice-button-secondary">
                        Edit Invoice
                    </a>
                    <a href="{{ route('invoices.print', $invoice->id) }}" target="_blank" class="invoice-button invoice-button-primary">
                        PDF / Print
                    </a>
                    @if(auth()->user()?->canAccessModule('invoices', 'update') && $invoice->status !== 'cancelled' && $invoice->payment_status !== 'cancelled')
                        <form action="{{ route('invoices.void', $invoice->id) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="invoice-button invoice-button-secondary" style="border:1px solid #fecaca;color:#b91c1c;" onclick="return confirm('Void this invoice? This keeps the invoice for audit history.');">Void Invoice</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        @if(session('success'))
            <div style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:14px 16px; border-radius:16px;">
                {{ session('success') }}
            </div>
        @endif

        <div class="invoice-meta-grid">
            <div class="invoice-meta-card">
                <span class="invoice-meta-label">Invoice Date</span>
                <div class="invoice-meta-value">{{ optional($invoice->invoice_date)->format('d M Y') ?: 'N/A' }}</div>
            </div>
            <div class="invoice-meta-card">
                <span class="invoice-meta-label">Due Date</span>
                <div class="invoice-meta-value">{{ optional($invoice->due_date)->format('d M Y') ?: 'N/A' }}</div>
            </div>
            <div class="invoice-meta-card">
                <span class="invoice-meta-label">Reference</span>
                <div class="invoice-meta-value">{{ $invoice->reference_number ?: 'N/A' }}</div>
            </div>
            <div class="invoice-meta-card">
                <span class="invoice-meta-label">Purchase Order</span>
                <div class="invoice-meta-value">{{ $invoice->purchase_order_number ?: 'N/A' }}</div>
            </div>
            <div class="invoice-meta-card">
                <span class="invoice-meta-label">Tax Type</span>
                <div class="invoice-meta-value">{{ strtoupper(str_replace('_', ' + ', $invoice->tax_type ?: 'N/A')) }}</div>
            </div>
        </div>

        <div class="invoice-subject-card">
            <strong>Subject</strong>{{ $subjectLine }}
        </div>

        @include('partials.activity-timeline', [
            'logs' => $activityLogs ?? collect(),
            'title' => 'Operations History',
            'subtitle' => 'Creation, edits, payment actions, and linked rental or sale events for this invoice.',
        ])

        <div class="invoice-address-grid">
            <div class="invoice-address-card">
                <span class="invoice-section-tag">Bill To</span>
                <h3 class="invoice-contact-name">{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer') }}</h3>

                @if($invoice->bill_to_phone)
                    <p class="invoice-contact-line">{{ $invoice->bill_to_phone }}</p>
                @endif

                @if($invoice->bill_to_email)
                    <p class="invoice-contact-line">{{ $invoice->bill_to_email }}</p>
                @endif

                <p class="invoice-contact-line">{{ $billingAddress ?: 'Address not provided' }}</p>
                <p class="invoice-contact-line">GSTIN: {{ $invoice->bill_to_gstin ?: 'N/A' }}</p>
            </div>

            <div class="invoice-address-card">
                <span class="invoice-section-tag">Ship To</span>
                <h3 class="invoice-contact-name">{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: 'Delivery Address') }}</h3>

                @if($shipMatchesBilling)
                    <p class="invoice-contact-line">Same as billing address</p>
                @else
                    @if($invoice->ship_to_phone)
                        <p class="invoice-contact-line">{{ $invoice->ship_to_phone }}</p>
                    @endif

                    <p class="invoice-contact-line">{{ $shippingAddress ?: 'Address not provided' }}</p>
                @endif
                <p class="invoice-contact-line">
                    Place of Supply:
                    {{ $invoice->place_of_supply_state ?: 'N/A' }}
                </p>
            </div>
        </div>

        <div class="invoice-table-card">
            <div class="invoice-table-wrap">
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th style="width:34%;">Item Description</th>
                            <th style="width:11%;">HSN/SAC</th>
                            <th style="width:10%;" class="text-right">Qty</th>
                            <th style="width:11%;" class="text-right">Rate</th>
                            <th style="width:11%;" class="text-right">Discount</th>
                            <th style="width:10%;" class="text-right">Tax</th>
                            <th style="width:13%;" class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoice->items as $item)
                            <tr>
                                <td data-label="Item Description">
                                    <span class="invoice-item-title">{{ $item->display_description }}</span>
                                    <div class="invoice-item-subline">
                                        @if($item->unit)
                                            Unit: {{ $item->unit }}
                                        @endif
                                        @if($item->days)
                                            @if($item->unit)
                                                |
                                            @endif
                                            Days: {{ number_format($item->days, 2) }}
                                        @endif
                                        @if($item->source_type === 'rental' && $displayRentalPeriod && !$invoice->rentalRenewal)
                                            @if($item->unit || $item->days)
                                                |
                                            @endif
                                            Rental: {{ optional($displayRentalPeriod['start_date'] ?? null)?->format('d M Y') ?: '-' }} to {{ optional($displayRentalPeriod['end_date'] ?? null)?->format('d M Y') ?: '-' }}
                                        @endif
                                        @if($invoice->rentalRenewal && ($item->unit === 'renewal' || ($item->product_id && $item->days)))
                                            @if($item->unit || $item->days)
                                                |
                                            @endif
                                            Renewal: {{ optional($invoice->rentalRenewal->previous_end_date)->format('d M Y') ?: '-' }} to {{ optional($invoice->rentalRenewal->renewed_end_date)->format('d M Y') ?: '-' }}
                                        @endif
                                    </div>
                                </td>
                                <td data-label="HSN/SAC">{{ $item->hsn_sac_code ?: 'N/A' }}</td>
                                <td data-label="Qty" class="text-right">{{ number_format($item->quantity, 2) }}</td>
                                <td data-label="Rate" class="text-right">&#8377;{{ number_format($item->rate, 2) }}</td>
                                <td data-label="Discount" class="text-right">&#8377;{{ number_format($item->discount_amount, 2) }}</td>
                                <td data-label="Tax" class="text-right">{{ number_format($item->tax_percentage, 2) }}%</td>
                                <td data-label="Amount" class="text-right">
                                    <strong>&#8377;{{ number_format($item->line_total, 2) }}</strong>
                                    <div class="invoice-item-subline">
                                        Taxable: &#8377;{{ number_format($item->taxable_amount, 2) }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="invoice-table-card">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; padding:16px 18px; border-bottom:1px solid #e2e8f0;">
                <div>
                    <h3 style="margin:0; color:#0f172a; font-size:18px;">Payment History</h3>
                    <p style="margin:4px 0 0; color:#64748b; font-size:13px;">Delete incorrect payment entries here before deleting linked invoices, rentals, or sales.</p>
                </div>
            </div>
            @if($invoice->payments->isEmpty())
                <div style="padding:16px 18px; color:#64748b;">No payments recorded for this invoice.</div>
            @else
                <div class="invoice-table-wrap">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Mode</th>
                                <th>Reference</th>
                                <th class="text-right">Amount</th>
                                @if($canDeletePayments)
                                    <th class="text-right">Action</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->payments as $payment)
                                <tr>
                                    <td data-label="Date">{{ optional($payment->payment_date)->format('d M Y') ?: '-' }}</td>
                                    <td data-label="Mode">{{ $payment->paymentMethodLabel() }}</td>
                                    <td data-label="Reference">
                                        {{ $payment->notes ?: 'Invoice payment' }}
                                        @if($payment->rental_id)
                                            <div style="color:#64748b; font-size:12px;">Rental #{{ $payment->rental_id }}</div>
                                        @endif
                                    </td>
                                    <td data-label="Amount" class="text-right">&#8377;{{ number_format((float) $payment->amount, 2) }}</td>
                                    @if($canDeletePayments)
                                        <td data-label="Action" class="text-right">
                                            <form action="{{ route('payments.destroy', $payment) }}" method="POST" style="margin:0;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="invoice-button invoice-button-secondary" style="border:1px solid #fecaca;color:#b91c1c; padding:8px 12px;" onclick="return confirm('Delete this payment? Invoice balance and sale payment status will be recalculated.');">Delete</button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="invoice-bottom-grid">
            <div class="invoice-notes-stack">
                @if($invoice->notes)
                    <div class="invoice-note-card">
                        <h4 class="invoice-note-title">Notes</h4>
                        <p class="invoice-note-body">{{ $invoice->notes }}</p>
                    </div>
                @endif

                @if($invoice->terms_conditions)
                    <div class="invoice-note-card">
                        <h4 class="invoice-note-title">Terms & Conditions</h4>
                        <p class="invoice-note-body">{{ $invoice->terms_conditions }}</p>
                    </div>
                @endif
            </div>

            <div class="invoice-summary-card">
                <h3>Invoice Summary</h3>

                <div class="invoice-summary-row">
                    <span>Subtotal</span>
                    <strong>&#8377;{{ number_format($invoice->subtotal, 2) }}</strong>
                </div>
                <div class="invoice-summary-row">
                    <span>Discount</span>
                    <strong>&#8377;{{ number_format($invoice->discount_amount, 2) }}</strong>
                </div>
                <div class="invoice-summary-row">
                    <span>Taxable Amount</span>
                    <strong>&#8377;{{ number_format($invoice->taxable_amount, 2) }}</strong>
                </div>
                <div class="invoice-summary-row">
                    <span>Deposit</span>
                    <strong>&#8377;{{ number_format($invoice->deposit_amount, 2) }}</strong>
                </div>
                <div class="invoice-summary-row">
                    <span>Shipping</span>
                    <strong>&#8377;{{ number_format($invoice->shipping_charges, 2) }}</strong>
                </div>

                @if($invoice->cgst_amount > 0)
                    <div class="invoice-summary-row">
                        <span>CGST</span>
                            <strong>&#8377;{{ number_format($invoice->cgst_amount, 2) }}</strong>
                        </div>
                        <div class="invoice-summary-row">
                            <span>SGST</span>
                            <strong>&#8377;{{ number_format($invoice->sgst_amount, 2) }}</strong>
                        </div>
                @endif

                @if($invoice->igst_amount > 0)
                    <div class="invoice-summary-row">
                        <span>IGST</span>
                            <strong>&#8377;{{ number_format($invoice->igst_amount, 2) }}</strong>
                        </div>
                @endif

                <div class="invoice-summary-row">
                    <span>Total Tax</span>
                    <strong>&#8377;{{ number_format($invoice->total_tax_amount, 2) }}</strong>
                </div>

                <hr class="invoice-summary-divider">

                <div class="invoice-grand-total">
                    <div class="invoice-summary-row">
                        <span>Total</span>
                        <strong>&#8377;{{ number_format($invoice->total_amount, 2) }}</strong>
                    </div>
                    <div class="invoice-summary-row">
                        <span>Paid</span>
                        <strong>&#8377;{{ number_format($invoice->paid_amount, 2) }}</strong>
                    </div>
                    <div class="invoice-summary-row">
                        <span>Balance Due</span>
                        <strong>&#8377;{{ number_format($invoice->balance_amount, 2) }}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
