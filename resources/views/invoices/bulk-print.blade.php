<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bulk Invoices</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef3f8;
            color: #0f172a;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
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
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid #dbe4ee;
            backdrop-filter: blur(10px);
        }
        .bulk-toolbar h1 {
            margin: 0;
            font-size: 18px;
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
            gap: 24px;
            padding: 24px;
        }
        .invoice-sheet {
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
            overflow: hidden;
            background: #ffffff;
            border: 1px solid #dbe4ee;
            border-radius: 20px;
            box-shadow: 0 20px 48px rgba(15,23,42,.08);
            page-break-after: always;
        }
        .invoice-head {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            padding: 28px;
            border-bottom: 1px solid #e2e8f0;
        }
        .invoice-head h2 {
            margin: 0 0 8px;
            font-size: 26px;
        }
        .muted {
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #f1f5f9;
            color: #334155;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            padding: 7px 11px;
            text-transform: uppercase;
        }
        .invoice-body {
            padding: 28px;
        }
        .party-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .party-card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
        }
        .party-card h3 {
            margin: 0 0 8px;
            color: #64748b;
            font-size: 11px;
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            padding: 10px;
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            letter-spacing: .08em;
            text-align: left;
            text-transform: uppercase;
        }
        td {
            padding: 11px 10px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            vertical-align: top;
        }
        .right { text-align: right; }
        .summary {
            width: min(360px, 100%);
            margin-left: auto;
            margin-top: 18px;
            display: grid;
            gap: 8px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: #475569;
            font-size: 13px;
        }
        .summary-row.total {
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1px solid #cbd5e1;
            color: #0f172a;
            font-size: 18px;
            font-weight: 900;
        }
        @media print {
            body { background: #ffffff; }
            .bulk-toolbar { display: none; }
            .bulk-shell { padding: 0; gap: 0; }
            .invoice-sheet {
                max-width: none;
                min-height: 100vh;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }
        }
        @media (max-width: 700px) {
            .invoice-head,
            .party-grid {
                grid-template-columns: 1fr;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="bulk-toolbar">
        <h1>{{ $invoices->count() }} selected invoice{{ $invoices->count() === 1 ? '' : 's' }}</h1>
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
                $statusLabel = strtoupper($invoice->payment_status ?: $invoice->status ?: 'draft');
            @endphp
            <section class="invoice-sheet">
                <header class="invoice-head">
                    <div>
                        <h2>Tax Invoice</h2>
                        <div class="muted">
                            <strong>{{ $organization?->name ?: 'Organization' }}</strong><br>
                            {{ collect([$organization?->address, $organization?->city, $organization?->state, $organization?->pincode])->filter()->implode(', ') }}<br>
                            GSTIN: {{ $organization?->gst_number ?: 'N/A' }}
                        </div>
                    </div>
                    <div class="right">
                        <div class="badge">{{ $statusLabel }}</div>
                        <div class="muted" style="margin-top:10px;">
                            Invoice #: <strong>{{ $invoice->invoice_number }}</strong><br>
                            Date: {{ optional($invoice->invoice_date)->format('d M Y') ?: 'N/A' }}<br>
                            Due: {{ optional($invoice->due_date)->format('d M Y') ?: 'N/A' }}
                        </div>
                    </div>
                </header>

                <div class="invoice-body">
                    <div class="party-grid">
                        <div class="party-card">
                            <h3>Bill To</h3>
                            <strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'N/A') }}</strong>
                            <div class="muted">
                                {{ $billToAddress ?: 'Address not provided' }}<br>
                                Phone: {{ $invoice->bill_to_phone ?: 'N/A' }}<br>
                                GSTIN: {{ $invoice->bill_to_gstin ?: ($invoice->customer->gst_number ?? 'N/A') }}
                            </div>
                        </div>
                        <div class="party-card">
                            <h3>Invoice Details</h3>
                            <div class="muted">
                                PO: {{ $invoice->purchase_order_number ?: 'N/A' }}<br>
                                Reference: {{ $invoice->reference_number ?: 'N/A' }}<br>
                                GST Mode: {{ ucfirst($invoice->tax_calculation_mode ?: 'exclusive') }}<br>
                                Tax Type: {{ strtoupper(str_replace('_', ' + ', $invoice->tax_type ?: 'N/A')) }}
                            </div>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>HSN/SAC</th>
                                <th class="right">Qty</th>
                                <th class="right">Rate</th>
                                <th class="right">GST</th>
                                <th class="right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $item)
                                <tr>
                                    <td>
                                        <strong>{{ $item->display_description }}</strong>
                                        <div class="muted">{{ $item->unit ?: 'Unit' }} @if($item->days) | Days: {{ number_format((float) $item->days, 2) }} @endif</div>
                                    </td>
                                    <td>{{ $item->hsn_sac_code ?: '-' }}</td>
                                    <td class="right">{{ number_format((float) $item->quantity, 2) }}</td>
                                    <td class="right">&#8377;{{ number_format((float) $item->rate, 2) }}</td>
                                    <td class="right">&#8377;{{ number_format((float) $item->cgst_amount + (float) $item->sgst_amount + (float) $item->igst_amount, 2) }}</td>
                                    <td class="right">&#8377;{{ number_format((float) $item->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="summary">
                        <div class="summary-row"><span>Subtotal</span><strong>&#8377;{{ number_format($invoice->subtotal, 2) }}</strong></div>
                        <div class="summary-row"><span>Discount</span><strong>&#8377;{{ number_format($invoice->discount_amount, 2) }}</strong></div>
                        <div class="summary-row"><span>Deposit</span><strong>&#8377;{{ number_format($invoice->deposit_amount, 2) }}</strong></div>
                        <div class="summary-row"><span>Transportation / Shipping</span><strong>&#8377;{{ number_format($invoice->shipping_charges, 2) }}</strong></div>
                        <div class="summary-row"><span>CGST</span><strong>&#8377;{{ number_format($invoice->cgst_amount, 2) }}</strong></div>
                        <div class="summary-row"><span>SGST</span><strong>&#8377;{{ number_format($invoice->sgst_amount, 2) }}</strong></div>
                        <div class="summary-row"><span>IGST</span><strong>&#8377;{{ number_format($invoice->igst_amount, 2) }}</strong></div>
                        <div class="summary-row total"><span>Total</span><strong>&#8377;{{ number_format($invoice->total_amount, 2) }}</strong></div>
                        <div class="summary-row"><span>Paid</span><strong>&#8377;{{ number_format($invoice->paid_amount, 2) }}</strong></div>
                        <div class="summary-row"><span>Balance</span><strong>&#8377;{{ number_format($invoice->balance_amount, 2) }}</strong></div>
                    </div>
                </div>
            </section>
        @endforeach
    </main>
</body>
</html>
