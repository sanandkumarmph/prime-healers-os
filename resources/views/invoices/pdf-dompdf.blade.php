<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }

        body {
            margin: 0;
            color: #24384f;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }

        table,
        td,
        th,
        div,
        span,
        p,
        strong {
            font-family: inherit;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table,
        .meta-table,
        .party-table,
        .items-table,
        .totals-table,
        .payments-table,
        .footer-table {
            margin-bottom: 10px;
        }

        .header-table td,
        .meta-table td,
        .party-table td,
        .items-table td,
        .items-table th,
        .totals-table td,
        .payments-table td,
        .payments-table th {
            border: 1px solid #d7e1ec;
            padding: 6px 7px;
            vertical-align: top;
        }

        .header-table td,
        .party-table td {
            border: 0;
            padding: 0 0 8px;
        }

        .company-logo {
            width: 92px;
            height: 62px;
            border: 1px solid #d7e1ec;
            text-align: center;
            vertical-align: middle;
        }

        .company-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #12263F;
            margin-bottom: 5px;
        }

        .invoice-title {
            font-size: 22px;
            font-weight: bold;
            text-transform: uppercase;
            text-align: right;
            color: #12263F;
        }

        .status-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 4px 10px;
            border: 1px solid #c2d0de;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #12263F;
            background: #EEF3F8;
        }

        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #5B6E84;
            margin-bottom: 4px;
        }

        .meta-label {
            display: block;
            color: #5B6E84;
            font-size: 9px;
            font-weight: bold;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .meta-value {
            display: block;
            color: #12263F;
            font-size: 11px;
            font-weight: bold;
        }

        .subject-box,
        .notes-box,
        .signature-box {
            border: 1px solid #d7e1ec;
            padding: 8px;
            margin-bottom: 10px;
        }

        .subject-box {
            background: #F8FBFE;
        }

        .items-table th,
        .payments-table th {
            background: #F0F6FB;
            color: #12263F;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .num {
            text-align: right;
            white-space: nowrap;
        }

        .item-title {
            font-weight: bold;
            color: #12263F;
        }

        .item-subtext {
            margin-top: 3px;
            color: #5B6E84;
            font-size: 9px;
        }

        .totals-table td:first-child,
        .payments-table td:first-child {
            width: 60%;
        }

        .grand-total td,
        .balance-due td {
            font-weight: bold;
            color: #12263F;
            background: #EEF3F8;
        }

        .signature-box {
            text-align: right;
            min-height: 90px;
        }

        .signature-box img {
            max-width: 140px;
            max-height: 58px;
            display: block;
            margin-left: auto;
            margin-bottom: 8px;
        }

        .signature-line {
            width: 140px;
            height: 46px;
            border-bottom: 1px solid #c2d0de;
            margin-left: auto;
            margin-bottom: 8px;
        }

        .qr-image {
            width: 110px;
            height: 110px;
            display: block;
            margin-top: 8px;
            margin-left: auto;
        }

        .footer-table td {
            border: 0;
            color: #5B6E84;
            font-size: 9px;
            padding: 0;
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
    $currency = trim((string) ($pdfCurrencySymbol ?: ($pdfCurrencyFallback ?: '₹')));
    $currencyHtml = $currency === '₹' ? '&#8377;' : e($currency);

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

<table class="header-table">
    <tr>
        <td style="width:100px; padding-right:10px;">
            <div class="company-logo">
                @if($tenantLogo)
                    <img src="{{ $tenantLogo }}" alt="Company Logo">
                @else
                    <div style="padding-top:18px; font-size:12px; font-weight:bold;">{{ $organizationInitials ?: 'CO' }}</div>
                @endif
            </div>
        </td>
        <td style="padding-right:10px;">
            <div class="company-name">{{ $organization?->name ?: 'Company' }}</div>
            @if($organizationAddress)
                <div>{{ $organizationAddress }}</div>
            @endif
            @if($organization?->phone || $organization?->email)
                <div>
                    @if($organization?->phone)
                        Phone: {{ $organization->phone }}
                    @endif
                    @if($organization?->phone && $organization?->email)
                        |
                    @endif
                    @if($organization?->email)
                        Email: {{ $organization->email }}
                    @endif
                </div>
            @endif
            @if($organization?->gst_number)
                <div>GSTIN: {{ $organization->gst_number }}</div>
            @endif
        </td>
        <td style="width:190px; text-align:right;">
            <div class="invoice-title">{{ strtoupper($invoiceTitle) }}</div>
            <div class="status-badge">{{ $statusLabel }}</div>
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
        <td><span class="meta-label">GST Type</span><span class="meta-value">{{ $showGstSplit ? 'CGST + SGST' : ($showTaxColumns ? 'IGST' : 'No Tax') }}</span></td>
    </tr>
</table>

<table class="party-table">
    <tr>
        <td style="width:50%; padding-right:6px;">
            <div class="section-title">Bill To</div>
            <div><strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer') }}</strong></div>
            @if($invoice->bill_to_phone)
                <div>{{ $invoice->bill_to_phone }}</div>
            @endif
            @if($invoice->bill_to_email)
                <div>{{ $invoice->bill_to_email }}</div>
            @endif
            <div>{{ $billingAddress ?: 'Address not provided' }}</div>
            @if($invoice->bill_to_gstin)
                <div>GSTIN: {{ $invoice->bill_to_gstin }}</div>
            @endif
        </td>
        <td style="width:50%; padding-left:6px;">
            <div class="section-title">Ship To</div>
            @if($shipMatchesBilling)
                <div><strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer')) }}</strong></div>
                <div>Same as billing address</div>
                <div>Place of Supply: {{ $invoice->place_of_supply_state ?: 'N/A' }}</div>
            @else
                <div><strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: 'Delivery Address') }}</strong></div>
                @if($invoice->ship_to_phone)
                    <div>{{ $invoice->ship_to_phone }}</div>
                @endif
                <div>{{ $shippingAddress ?: 'Address not provided' }}</div>
                <div>Place of Supply: {{ $invoice->place_of_supply_state ?: 'N/A' }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="subject-box"><strong>Subject:</strong> {{ $subjectLine }}</div>

<table class="items-table">
    <thead>
        <tr>
            <th style="width:4%;">#</th>
            <th style="width:34%;">Item &amp; Description</th>
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
                    <div class="item-title">{{ $item->display_description }}</div>
                    @if($itemMeta->filter()->isNotEmpty())
                        <div class="item-subtext">{{ $itemMeta->filter()->implode(' | ') }}</div>
                    @endif
                </td>
                <td>{{ $item->hsn_sac_code ?: '-' }}</td>
                <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                <td class="num">{!! $currencyHtml !!} {{ number_format((float) $item->rate, 2) }}</td>
                @if($showDiscount)
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $item->discount_amount, 2) }}</td>
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
                            CGST {!! $currencyHtml !!} {{ number_format((float) ($item->cgst_amount ?? 0), 2) }}<br>
                            SGST {!! $currencyHtml !!} {{ number_format((float) ($item->sgst_amount ?? 0), 2) }}
                        @else
                            {!! $currencyHtml !!} {{ number_format($itemTaxAmount, 2) }}
                        @endif
                    </td>
                @endif
                <td class="num"><strong>{!! $currencyHtml !!} {{ number_format((float) $item->line_total, 2) }}</strong></td>
            </tr>
        @endforeach
    </tbody>
