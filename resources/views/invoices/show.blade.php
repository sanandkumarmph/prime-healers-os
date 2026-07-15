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
    $canUpdateInvoices = auth()->user()?->canAccessModule('invoices', 'update') ?? false;
    $canPrintInvoices = auth()->user()?->hasPermission('invoices.print') ?? false;
    $invoiceCallHref = $invoice->bill_to_phone ? 'tel:' . preg_replace('/\D+/', '', $invoice->bill_to_phone) : null;
    $invoicePaymentHref = $invoice->rental_id
        ? route('rentals.show', $invoice->rental_id) . '#rental-billing-actions'
        : ($invoice->sale_id ? route('sales.show', $invoice->sale_id) . '#sale-billing-actions' : '#invoice-payment-history');
    $invoiceQuickActions = collect();
    $invoiceMoreActions = collect();
    $invoiceInfoItems = collect();
    $invoiceFollowUpContextJson = json_encode([
        'customer_id' => $invoice->customer_id,
        'rental_id' => $invoice->rental_id,
        'sale_id' => $invoice->sale_id,
        'invoice_id' => $invoice->id,
        'reference' => $invoice->invoice_number ? 'Invoice ' . $invoice->invoice_number : 'Invoice #' . $invoice->id,
        'reminder_contact' => collect([$invoice->bill_to_name ?: ($invoice->customer->name ?? null), $invoice->bill_to_phone])->filter()->implode(' | '),
        'service_contact' => collect([$invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? null)), $invoice->ship_to_phone])->filter()->implode(' | '),
        'service_address' => str_replace("\n", ' | ', $shippingAddress ?: $billingAddress),
    ]);

    if ($invoiceCallHref) {
        $invoiceQuickActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => $invoiceCallHref,
        ]);
    }

    if (!empty($whatsAppLinks['invoice'])) {
        $invoiceQuickActions->push([
            'type' => 'link',
            'label' => 'WhatsApp Invoice',
            'href' => $whatsAppLinks['invoice'],
            'target' => '_blank',
            'rel' => 'noopener',
            'accent' => true,
        ]);
    }

    if ($canPrintInvoices) {
        $invoiceQuickActions->push([
            'type' => 'link',
            'label' => 'Print',
            'href' => route('invoices.print', $invoice->id),
            'target' => '_blank',
        ]);
    }

    if (($invoice->rental_id || $invoice->sale_id) && !in_array($invoice->payment_status, ['paid', 'cancelled'], true)) {
        $invoiceQuickActions->push([
            'type' => 'link',
            'label' => 'Payment',
            'href' => $invoicePaymentHref,
        ]);
    }

    $invoiceQuickActions->push([
        'type' => 'link',
        'label' => 'Timeline',
        'href' => '#invoice-activity-timeline',
    ]);

    if (auth()->user()?->canViewRecordFinance()) {
        $invoiceQuickActions->push([
            'type' => 'link',
            'label' => 'Ledger',
            'href' => route('ledger.index', ['invoice_id' => $invoice->id]),
        ]);
    }

    if ($canUpdateInvoices) {
        $invoiceMoreActions->push([
            'type' => 'link',
            'label' => 'Edit Invoice',
            'href' => route('invoices.edit', $invoice->id),
        ]);
    }

    $invoiceMoreActions->push([
        'type' => 'button',
        'label' => 'Add Follow-up',
        'attributes' => [
            'data-open-follow-up-modal' => true,
            'data-follow-up-context' => $invoiceFollowUpContextJson,
            'data-follow-up-type' => \App\Models\FollowUp::TYPE_PAYMENT,
            'data-follow-up-priority' => \App\Models\FollowUp::PRIORITY_HIGH,
        ],
    ]);

    if ($invoice->rental_id) {
        $invoiceMoreActions->push([
            'type' => 'link',
            'label' => 'View Rental',
            'href' => route('rentals.show', $invoice->rental_id),
        ]);
    }

    if ($invoice->sale_id) {
        $invoiceMoreActions->push([
            'type' => 'link',
            'label' => 'View Sale',
            'href' => route('sales.show', $invoice->sale_id),
        ]);
    }

    if ($invoice->customer_id) {
        $invoiceMoreActions->push([
            'type' => 'link',
            'label' => 'View Customer',
            'href' => route('customers.show', $invoice->customer_id),
        ]);
    }

    if (auth()->user()?->canAccessModule('invoices', 'update') && $invoice->status !== 'cancelled' && $invoice->payment_status !== 'cancelled') {
        $invoiceMoreActions->push([
            'type' => 'form',
            'label' => 'Void Invoice',
            'action' => route('invoices.void', $invoice->id),
            'method' => 'PUT',
            'confirm' => 'Void this invoice? This keeps the invoice for audit history.',
            'danger' => true,
        ]);
    }

    $invoiceInfoItems->push([
        'label' => 'Bill To',
        'value' => collect([$invoice->bill_to_name ?: ($invoice->customer->name ?? null), $invoice->bill_to_phone])->filter()->implode(' | '),
    ]);

    if ($invoice->ship_to_name || $shippingAddress) {
        $invoiceInfoItems->push([
            'label' => 'Ship To',
            'value' => collect([$invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? null)), $invoice->ship_to_phone ?: null])->filter()->implode(' • ') ?: ($invoice->ship_to_name ?: ($invoice->bill_to_name ?: 'Delivery Address')),
        ]);
    }

    if ($billingAddress) {
        $invoiceInfoItems->push([
            'label' => 'Billing Address',
            'value' => str_replace("\n", ' • ', $billingAddress),
        ]);
    }
