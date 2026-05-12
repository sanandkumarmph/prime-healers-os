<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&family=manrope:600,700,800&display=swap" rel="stylesheet" />
    <meta name="application-name" content="Prime Healers OS">
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #24384f;
            font-variant-numeric: tabular-nums;
            -webkit-font-smoothing: antialiased;
        }

        .invoice-page {
            width: 100%;
        }

        .header-table,
        .meta-table,
        .party-table,
        .items-table,
        .bottom-table,
        .payment-details-table,
        .payments-table,
        .totals-table,
        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table {
            margin-bottom: 10px;
            border-bottom: 1px solid #d7e1ec;
        }

        .header-table td {
            vertical-align: top;
            padding-bottom: 10px;
        }

        .header-logo-cell {
            width: 34mm;
            padding-right: 10px;
        }

        .header-logo-box {
            width: 34mm;
            height: 20mm;
            border: 1px solid #d7e1ec;
            border-radius: 8px;
            background: #ffffff;
            text-align: center;
            vertical-align: middle;
        }

        .header-logo-box img {
            width: auto;
            height: auto;
            max-width: 33mm;
            max-height: 19mm;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        .header-logo-fallback {
            padding: 8px 6px 0;
            color: #24384f;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.25;
        }

        .header-company-cell {
            padding-right: 12px;
        }

        .header-company-name {
            margin: 0 0 5px;
            color: #12263F;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.2;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .header-company-line {
            margin: 0;
            color: #24384f;
            font-size: 9.8px;
            line-height: 1.45;
        }

        .header-title-cell {
            width: 52mm;
            text-align: right;
            padding-top: 2px;
            padding-right: 2px;
        }

        .header-invoice-title {
            margin: 0 0 8px;
            color: #12263F;
            font-size: 22px;
            font-weight: 700;
            line-height: 1;
            text-transform: uppercase;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .status-paid {
            color: #0E9F4B;
            background: #EAF8F0;
            border-color: #0E9F4B;
        }

        .status-partial {
            color: #B7791F;
            background: #FFF7E8;
            border-color: #B7791F;
        }

        .status-unpaid {
            color: #B30D23;
            background: #FDECEF;
            border-color: #B30D23;
        }

        .status-draft {
            color: #5B6E84;
            background: #EEF3F8;
            border-color: #C2D0DE;
        }

        .meta-table {
            margin-bottom: 8px;
            border: 1px solid #d7e1ec;
        }

        .meta-table td {
            width: 33.33%;
            padding: 7px 10px;
            background: #F8FBFE;
            border-right: 1px solid #d7e1ec;
            border-bottom: 1px solid #d7e1ec;
            vertical-align: top;
        }

        .meta-table tr:last-child td {
            border-bottom: 0;
        }

        .meta-table td:nth-child(3n) {
            border-right: 0;
        }

        .meta-label {
            display: block;
            margin-bottom: 3px;
            color: #5B6E84;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .meta-value {
            display: block;
            color: #12263F;
            font-size: 10.5px;
            font-weight: 700;
            word-break: break-word;
        }

        .party-table {
            margin-bottom: 8px;
            border: 1px solid #d7e1ec;
        }

        .party-table td {
            width: 50%;
            padding: 9px 11px;
            vertical-align: top;
        }

        .party-table td:first-child {
            border-right: 1px solid #d7e1ec;
        }

        .section-title {
            margin: 0 0 5px;
            color: #5B6E84;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .party-line {
            margin: 0 0 3px;
            color: #24384f;
            font-size: 10px;
            line-height: 1.45;
            word-break: break-word;
        }

        .subject-row {
            margin-bottom: 8px;
            padding: 7px 10px;
            border: 1px solid #d7e1ec;
            background: #F8FBFE;
            color: #24384f;
            font-size: 10px;
        }

        .subject-row strong {
            margin-right: 6px;
        }

        .items-table {
            margin-bottom: 8px;
        }

        .items-table thead {
            display: table-header-group;
        }

        .items-table th,
        .items-table td {
            border: 1px solid #d7e1ec;
            padding: 7px 6px;
            vertical-align: top;
        }

        .items-table th {
            background: #F0F6FB;
            color: #12263F;
            font-size: 9px;
            font-weight: 800;
            text-align: left;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .items-table td {
            color: #24384f;
            font-size: 9.7px;
        }

        .num {
            text-align: right;
            white-space: nowrap;
        }

        .item-title {
            display: block;
            color: #12263F;
            font-weight: 700;
            line-height: 1.35;
        }

        .item-subtext {
            display: block;
            margin-top: 2px;
            color: #5B6E84;
            font-size: 8.7px;
            line-height: 1.35;
        }

        .bottom-table td {
            vertical-align: top;
        }

        .bottom-notes-cell {
            width: 58%;
            padding-right: 10px;
        }

        .bottom-totals-cell {
            width: 42%;
        }

        .notes-box,
        .payment-card,
        .signature-box {
            border: 1px solid #d7e1ec;
            padding: 9px 11px;
        }

        .notes-box p,
        .notes-box ul {
            margin: 0 0 7px;
            color: #24384f;
            font-size: 9.6px;
            line-height: 1.45;
        }

        .notes-box ul {
            padding-left: 14px;
        }

        .notes-box li {
            margin: 0 0 4px;
        }

        .bottom-table,
        .payment-card {
            page-break-inside: avoid;
        }

        .payment-card {
            margin-top: 8px;
        }

        .payment-details-table td {
            width: 50%;
            padding: 0;
            vertical-align: top;
        }

        .payment-details-table td:first-child {
            border-right: 1px solid #d7e1ec;
            padding-right: 10px;
        }

        .payment-bank-line {
            margin: 0 0 4px;
            color: #24384f;
            font-size: 9.6px;
            line-height: 1.45;
        }

        .payment-qr-col {
            width: 30mm;
            text-align: center;
        }

        .payment-qr-caption {
            margin-top: 6px;
            color: #5B6E84;
            font-size: 8.8px;
            line-height: 1.3;
        }

        .qr-image {
            display: block;
            width: 26.4mm;
            height: 26.4mm;
            object-fit: contain;
            margin: 0 auto;
        }

        .payments-table {
            margin-top: 8px;
            page-break-inside: auto;
        }

        .payments-table th,
        .payments-table td {
            border: 1px solid #d7e1ec;
            padding: 7px 8px;
            vertical-align: top;
        }

        .payments-table th {
            background: #F0F6FB;
            color: #12263F;
            font-size: 9px;
            font-weight: 800;
            text-align: left;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .payments-table td {
            color: #24384f;
            font-size: 9.6px;
            white-space: normal;
            word-break: normal;
            overflow-wrap: break-word;
        }

        .payments-table th:first-child {
            width: 18%;
        }

        .payments-table th:nth-child(2) {
            width: 16%;
        }

        .payments-table th:nth-child(3) {
            width: 46%;
        }

        .payments-table th:last-child {
            width: 20%;
        }

        .totals-table td {
            border: 1px solid #d7e1ec;
            padding: 7px 8px;
            color: #24384f;
            font-size: 10px;
        }

        .totals-table td:last-child {
            text-align: right;
            white-space: nowrap;
        }

        .grand-total td {
            background: #EEF3F8;
            color: #12263F;
            font-size: 11.5px;
            font-weight: 800;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .balance-due td {
            background: #EAF8F0;
            color: #12263F;
            font-size: 11.5px;
            font-weight: 800;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .signature-box {
            min-height: 31mm;
            margin-top: 8px;
            text-align: right;
        }

        .signature-box img {
            max-width: 42mm;
            max-height: 18mm;
            object-fit: contain;
            display: block;
            margin-left: auto;
            margin-bottom: 5px;
        }

        .signature-line {
            width: 42mm;
            height: 18mm;
            border-bottom: 1px solid #c2d0de;
            margin-left: auto;
            margin-bottom: 5px;
        }

        .footer-table {
            margin-top: 10px;
        }

        .footer-table td {
            color: #5B6E84;
            font-size: 8.8px;
            vertical-align: top;
        }

        .footer-right {
            text-align: right;
        }
    </style>
</head>
<body>
@php
    $organization = $invoice->organization;
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

    $statusClass = match ($invoice->payment_status) {
        'paid' => 'status-paid',
        'partial' => 'status-partial',
        default => in_array($invoice->status, ['draft', 'cancelled'], true) ? 'status-draft' : 'status-unpaid',
    };

    $statusLabel = $invoice->payment_status === 'partial'
        ? 'PARTIALLY PAID'
        : strtoupper($invoice->payment_status ?: ($invoice->status ?: 'draft'));

    $organizationAddress = collect([
        $organization?->address,
        trim(collect([
            $organization?->city,
            $organization?->state,
            $organization?->pincode,
        ])->filter()->implode(', ')),
        $organization?->country,
    ])->filter()->implode(', ');

    $billingAddress = collect([
        $invoice->bill_to_address,
        trim(collect([
            $invoice->bill_to_city,
            $invoice->bill_to_state,
            $invoice->bill_to_state_code ? '(' . $invoice->bill_to_state_code . ')' : null,
            $invoice->bill_to_pincode,
        ])->filter()->implode(' ')),
    ])->filter()->implode(', ');

    $shippingAddress = collect([
        $invoice->ship_to_address,
        trim(collect([
            $invoice->ship_to_city,
            $invoice->ship_to_state,
            $invoice->ship_to_state_code ? '(' . $invoice->ship_to_state_code . ')' : null,
            $invoice->ship_to_pincode,
        ])->filter()->implode(' ')),
    ])->filter()->implode(', ');

    $shipMatchesBilling = trim(strtolower(implode('|', [
        $invoice->bill_to_name ?: ($invoice->customer->name ?? ''),
        $invoice->bill_to_phone ?: '',
        $billingAddress,
    ]))) === trim(strtolower(implode('|', [
        $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? '')),
        $invoice->ship_to_phone ?: ($invoice->bill_to_phone ?: ''),
        $shippingAddress ?: $billingAddress,
    ])));

    $showTaxColumns = (float) $invoice->total_tax_amount > 0;
    $showGstSplit = $showTaxColumns && $invoice->tax_type === 'cgst_sgst';
    $showIgst = $showTaxColumns && $invoice->tax_type === 'igst';
    $showDiscount = (float) $invoice->discount_amount > 0;
    $showShipping = (float) $invoice->shipping_charges > 0;
    $showDeposit = (float) $invoice->deposit_amount > 0;
    $otherCharges = (float) ($invoice->other_charges ?? 0);
    $showOtherCharges = $otherCharges > 0;
    $billingTerms = $invoice->terms_conditions ?: ($organization?->default_terms ?: null);
    $paymentTermsLabel = $invoice->due_date && $invoice->invoice_date
        ? max(optional($invoice->invoice_date)->diffInDays($invoice->due_date, false), 0) . ' day terms'
        : ($billingTerms ? 'As agreed' : 'Due on receipt');
    $termsList = collect(preg_split('/\r\n|\r|\n/', (string) $billingTerms))
        ->map(fn ($line) => preg_replace('/^[\-\x{2022}\s]+/u', '', trim((string) $line)))
        ->filter()
        ->values();
    $displayRentalPeriod = $invoice->inferredRentalPeriod();

    $toDataUri = function (?string $relativePath): ?string {
        if (!$relativePath) {
            return null;
        }

        $absolutePath = public_path('storage/' . ltrim($relativePath, '/'));

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'image/png';
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        return 'data:' . ($mime ?: 'image/png') . ';base64,' . base64_encode($contents);
    };

    $tenantLogo = $toDataUri($organization?->logo);
    $tenantQr = $toDataUri($organization?->payment_qr_code);
    $tenantSignature = $toDataUri($organization?->digital_signature);
    $organizationInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $organization?->name ?? 'OR'), 0, 2));
@endphp

<div class="invoice-page">
    <table class="header-table">
        <tr>
            <td class="header-logo-cell">
                <div class="header-logo-box">
                    @if($tenantLogo)
                        <img src="{{ $tenantLogo }}" alt="Company Logo">
                    @else
                        <div class="header-logo-fallback">{{ $organizationInitials ?: 'CO' }}</div>
                    @endif
                </div>
            </td>
            <td class="header-company-cell">
                <h1 class="header-company-name">{{ $organization?->name ?: 'Company' }}</h1>
                @if($organizationAddress)
                    <p class="header-company-line">{{ $organizationAddress }}</p>
                @endif
                @if($organization?->phone || $organization?->email)
                    <p class="header-company-line">
                        @if($organization?->phone)
                            Phone: {{ $organization->phone }}
                        @endif
                        @if($organization?->phone && $organization?->email)
                            &nbsp;|&nbsp;
                        @endif
                        @if($organization?->email)
                            Email: {{ $organization->email }}
                        @endif
                    </p>
                @endif
                @if($organization?->gst_number)
                    <p class="header-company-line">GSTIN: {{ $organization->gst_number }}</p>
                @endif
            </td>
            <td class="header-title-cell">
                <h2 class="header-invoice-title">{{ strtoupper($invoiceTitle) }}</h2>
                <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td><span class="meta-label">Invoice #</span><span class="meta-value">{{ $invoice->invoice_number }}</span></td>
            <td><span class="meta-label">Date</span><span class="meta-value">{{ optional($invoice->invoice_date)->format('d M Y') ?: 'N/A' }}</span></td>
            <td><span class="meta-label">Due Date</span><span class="meta-value">{{ optional($invoice->due_date)->format('d M Y') ?: 'N/A' }}</span></td>
        </tr>
        <tr>
            <td><span class="meta-label">Terms</span><span class="meta-value">{{ $paymentTermsLabel }}</span></td>
            <td><span class="meta-label">Place of Supply</span><span class="meta-value">{{ $invoice->place_of_supply_state ?: 'N/A' }}</span></td>
            <td><span class="meta-label">GST Type</span><span class="meta-value">{{ $showGstSplit ? 'CGST + SGST' : ($showIgst ? 'IGST' : 'No Tax') }}</span></td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td>
                <h3 class="section-title">Bill To</h3>
                <p class="party-line"><strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer') }}</strong></p>
                @if($invoice->bill_to_phone)
                    <p class="party-line">{{ $invoice->bill_to_phone }}</p>
                @endif
                @if($invoice->bill_to_email)
                    <p class="party-line">{{ $invoice->bill_to_email }}</p>
                @endif
                <p class="party-line">{{ $billingAddress ?: 'Address not provided' }}</p>
                @if($invoice->bill_to_gstin)
                    <p class="party-line">GSTIN: {{ $invoice->bill_to_gstin }}</p>
                @endif
            </td>
            <td>
                <h3 class="section-title">Ship To</h3>
                @if($shipMatchesBilling)
                    <p class="party-line"><strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer')) }}</strong></p>
                    <p class="party-line">Same as billing address</p>
                    <p class="party-line">Place of Supply: {{ $invoice->place_of_supply_state ?: 'N/A' }}</p>
                @else
                    <p class="party-line"><strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: 'Delivery Address') }}</strong></p>
                    @if($invoice->ship_to_phone)
                        <p class="party-line">{{ $invoice->ship_to_phone }}</p>
                    @endif
                    <p class="party-line">{{ $shippingAddress ?: 'Address not provided' }}</p>
                    <p class="party-line">Place of Supply: {{ $invoice->place_of_supply_state ?: 'N/A' }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="subject-row"><strong>Subject:</strong> {{ $subjectLine }}</div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th style="width:35%;">Item &amp; Description</th>
                <th style="width:10%;">HSN/SAC</th>
                <th style="width:7%;" class="num">Qty</th>
                <th style="width:11%;" class="num">Rate</th>
                @if($showDiscount)
                    <th style="width:9%;" class="num">Discount</th>
                @endif
                @if($showTaxColumns)
                    <th style="width:10%;" class="num">Tax</th>
                    <th style="width:10%;" class="num">Tax Amt</th>
                @endif
                <th style="width:11%;" class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                @php
                    $itemTaxAmount = (float) ($item->cgst_amount ?? 0) + (float) ($item->sgst_amount ?? 0) + (float) ($item->igst_amount ?? 0);
                    $itemMeta = collect([
                        $item->unit ? 'Unit: ' . $item->unit : null,
                        $item->days ? 'Days: ' . number_format((float) $item->days, 2) : null,
                    ]);

                    if ($item->source_type === 'rental' && $displayRentalPeriod && !$invoice->rentalRenewal) {
                        $itemMeta->push(
                            'Rental Period: '
                            . (optional($displayRentalPeriod['start_date'] ?? null)?->format('d M Y') ?: '-')
                            . ' to '
                            . (optional($displayRentalPeriod['end_date'] ?? null)?->format('d M Y') ?: '-')
                        );
                    }

                    if ($invoice->rentalRenewal && ($item->unit === 'renewal' || ($item->product_id && $item->days))) {
                        $itemMeta->push(
                            'Rental Period: '
                            . (optional($invoice->rentalRenewal->previous_end_date)->format('d M Y') ?: '-')
                            . ' to '
                            . (optional($invoice->rentalRenewal->renewed_end_date)->format('d M Y') ?: '-')
                        );
                    }
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <span class="item-title">{{ $item->display_description }}</span>
                        @if($itemMeta->filter()->isNotEmpty())
                            <span class="item-subtext">{{ $itemMeta->filter()->implode(' | ') }}</span>
                        @endif
                    </td>
                    <td>{{ $item->hsn_sac_code ?: '-' }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="num">&#8377;{{ number_format((float) $item->rate, 2) }}</td>
                    @if($showDiscount)
                        <td class="num">&#8377;{{ number_format((float) $item->discount_amount, 2) }}</td>
                    @endif
                    @if($showTaxColumns)
                        <td class="num">
                            @if($showGstSplit)
                                CGST {{ number_format((float) ($item->cgst_rate ?? 0), 2) }}%<br>
                                SGST {{ number_format((float) ($item->sgst_rate ?? 0), 2) }}%
                            @else
                                {{ number_format((float) ($item->igst_rate ?? $item->tax_percentage ?? 0), 2) }}%
                            @endif
                        </td>
                        <td class="num">
                            @if($showGstSplit)
                                CGST &#8377;{{ number_format((float) ($item->cgst_amount ?? 0), 2) }}<br>
                                SGST &#8377;{{ number_format((float) ($item->sgst_amount ?? 0), 2) }}
                            @else
                                &#8377;{{ number_format($itemTaxAmount, 2) }}
                            @endif
                        </td>
                    @endif
                    <td class="num"><strong>&#8377;{{ number_format((float) $item->line_total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="bottom-table">
        <tr>
            <td class="bottom-notes-cell">
                <div class="notes-box">
                    <h3 class="section-title">Amount in Words</h3>
                    <p>{{ $amountInWords ?? 'Amount not available' }}</p>

                    @if($invoice->notes)
                        <h3 class="section-title">Notes</h3>
                        <p>{{ $invoice->notes }}</p>
                    @endif

                    @if($termsList->isNotEmpty())
                        <h3 class="section-title">Terms</h3>
                        <ul>
                            @foreach($termsList as $term)
                                <li>{{ $term }}</li>
                            @endforeach
                        </ul>
                    @elseif($billingTerms)
                        <h3 class="section-title">Terms</h3>
                        <p>{{ $billingTerms }}</p>
                    @endif
                </div>
            </td>
            <td class="bottom-totals-cell">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal</td>
                        <td>&#8377;{{ number_format((float) $invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($showDiscount)
                        <tr>
                            <td>Discount</td>
                            <td>&#8377;{{ number_format((float) $invoice->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td>Tax Total</td>
                        <td>&#8377;{{ number_format((float) $invoice->total_tax_amount, 2) }}</td>
                    </tr>
                    @if($showShipping)
                        <tr>
                            <td>Transport / Shipping</td>
                            <td>&#8377;{{ number_format((float) $invoice->shipping_charges, 2) }}</td>
                        </tr>
                    @endif
                    @if($showDeposit)
                        <tr>
                            <td>Refundable Deposit</td>
                            <td>&#8377;{{ number_format((float) $invoice->deposit_amount, 2) }}</td>
                        </tr>
                    @endif
                    @if($showOtherCharges)
                        <tr>
                            <td>Other Charges</td>
                            <td>&#8377;{{ number_format($otherCharges, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="grand-total">
                        <td>Grand Total</td>
                        <td>&#8377;{{ number_format((float) $invoice->total_amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Payment Received</td>
                        <td>&#8377;{{ number_format((float) $invoice->paid_amount, 2) }}</td>
                    </tr>
                    <tr class="balance-due">
                        <td>Balance Due</td>
                        <td>&#8377;{{ number_format((float) $invoice->balance_amount, 2) }}</td>
                    </tr>
                </table>

                <div class="signature-box">
                    @if($tenantSignature)
                        <img src="{{ $tenantSignature }}" alt="Authorized Signature">
                    @else
                        <div class="signature-line"></div>
                    @endif
                    <div>Authorized Signature</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="payment-card">
        <h3 class="section-title">Payment Details</h3>
        <table class="payment-details-table">
            <tr>
                <td>
                    @if($organization?->bank_account_name || $organization?->bank_account_number || $organization?->bank_ifsc || $organization?->bank_name || $organization?->bank_branch || $organization?->upi_id)
                        @if($organization?->bank_account_name)
                            <p class="payment-bank-line">A/C Name: {{ $organization->bank_account_name }}</p>
                        @endif
                        @if($organization?->bank_account_number)
                            <p class="payment-bank-line">A/C No: {{ $organization->bank_account_number }}</p>
                        @endif
                        @if($organization?->bank_ifsc)
                            <p class="payment-bank-line">IFSC: {{ $organization->bank_ifsc }}</p>
                        @endif
                        @if($organization?->bank_name)
                            <p class="payment-bank-line">Bank: {{ $organization->bank_name }}</p>
                        @endif
                        @if($organization?->bank_branch)
                            <p class="payment-bank-line">Branch: {{ $organization->bank_branch }}</p>
                        @endif
                        @if($organization?->upi_id)
                            <p class="payment-bank-line">UPI ID: {{ $organization->upi_id }}</p>
                        @endif
                    @else
                        <p class="payment-bank-line">Bank details are not configured for this company.</p>
                    @endif
                </td>
                <td class="payment-qr-col">
                    @if($tenantQr)
                        <img src="{{ $tenantQr }}" alt="Payment QR Code" class="qr-image">
                        <div class="payment-qr-caption">Scan to pay</div>
                    @else
                        <div class="payment-qr-caption">No payment QR configured</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if($invoice->payments->isNotEmpty())
        <table class="payments-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Notes</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->payments as $payment)
                    <tr>
                        <td>{{ optional($payment->payment_date)->format('d M Y') ?: 'N/A' }}</td>
                        <td>{{ strtoupper($payment->payment_method ?: 'other') }}</td>
                        <td>{{ $payment->notes ?: '-' }}</td>
                        <td class="num">&#8377;{{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="footer-table">
        <tr>
            <td>Generated digitally by {{ $organization?->name ?: 'Organization' }}</td>
            <td class="footer-right"></td>
        </tr>
    </table>
</div>
</body>
</html>