</table>

<table>
    <tr>
        <td style="width:60%; padding-right:8px; vertical-align:top;">
            <div class="notes-box">
                <div class="section-title">Amount in Words</div>
                <div>{{ $amountInWords ?? 'Amount not available' }}</div>

                @if($invoice->notes)
                    <div class="section-title" style="margin-top:10px;">Notes</div>
                    <div>{{ $invoice->notes }}</div>
                @endif

                @if($termsList->isNotEmpty())
                    <div class="section-title" style="margin-top:10px;">Terms</div>
                    @foreach($termsList as $term)
                        <div>- {{ $term }}</div>
                    @endforeach
                @elseif($billingTerms)
                    <div class="section-title" style="margin-top:10px;">Terms</div>
                    <div>{{ $billingTerms }}</div>
                @endif

                <div class="section-title" style="margin-top:10px;">Bank Details</div>
                @if($organization?->bank_account_name || $organization?->bank_account_number || $organization?->bank_ifsc || $organization?->bank_name || $organization?->bank_branch || $organization?->upi_id)
                    @if($organization?->bank_account_name)
                        <div>A/C Name: {{ $organization->bank_account_name }}</div>
                    @endif
                    @if($organization?->bank_account_number)
                        <div>A/C No: {{ $organization->bank_account_number }}</div>
                    @endif
                    @if($organization?->bank_ifsc)
                        <div>IFSC: {{ $organization->bank_ifsc }}</div>
                    @endif
                    @if($organization?->bank_name)
                        <div>Bank: {{ $organization->bank_name }}</div>
                    @endif
                    @if($organization?->bank_branch)
                        <div>Branch: {{ $organization->bank_branch }}</div>
                    @endif
                    @if($organization?->upi_id)
                        <div>UPI ID: {{ $organization->upi_id }}</div>
                    @endif
                @else
                    <div>Bank details are not configured for this company.</div>
                @endif

                @if($tenantQr)
                    <img src="{{ $tenantQr }}" alt="Payment QR Code" class="qr-image">
                @endif
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
                                <td class="num">{!! $currencyHtml !!} {{ number_format((float) $payment->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        <td style="width:40%; vertical-align:top;">
            <table class="totals-table">
                <tr>
                    <td>Subtotal</td>
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->subtotal, 2) }}</td>
                </tr>
                @if($showDiscount)
                    <tr>
                        <td>Discount</td>
                        <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->discount_amount, 2) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Tax Total</td>
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->total_tax_amount, 2) }}</td>
                </tr>
                @if($showShipping)
                    <tr>
                        <td>Transport / Shipping</td>
                        <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->shipping_charges, 2) }}</td>
                    </tr>
                @endif
                @if($showDeposit)
                    <tr>
                        <td>Refundable Deposit</td>
                        <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->deposit_amount, 2) }}</td>
                    </tr>
                @endif
                @if($showOtherCharges)
                    <tr>
                        <td>Other Charges</td>
                        <td class="num">{!! $currencyHtml !!} {{ number_format($otherCharges, 2) }}</td>
                    </tr>
                @endif
                <tr class="grand-total">
                    <td>Grand Total</td>
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                </tr>
                <tr>
                    <td>Payment Received</td>
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->paid_amount, 2) }}</td>
                </tr>
                <tr class="balance-due">
                    <td>Balance Due</td>
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->balance_amount, 2) }}</td>
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

<table class="footer-table">
    <tr>
        <td>Generated digitally by {{ $organization?->name ?: 'Organization' }}</td>
        <td class="footer-right"></td>
    </tr>
</table>
</body>
</html>
