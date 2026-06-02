<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bulk Invoices</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef3f8;
            color: #0f172a;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
        }

        .bulk-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 14px 22px;
            background: rgba(255, 255, 255, 0.95);
            border-bottom: 1px solid #dbe4ee;
            backdrop-filter: blur(10px);
        }

        .bulk-toolbar h1 {
            margin: 0;
            font-size: 18px;
        }

        .bulk-toolbar p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 12px;
        }

        .bulk-toolbar button {
            border: 0;
            border-radius: 10px;
            background: #0f172a;
            color: #ffffff;
            cursor: pointer;
            font-weight: 800;
            padding: 10px 14px;
        }

        .bulk-shell {
            display: grid;
            gap: 22px;
            padding: 22px;
        }

        .bulk-invoice-page {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }

        .bulk-invoice-page:not(:last-child) {
            page-break-after: always;
        }

        .bulk-invoice-sheet {
            width: 100%;
            max-width: 100%;
            overflow: hidden;
            border: 1px solid #dbe4ee;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
        }

        .bulk-invoice-header {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 20px 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        .bulk-brand {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            min-width: 0;
            flex: 1 1 auto;
        }

        .bulk-logo {
            width: auto;
            max-width: 95px;
            max-height: 45px;
            flex: 0 0 auto;
        }

        .bulk-company h2 {
            margin: 0 0 4px;
            font-size: 15px;
            line-height: 1.2;
        }

        .bulk-company p,
        .bulk-meta-note,
        .bulk-item-subtext,
        .bulk-amount-words {
            margin: 0;
            color: #64748b;
        }

        .bulk-company p {
            margin-top: 2px;
        }

        .bulk-invoice-meta {
            width: 240px;
            max-width: 100%;
            text-align: right;
            flex: 0 0 auto;
        }

        .bulk-invoice-title {
            margin: 0 0 8px;
            font-size: 20px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .bulk-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid #cbd5e1;
            color: #334155;
            background: #f8fafc;
        }

        .bulk-status.bulk-status-paid {
            color: #166534;
            background: #dcfce7;
            border-color: #bbf7d0;
        }

        .bulk-status.bulk-status-partial {
            color: #9a3412;
            background: #ffedd5;
            border-color: #fed7aa;
        }

        .bulk-status.bulk-status-overdue,
        .bulk-status.bulk-status-unpaid,
        .bulk-status.bulk-status-draft {
            color: #991b1b;
            background: #fee2e2;
            border-color: #fecaca;
        }

        .bulk-status.bulk-status-cancelled {
            color: #475569;
            background: #e2e8f0;
            border-color: #cbd5e1;
        }

        .bulk-meta-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            border-bottom: 1px solid #e2e8f0;
        }

        .bulk-meta-grid td {
            width: 50%;
            padding: 12px 16px;
            border-right: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .bulk-meta-grid tr td:last-child {
            border-right: 0;
        }

        .bulk-label {
            display: block;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .bulk-value {
            display: block;
            font-size: 13px;
            font-weight: 700;
            word-break: break-word;
        }

        .bulk-body {
            padding: 16px 20px 20px;
        }

        .bulk-party-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .bulk-party-card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            min-width: 0;
        }

        .bulk-party-card h3 {
            margin: 0 0 8px;
            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .bulk-party-card strong {
            display: block;
            margin-bottom: 4px;
            font-size: 12px;
        }

        .bulk-subject {
            margin: 0 0 12px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            font-size: 11px;
        }

        .bulk-items {
            width: 100%;
            max-width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .bulk-items th,
        .bulk-items td {
            border: 1px solid #e2e8f0;
            padding: 8px 9px;
            vertical-align: top;
        }

        .bulk-items th {
            background: #f8fafc;
            color: #475569;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: left;
        }

        .bulk-items th:nth-child(1),
        .bulk-items td:nth-child(1) {
            width: 5%;
            text-align: center;
        }

        .bulk-items th:nth-child(2),
        .bulk-items td:nth-child(2) {
            width: 45%;
        }

        .bulk-items th:nth-child(3),
        .bulk-items td:nth-child(3) {
            width: 8%;
        }

        .bulk-items th:nth-child(4),
        .bulk-items td:nth-child(4) {
            width: 14%;
        }

        .bulk-items th:nth-child(5),
        .bulk-items td:nth-child(5) {
            width: 13%;
        }

        .bulk-items th:nth-child(6),
        .bulk-items td:nth-child(6) {
            width: 15%;
        }

        .bulk-items td {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .bulk-item-title {
            display: block;
            font-weight: 700;
            color: #0f172a;
        }

        .bulk-item-subtext {
            display: block;
            margin-top: 3px;
            font-size: 9px;
            line-height: 1.45;
        }

        .bulk-num {
            text-align: right;
            white-space: nowrap;
        }

        .bulk-summary-grid {
            display: table;
            width: 100%;
            margin-top: 16px;
        }

        .bulk-summary-left,
        .bulk-summary-right {
            display: table-cell;
            vertical-align: top;
        }

        .bulk-summary-left {
            width: 58%;
            padding-right: 16px;
        }

        .bulk-summary-right {
            width: 42%;
        }

        .bulk-summary-card {
            width: 100%;
            margin-left: auto;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
        }

        .bulk-summary-table {
            width: 100%;
            border-collapse: collapse;
        }

        .bulk-summary-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #eef2f7;
            font-size: 10px;
        }

        .bulk-summary-table tr:last-child td {
            border-bottom: 0;
        }

        .bulk-summary-table td:last-child {
            text-align: right;
            white-space: nowrap;
            font-weight: 700;
        }

        .bulk-summary-table .bulk-grand-total td {
            background: #f8fafc;
            font-size: 12px;
            font-weight: 800;
        }

        .bulk-signature {
            margin-top: 14px;
            text-align: right;
            color: #475569;
            font-size: 10px;
        }

        .bulk-signature strong {
            display: block;
            color: #0f172a;
            margin-top: 24px;
            padding-top: 6px;
            border-top: 1px solid #cbd5e1;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .bulk-toolbar {
                display: none;
            }

            .bulk-shell {
                padding: 0;
                gap: 0;
            }

            .bulk-invoice-page {
                max-width: none;
            }

            .bulk-invoice-sheet {
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }
        }

        @media (max-width: 760px) {
            .bulk-toolbar,
            .bulk-invoice-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .bulk-invoice-meta,
            .bulk-summary-right,
            .bulk-summary-left {
                width: 100%;
            }

            .bulk-meta-grid td,
            .bulk-party-grid {
                display: block;
                width: 100%;
            }

            .bulk-summary-grid,
            .bulk-summary-left,
            .bulk-summary-right {
                display: block;
            }
        }
    </style>
</head>
<body>
    <div class="bulk-toolbar">
        <div>
            <h1>{{ $invoices->count() }} selected invoice{{ $invoices->count() === 1 ? '' : 's' }}</h1>
            <p>Bulk print layout uses a separate compact template and page break between invoices only.</p>
        </div>
        <button type="button" onclick="window.print()">Download / Print PDF</button>
    </div>

    <main class="bulk-shell">
        @foreach($invoices as $invoice)
            @php
                $organization = $invoice->organization;
                $billToAddress = collect([
                    $invoice->bill_to_address,
                    $invoice->bill_to_city,
                    $invoice->bill_to_state,
                    $invoice->bill_to_pincode,
                ])->filter()->implode(', ');
                $shipToAddress = collect([
                    $invoice->ship_to_address,
                    $invoice->ship_to_city,
                    $invoice->ship_to_state,
                    $invoice->ship_to_pincode,
                ])->filter()->implode(', ');
                $statusValue = strtolower((string) ($invoice->payment_status ?: $invoice->status ?: 'draft'));
                $statusLabel = strtoupper($invoice->payment_status ?: $invoice->status ?: 'draft');
                $invoiceHeading = $invoice->linkedRentalId() ? 'Rental Invoice' : 'Tax Invoice';
                $amountInWords = $amountInWordsByInvoiceId[$invoice->id] ?? null;
                $shippingLabel = $invoice->deposit_amount > 0 ? 'Transportation / Shipping' : 'Shipping';
                $logoUrl = app(\App\Support\InvoicePdfAssetResolver::class)->logoBrowserUrl();
                $lineItems = $invoice->items->values();
            @endphp

            <section class="bulk-invoice-page">
                <article class="bulk-invoice-sheet">
                    <header class="bulk-invoice-header">
                        <div class="bulk-brand">
                            <img src="{{ $logoUrl }}" alt="Prime Healers" class="bulk-logo">
                            <div class="bulk-company">
                                <h2>{{ $organization?->name ?: 'Prime Healers' }}</h2>
                                <p>{{ collect([$organization?->address, $organization?->city, $organization?->state, $organization?->pincode])->filter()->implode(', ') }}</p>
                                <p>
                                    @if($organization?->phone)
                                        Phone: {{ $organization->phone }}
                                    @endif
                                    @if($organization?->email)
                                        @if($organization?->phone) | @endif Email: {{ $organization->email }}
                                    @endif
                                </p>
                                <p>GSTIN: {{ $organization?->gst_number ?: 'N/A' }}</p>
                            </div>
                        </div>

                        <div class="bulk-invoice-meta">
                            <h1 class="bulk-invoice-title">{{ $invoiceHeading }}</h1>
                            <div class="bulk-status bulk-status-{{ $statusValue }}">{{ $statusLabel }}</div>
                            <p class="bulk-meta-note" style="margin-top:10px;">
                                Invoice #: <strong>{{ $invoice->invoice_number }}</strong><br>
                                Date: {{ optional($invoice->invoice_date)->format('d M Y') ?: 'N/A' }}<br>
                                Due: {{ optional($invoice->due_date)->format('d M Y') ?: 'N/A' }}
                            </p>
                        </div>
                    </header>

                    <table class="bulk-meta-grid" aria-hidden="true">
                        <tr>
                            <td>
                                <span class="bulk-label">Place of Supply</span>
                                <span class="bulk-value">{{ $invoice->place_of_supply_state ?: ($invoice->bill_to_state ?: 'N/A') }}</span>
                            </td>
                            <td>
                                <span class="bulk-label">GST Type</span>
                                <span class="bulk-value">{{ strtoupper(str_replace('_', ' + ', (string) ($invoice->tax_type ?: 'no_tax'))) }}</span>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <span class="bulk-label">Terms</span>
                                <span class="bulk-value">{{ $invoice->terms_conditions ?: 'Due on receipt' }}</span>
                            </td>
                            <td>
                                <span class="bulk-label">Reference</span>
                                <span class="bulk-value">{{ $invoice->reference_number ?: ($invoice->purchase_order_number ?: 'N/A') }}</span>
                            </td>
                        </tr>
                    </table>

                    <div class="bulk-body">
                        <div class="bulk-party-grid">
                            <div class="bulk-party-card">
                                <h3>Bill To</h3>
                                <strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'N/A') }}</strong>
                                <p class="bulk-meta-note">{{ $invoice->bill_to_phone ?: ($invoice->customer->phone ?? 'N/A') }}</p>
                                @if($invoice->bill_to_email || $invoice->customer?->email)
                                    <p class="bulk-meta-note">{{ $invoice->bill_to_email ?: $invoice->customer?->email }}</p>
                                @endif
                                <p class="bulk-meta-note">{{ $billToAddress ?: 'Address not provided' }}</p>
                                @if($invoice->bill_to_gstin || $invoice->customer?->gst_number)
                                    <p class="bulk-meta-note">GSTIN: {{ $invoice->bill_to_gstin ?: $invoice->customer?->gst_number }}</p>
                                @endif
                            </div>

                            <div class="bulk-party-card">
                                <h3>Ship To</h3>
                                <strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? 'N/A')) }}</strong>
                                @if($invoice->ship_to_phone)
                                    <p class="bulk-meta-note">{{ $invoice->ship_to_phone }}</p>
                                @endif
                                <p class="bulk-meta-note">{{ $shipToAddress ?: 'Same as billing address' }}</p>
                                <p class="bulk-meta-note">
                                    GST Mode: {{ ucfirst((string) ($invoice->tax_calculation_mode ?: 'exclusive')) }}
                                </p>
                            </div>
                        </div>

                        <div class="bulk-subject">
                            <strong>Subject:</strong>
                            {{ $invoice->linkedRentalId() ? 'Rental Charges' : 'Invoice Charges' }}
                        </div>

                        <table class="bulk-items">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Item &amp; Description</th>
                                    <th class="bulk-num">Qty</th>
                                    <th class="bulk-num">Rate</th>
                                    <th class="bulk-num">Tax</th>
                                    <th class="bulk-num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($lineItems as $lineIndex => $item)
                                    @php
                                        $itemMeta = collect();

                                        if ($item->source_type === 'rental_sale') {
                                            $itemMeta->push('Products Sold With Rental');
                                        }

                                        if ($item->hsn_sac_code) {
                                            $itemMeta->push('HSN/SAC: ' . $item->hsn_sac_code);
                                        }

                                        if ((float) $item->discount_amount > 0) {
                                            $itemMeta->push('Discount: ' . $pdfCurrencySymbol . number_format((float) $item->discount_amount, 2));
                                        }

                                        if ($item->unit) {
                                            $itemMeta->push('Unit: ' . $item->unit);
                                        }

                                        if ($item->source_type === 'rental' && $invoice->rental) {
                                            $itemMeta->push('Days: ' . number_format((float) $invoice->rental->baseDurationDays(), 2));
                                        } elseif ((float) $item->days > 0) {
                                            $itemMeta->push('Days: ' . number_format((float) $item->days, 2));
                                        }

                                        $taxDisplay = (float) $item->tax_percentage > 0
                                            ? rtrim(rtrim(number_format((float) $item->tax_percentage, 2), '0'), '.') . '%'
                                            : 'No Tax';

                                        $taxAmount = (float) $item->cgst_amount + (float) $item->sgst_amount + (float) $item->igst_amount;
                                    @endphp
                                    <tr>
                                        <td>{{ $lineIndex + 1 }}</td>
                                        <td>
                                            <span class="bulk-item-title">{{ $item->display_description }}</span>
                                            @if($itemMeta->isNotEmpty())
                                                <span class="bulk-item-subtext">{{ $itemMeta->implode(' | ') }}</span>
                                            @endif
                                        </td>
                                        <td class="bulk-num">{{ number_format((float) $item->quantity, 2) }}</td>
                                        <td class="bulk-num">{{ $pdfCurrencySymbol }}{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="bulk-num">
                                            {{ $taxDisplay }}
                                            @if($taxAmount > 0)
                                                <span class="bulk-item-subtext">{{ $pdfCurrencySymbol }}{{ number_format($taxAmount, 2) }}</span>
                                            @endif
                                        </td>
                                        <td class="bulk-num">{{ $pdfCurrencySymbol }}{{ number_format((float) $item->line_total, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" style="text-align:center;color:#64748b;">No invoice items found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        <div class="bulk-summary-grid">
                            <div class="bulk-summary-left">
                                <div class="bulk-party-card" style="height:100%;">
                                    <h3>Amount in Words</h3>
                                    <p class="bulk-amount-words">{{ $amountInWords ?: 'N/A' }}</p>
                                    @if($invoice->notes)
                                        <h3 style="margin-top:14px;">Notes</h3>
                                        <p class="bulk-amount-words">{{ $invoice->notes }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="bulk-summary-right">
                                <div class="bulk-summary-card">
                                    <table class="bulk-summary-table">
                                        <tr>
                                            <td>Subtotal</td>
                                            <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->subtotal, 2) }}</td>
                                        </tr>
                                        @if((float) $invoice->discount_amount > 0)
                                            <tr>
                                                <td>Discount</td>
                                                <td>-{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->discount_amount, 2) }}</td>
                                            </tr>
                                        @endif
                                        @if((float) $invoice->deposit_amount > 0)
                                            <tr>
                                                <td>Deposit</td>
                                                <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->deposit_amount, 2) }}</td>
                                            </tr>
                                        @endif
                                        @if((float) $invoice->shipping_charges > 0)
                                            <tr>
                                                <td>{{ $shippingLabel }}</td>
                                                <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->shipping_charges, 2) }}</td>
                                            </tr>
                                        @endif
                                        @if((float) $invoice->cgst_amount > 0)
                                            <tr>
                                                <td>CGST</td>
                                                <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->cgst_amount, 2) }}</td>
                                            </tr>
                                        @endif
                                        @if((float) $invoice->sgst_amount > 0)
                                            <tr>
                                                <td>SGST</td>
                                                <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->sgst_amount, 2) }}</td>
                                            </tr>
                                        @endif
                                        @if((float) $invoice->igst_amount > 0)
                                            <tr>
                                                <td>IGST</td>
                                                <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->igst_amount, 2) }}</td>
                                            </tr>
                                        @endif
                                        <tr class="bulk-grand-total">
                                            <td>Grand Total</td>
                                            <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->total_amount, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td>Paid</td>
                                            <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->paid_amount, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td>Balance</td>
                                            <td>{{ $pdfCurrencySymbol }}{{ number_format((float) $invoice->balance_amount, 2) }}</td>
                                        </tr>
                                    </table>
                                </div>

                                <div class="bulk-signature">
                                    For {{ $organization?->name ?: 'Prime Healers' }}
                                    <strong>Authorized Signature</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            </section>
        @endforeach
    </main>
</body>
</html>
