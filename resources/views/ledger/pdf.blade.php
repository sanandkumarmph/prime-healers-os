<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 28px 38px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 10px; line-height: 1.34; }
        .page { width: 100%; }
        .muted { color: #64748b; }
        .small { font-size: 8.5px; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .brand-table, .recipient-table, .summary-table, .entries { width: 100%; border-collapse: collapse; }
        .brand-table td { vertical-align: top; padding-bottom: 14px; }
        .brand-mark { max-width: 184px; max-height: 58px; width: auto; height: auto; object-fit: contain; margin-bottom: 5px; }
        .brand-name { margin: 0 0 6px; font-size: 18px; line-height: 1.08; color: #0f172a; }
        .brand-line { margin: 1px 0; color: #334155; }
        .document-title { margin: 0; font-size: 20px; line-height: 1.1; color: #0f172a; text-align: right; }
        .title-rule { width: 126px; height: 2px; margin: 8px 0 14px auto; background: #2563eb; }
        .meta-block { margin-left: auto; width: 230px; text-align: left; }
        .meta-label { margin: 0 0 3px; color: #64748b; font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; }
        .meta-value { margin: 0 0 10px; font-size: 10.5px; color: #0f172a; }
        .recipient-table { margin: 8px 0 18px; }
        .recipient-table td { vertical-align: top; }
        .section-label { margin: 0 0 5px; color: #64748b; font-size: 8.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; }
        .recipient-name { margin: 0 0 5px; font-size: 12px; font-weight: bold; color: #111827; }
        .recipient-line { margin: 1px 0; color: #334155; }
        .account-summary { border: 1px solid #d1d5db; border-radius: 4px; overflow: hidden; }
        .account-summary h2 { margin: 0; padding: 8px 10px; color: #075985; font-size: 10.5px; border-bottom: 1px solid #e5e7eb; }
        .summary-table td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; }
        .summary-table tr:last-child td { border-bottom: 0; }
        .summary-table .total td { background: #f8fafc; color: #075985; font-weight: bold; }
        .entries { table-layout: fixed; }
        .entries thead { display: table-header-group; }
        .entries tr { page-break-inside: avoid; }
        .entries th { padding: 7px 8px; border: 1px solid #1f2937; background: #111827; color: #fff; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .04em; }
        .entries td { padding: 8px; border: 1px solid #d1d5db; vertical-align: top; }
        .entries tbody tr:nth-child(even) td { background: #f8fafc; }
        .transaction-ref { color: #075985; font-weight: bold; }
        .details { color: #334155; }
        .balance-row td { background: #f1f5f9 !important; font-weight: bold; color: #0f172a; }
        .empty { padding: 18px; text-align: center; color: #64748b; }
        .footer { position: fixed; left: 0; right: 0; bottom: -20px; color: #64748b; font-size: 8px; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .page-number:after { content: counter(page) " of " counter(pages); }
        .signature-section { width: 100%; margin-top: 18px; page-break-inside: avoid; border-collapse: collapse; }
        .signature-section td { padding-top: 10px; }
        .signature-box { width: 184px; margin-left: auto; text-align: center; color: #334155; }
        .signature-image { max-width: 150px; max-height: 70px; width: auto; height: auto; object-fit: contain; display: block; margin: 0 auto 6px; }
        .signature-line { border-top: 1px solid #cbd5e1; padding-top: 5px; font-size: 9px; font-weight: bold; color: #0f172a; }
    </style>
</head>
<body>
@php
    $money = fn ($value) => 'Rs. ' . number_format((float) $value, 2);
    $summary = $statement['summary'];
    $recipient = $statement['recipient'] ?? ['name' => $statement['context_label'], 'lines' => []];
    $orgName = $organization?->legal_name ?: ($organization?->name ?: config('app.name', 'Prime Healers OS'));
    $logoDataUri = $logoDataUri ?? null;
    $signatureDataUri = $signatureDataUri ?? null;
    $orgLocation = collect([$organization?->city, $organization?->state, $organization?->pincode])->filter()->join(', ');
    $generatedAt = now()->format('d M Y, h:i A');
@endphp

<div class="page">
    <table class="brand-table">
        <tr>
            <td style="width: 54%;">
                @if($logoDataUri)
                    <img class="brand-mark" src="{{ $logoDataUri }}" alt="{{ $orgName }}">
                @endif
                <h1 class="brand-name">{{ $orgName }}</h1>
                @if($organization?->address)<p class="brand-line">{{ $organization->address }}</p>@endif
                @if($orgLocation !== '')<p class="brand-line">{{ $orgLocation }}</p>@endif
                @if($organization?->gst_number)<p class="brand-line">GSTIN: {{ $organization->gst_number }}</p>@endif
                @if($organization?->phone)<p class="brand-line">Phone: {{ $organization->phone }}</p>@endif
                @if($organization?->email)<p class="brand-line">Email: {{ $organization->email }}</p>@endif
            </td>
            <td style="width: 46%;">
                <h2 class="document-title">Statement of Accounts</h2>
                <div class="title-rule"></div>
                <div class="meta-block">
                    <p class="meta-label">Statement Period</p>
                    <p class="meta-value">{{ $statement['date_label'] }}</p>
                    <p class="meta-label">Generated On</p>
                    <p class="meta-value">{{ $generatedAt }}</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="recipient-table">
        <tr>
            <td style="width: 54%; padding-right: 18px;">
                <p class="section-label">To</p>
                <p class="recipient-name">{{ $recipient['name'] ?? 'Customer' }}</p>
                @foreach(($recipient['lines'] ?? []) as $line)
                    <p class="recipient-line">{{ $line }}</p>
                @endforeach
                @if(!empty($recipient['gstin']))<p class="recipient-line">GSTIN: {{ $recipient['gstin'] }}</p>@endif
                @if(!empty($recipient['phone']))<p class="recipient-line">Phone: {{ $recipient['phone'] }}</p>@endif
                @if(!empty($recipient['email']))<p class="recipient-line">Email: {{ $recipient['email'] }}</p>@endif
            </td>
            <td style="width: 46%;">
                <div class="account-summary">
                    <h2>Account Summary</h2>
                    <table class="summary-table">
                        <tr><td>Opening Balance</td><td class="right nowrap">{{ $money($summary['opening_balance']) }}</td></tr>
                        <tr><td>Invoiced Amount (Debits)</td><td class="right nowrap">{{ $money($summary['total_debit']) }}</td></tr>
                        <tr><td>Amount Received (Credits)</td><td class="right nowrap">{{ $money($summary['total_credit']) }}</td></tr>
                        <tr class="total"><td>Balance Due</td><td class="right nowrap">{{ $money($summary['closing_balance']) }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="entries">
        <thead>
            <tr>
                <th style="width: 12%;">Date</th>
                <th style="width: 19%;">Transaction</th>
                <th style="width: 32%;">Details</th>
                <th style="width: 12%;" class="right">Amount</th>
                <th style="width: 12%;" class="right">Payments</th>
                <th style="width: 13%;" class="right">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $statement['filters']['from_date']->format('d M Y') }}</td>
                <td><strong>Opening Balance</strong></td>
                <td class="details">Opening balance before selected period</td>
                <td class="right nowrap">{{ $money(0) }}</td>
                <td class="right nowrap">&mdash;</td>
                <td class="right nowrap">{{ $money($summary['opening_balance']) }}</td>
            </tr>
            @forelse($statement['entries'] as $entry)
                <tr>
                    <td>{{ $entry['entry_date']->format('d M Y') }}</td>
                    <td>
                        <span class="transaction-ref">{{ $entry['source_label'] }}</span><br>
                        {{ $entry['reference'] }}
                    </td>
                    <td class="details">
                        {{ $entry['particulars'] }}
                        @if(!empty($entry['due_date']))
                            <br><span class="muted small">Due {{ $entry['due_date']->format('d M Y') }}</span>
                        @endif
                    </td>
                    <td class="right nowrap">{{ $entry['debit'] > 0 ? $money($entry['debit']) : '-' }}</td>
                    <td class="right nowrap">{{ $entry['credit'] > 0 ? $money($entry['credit']) : '-' }}</td>
                    <td class="right nowrap">{{ $money($entry['running_balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No ledger entries found for this statement.</td></tr>
            @endforelse
            <tr class="balance-row">
                <td colspan="5" class="right">Balance Due</td>
                <td class="right nowrap">{{ $money($summary['closing_balance']) }}</td>
            </tr>
        </tbody>
    </table>
    @if($signatureDataUri)
        <table class="signature-section">
            <tr>
                <td>
                    <div class="signature-box">
                        <img class="signature-image" src="{{ $signatureDataUri }}" alt="Authorized signature">
                        <div class="signature-line">Authorized Signatory</div>
                    </div>
                </td>
            </tr>
        </table>
    @endif

</div>

<div class="footer">
    <span>This is a system-generated statement.</span>
    <span style="margin-left: 180px;">Generated from Prime Healers OS</span>
    <span style="float:right;">Page <span class="page-number"></span></span>
</div>
</body>
</html>