@endphp

<div class="invoice-view-page" style="max-width:1180px; margin:0 auto;">
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

        .invoice-mobile-preview-card { display:none; }
        @media (max-width: 640px) {

            .invoice-view-page {
                padding: 0 10px 104px;
            }

            .invoice-view-shell {
                gap: 12px;
            }

            .invoice-hero {
                padding: 14px;
                border-radius: 18px;
            }

            .invoice-hero-top {
                display: grid;
                gap: 12px;
                padding-bottom: 12px;
            }

            .invoice-brand {
                flex-direction: row;
                align-items: center;
                gap: 12px;
            }

            .invoice-logo {
                width: 52px;
                height: 52px;
                border-radius: 14px;
            }

            .invoice-logo-fallback {
                font-size: 18px;
            }

            .invoice-brand h1 {
                margin-bottom: 2px;
                font-size: 16px;
                line-height: 1.25;
            }

            .invoice-brand-meta {
                display: none;
            }

            .invoice-title-panel {
                margin-top: 0;
                text-align: left;
            }

            .invoice-title-panel h2 {
                margin: 8px 0 4px;
                font-size: 24px;
                line-height: 1.05;
            }

            .invoice-subtitle {
                font-size: 12px;
                line-height: 1.35;
            }

            .status-badge {
                margin-top: 10px;
                padding: 6px 10px;
                font-size: 10px;
            }

            .invoice-actions {
                margin-top: 12px;
            }

            .invoice-actions p {
                display: none;
            }

            .invoice-button {
                min-height: 40px;
                padding: 8px 10px;
                border-radius: 10px;
                font-size: 12px;
            }

            .invoice-meta-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .invoice-meta-card {
                padding: 10px;
                border-radius: 14px;
            }

            .invoice-meta-label {
                margin-bottom: 4px;
                font-size: 9px;
            }

            .invoice-meta-value {
                font-size: 12px;
                line-height: 1.35;
            }

            .invoice-subject-card {
                padding: 12px;
                border-radius: 14px;
                font-size: 12px;
            }

            .invoice-document-desktop {
                display: none !important;
            }

            .invoice-mobile-preview-card {
                display: grid;
                gap: 12px;
                padding: 12px;
                border-radius: 16px;
            }

            .invoice-mobile-preview-head {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 10px;
                padding-bottom: 10px;
                border-bottom: 1px solid #e2e8f0;
            }

            .invoice-mobile-preview-head h3 {
                margin: 0;
                color: #0f172a;
                font-size: 16px;
                line-height: 1.2;
            }

            .invoice-mobile-preview-head span,
            .invoice-mobile-label {
                color: #64748b;
                font-size: 10px;
                font-weight: 800;
                letter-spacing: .06em;
                text-transform: uppercase;
            }

            .invoice-mobile-amount {
                color: #1d4ed8;
                font-size: 18px;
                font-weight: 900;
                white-space: nowrap;
            }

            .invoice-mobile-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .invoice-mobile-box {
                min-width: 0;
                padding: 10px;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                background: #f8fafc;
            }

            .invoice-mobile-box strong,
            .invoice-mobile-line strong {
                display: block;
                margin-top: 4px;
                color: #0f172a;
                font-size: 12px;
                line-height: 1.35;
                overflow-wrap: anywhere;
            }

            .invoice-mobile-box p {
                margin: 3px 0 0;
                color: #64748b;
                font-size: 11px;
                line-height: 1.4;
                overflow-wrap: anywhere;
            }

            .invoice-mobile-lines {
                display: grid;
                gap: 8px;
            }

            .invoice-mobile-line {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 10px;
                align-items: start;
                padding: 10px;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                background: #fff;
            }

            .invoice-mobile-line small {
                display: block;
                margin-top: 3px;
                color: #64748b;
                font-size: 11px;
                line-height: 1.35;
            }

            .invoice-mobile-line-amount {
                color: #0f172a;
                font-size: 12px;
                font-weight: 900;
                white-space: nowrap;
            }

            .invoice-mobile-total-list {
                display: grid;
                gap: 7px;
                padding-top: 8px;
                border-top: 1px solid #e2e8f0;
            }

            .invoice-mobile-total-row {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                color: #475569;
                font-size: 12px;
            }

            .invoice-mobile-total-row strong {
                color: #0f172a;
                white-space: nowrap;
            }

            .invoice-mobile-total-row.is-grand {
                margin-top: 2px;
                padding-top: 8px;
                border-top: 1px solid #cbd5e1;
                color: #0f172a;
                font-size: 14px;
                font-weight: 900;
            }

            .timeline-shell {
                padding: 10px;
                border-radius: 14px;
            }

            .timeline-summary-copy h2 {
                font-size: 15px;
            }

            .timeline-summary-copy p {
                font-size: 11px;
            }
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

        @media (max-width: 640px) {
            .invoice-view-page .invoice-hero {
                padding: 14px;
                border-radius: 18px;
            }

            .invoice-view-page .invoice-brand {
                flex-direction: row;
                align-items: center;
                gap: 12px;
            }

            .invoice-view-page .invoice-logo {
                width: 52px;
                height: 52px;
                border-radius: 14px;
            }

            .invoice-view-page .invoice-brand h1 {
                font-size: 16px;
                line-height: 1.25;
            }

            .invoice-view-page .invoice-title-panel h2 {
                font-size: 24px;
            }

            .invoice-view-page .invoice-mobile-grid {
                grid-template-columns: 1fr;
            }

            .invoice-view-page .invoice-action-buttons {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 420px) {
            .invoice-action-buttons {
                grid-template-columns: 1fr;
            }
        }

        @include('invoices.partials.invoice-document-styles', [
            'invoiceBodyFontStack' => "'Inter', 'Segoe UI', Roboto, Arial, sans-serif",
            'invoiceHeadingFontStack' => "'Manrope', 'Inter', 'Segoe UI', sans-serif",
        ])
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

        @include('partials.quick-action-toolbar', [
            'label' => 'Invoice Quick Actions',
            'actions' => $invoiceQuickActions,
            'moreActions' => $invoiceMoreActions,
            'infoItems' => $invoiceInfoItems,
        ])

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
            'anchorId' => 'invoice-activity-timeline',
        ])

        <div class="invoice-table-card invoice-mobile-preview-card" aria-label="Mobile invoice preview">
            <div class="invoice-mobile-preview-head">
                <div>
                    <span>Invoice Preview</span>
                    <h3>{{ $invoice->invoice_number }}</h3>
                    <p class="invoice-subtitle">{{ optional($invoice->invoice_date)->format('d M Y') ?: 'Date not set' }} &middot; {{ $subjectLine }}</p>
                </div>
                <div style="text-align:right;">
                    <div class="invoice-mobile-amount">&#8377;{{ number_format((float) $invoice->total_amount, 2) }}</div>
                    <span class="status-badge {{ $statusClass }}" style="margin-top:6px;">{{ $statusLabel }}</span>
                </div>
            </div>

            <div class="invoice-mobile-grid">
                <div class="invoice-mobile-box">
                    <span class="invoice-mobile-label">Bill To</span>
                    <strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer') }}</strong>
                    @if($invoice->bill_to_phone)<p>{{ $invoice->bill_to_phone }}</p>@endif
                    @if($billingAddress)<p>{{ $billingAddress }}</p>@endif
                </div>
                <div class="invoice-mobile-box">
                    <span class="invoice-mobile-label">Ship To</span>
                    <strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer')) }}</strong>
                    <p>{{ $shipMatchesBilling ? 'Same as billing' : ($shippingAddress ?: 'Address not set') }}</p>
                    <p>Due: {{ optional($invoice->due_date)->format('d M Y') ?: 'N/A' }}</p>
                </div>
            </div>

            <div class="invoice-mobile-lines">
                <span class="invoice-mobile-label">Line Items</span>
                @foreach($invoice->items as $item)
                    <div class="invoice-mobile-line">
                        <div>
                            <strong>{{ $item->description ?: 'Invoice item' }}</strong>
                            <small>Qty {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }} @if($item->unit) {{ $item->unit }} @endif &middot; Rate &#8377;{{ number_format((float) $item->rate, 2) }}</small>
                            @if($item->hsn_sac_code)<small>HSN/SAC: {{ $item->hsn_sac_code }}</small>@endif
                        </div>
                        <div class="invoice-mobile-line-amount">&#8377;{{ number_format((float) $item->total_amount, 2) }}</div>
                    </div>
                @endforeach
            </div>

            <div class="invoice-mobile-total-list">
                <div class="invoice-mobile-total-row"><span>Subtotal</span><strong>&#8377;{{ number_format((float) $invoice->subtotal, 2) }}</strong></div>
                @if((float) $invoice->discount_amount > 0)
                    <div class="invoice-mobile-total-row"><span>Discount</span><strong>-&#8377;{{ number_format((float) $invoice->discount_amount, 2) }}</strong></div>
                @endif
                @if((float) $invoice->total_tax_amount > 0)
                    <div class="invoice-mobile-total-row"><span>GST</span><strong>&#8377;{{ number_format((float) $invoice->total_tax_amount, 2) }}</strong></div>
                @endif
                @if((float) $invoice->shipping_charges > 0)
                    <div class="invoice-mobile-total-row"><span>Transport</span><strong>&#8377;{{ number_format((float) $invoice->shipping_charges, 2) }}</strong></div>
                @endif
                <div class="invoice-mobile-total-row is-grand"><span>Total</span><strong>&#8377;{{ number_format((float) $invoice->total_amount, 2) }}</strong></div>
                <div class="invoice-mobile-total-row"><span>Paid</span><strong>&#8377;{{ number_format((float) $invoice->paid_amount, 2) }}</strong></div>
                <div class="invoice-mobile-total-row"><span>Balance</span><strong>&#8377;{{ number_format((float) $invoice->balance_amount, 2) }}</strong></div>
            </div>

            <a href="{{ route('invoices.print', $invoice->id) }}" target="_blank" class="invoice-button invoice-button-primary">Open PDF / Print</a>
        </div>

        <div class="invoice-table-card invoice-document-desktop" style="padding:20px 22px;">
            @include('invoices.partials.invoice-document', [
                'invoice' => $invoice,
                'amountInWords' => $amountInWords ?? null,
                'pdfCurrencySymbol' => $pdfCurrencySymbol ?? null,
                'pdfCurrencyFallback' => $pdfCurrencyFallback ?? null,
                'documentRootClass' => 'invoice-page',
                'showPaymentsTable' => false,
            ])
        </div>

        <div class="invoice-table-card" id="invoice-payment-history">
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
    </div>
</div>

@include('partials.follow-up-modal')

@endsection
