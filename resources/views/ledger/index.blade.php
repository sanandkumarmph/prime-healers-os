@extends('layouts.app')

@section('content')
@php
    $filters = $statement['filters'];
    $entries = $statement['entries'];
    $summary = $statement['summary'];
    $context = $statement['context'];
    $money = fn ($value) => 'Rs. ' . number_format((float) $value, 2);
    $query = request()->query();
    $pdfPreviewUrl = route('ledger.preview.pdf', $query);
    $pdfDownloadUrl = route('ledger.export.pdf', $query);
    $filterOpen = request()->hasAny(['customer_id','business_partner_id','rental_id','sale_id','invoice_id','payment_status','outstanding_only','city','search','from_date','to_date']);
    $activeFilters = collect([
        'Period' => \App\Services\Finance\LedgerService::PERIODS[$filters['period']] ?? 'This Month',
        'Customer' => optional($filterOptions['customers']->firstWhere('id', $filters['customer_id']))->name,
        'Partner' => optional($filterOptions['businessPartners']->firstWhere('id', $filters['business_partner_id']))->displayName(),
        'Rental' => $filters['rental_id'] ? 'Rental #' . $filters['rental_id'] : null,
        'Sale' => $filters['sale_id'] ? 'Sale #' . $filters['sale_id'] : null,
        'Invoice' => optional($filterOptions['invoices']->firstWhere('id', $filters['invoice_id']))->invoice_number,
        'Status' => $filters['payment_status'] !== '' ? str($filters['payment_status'])->title()->toString() : null,
        'City' => $filters['city'] ?: null,
        'Outstanding' => $filters['outstanding_only'] ? 'Only open balances' : null,
    ])->filter();
@endphp

<style>
    .ledger-page { display: grid; gap: 14px; color: #0f172a; }
    .ledger-shell { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 14px; align-items: start; }
    .ledger-hero, .ledger-card, .ledger-panel, .ledger-table-card { background: rgba(255,255,255,.96); border: 1px solid #dbe4f0; border-radius: 16px; box-shadow: 0 10px 28px rgba(15,23,42,.05); }
    .ledger-hero { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; padding: 16px; }
    .ledger-kicker { color: #4f46e5; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
    .ledger-title { margin: 4px 0 5px; font-size: 24px; line-height: 1.1; letter-spacing: 0; }
    .ledger-meta { display: flex; flex-wrap: wrap; gap: 7px; color: #64748b; font-size: 12px; font-weight: 800; }
    .ledger-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; align-items: center; }
    .ledger-btn, .ledger-icon-btn, .ledger-menu summary { display: inline-flex; align-items: center; justify-content: center; min-height: 36px; border: 1px solid #cbd5e1; border-radius: 11px; background: #fff; color: #334155; font-size: 13px; font-weight: 900; text-decoration: none; cursor: pointer; }
    .ledger-btn { padding: 0 13px; }
    .ledger-icon-btn { width: 36px; padding: 0; }
    .ledger-btn.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .ledger-menu { position: relative; }
    .ledger-menu summary { list-style: none; padding: 0 13px; }
    .ledger-menu summary::-webkit-details-marker { display: none; }
    .ledger-menu[open] .ledger-menu-list { display: grid; }
    .ledger-menu-list { display: none; position: absolute; right: 0; top: 42px; min-width: 180px; z-index: 20; gap: 6px; padding: 8px; border: 1px solid #dbe4f0; border-radius: 13px; background: #fff; box-shadow: 0 16px 40px rgba(15,23,42,.14); }
    .ledger-menu-list a, .ledger-menu-list button { width: 100%; border: 0; border-radius: 10px; background: #f8fafc; color: #0f172a; padding: 10px 12px; text-align: left; font-size: 13px; font-weight: 900; text-decoration: none; cursor: pointer; }
    .ledger-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .ledger-card { padding: 12px; min-height: 82px; }
    .ledger-card span { display: block; color: #64748b; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; }
    .ledger-card strong { display: block; margin-top: 6px; font-size: 20px; font-variant-numeric: tabular-nums; }
    .ledger-card small { display: block; margin-top: 4px; color: #64748b; font-size: 11px; font-weight: 800; }
    .ledger-card.debit strong { color: #b91c1c; }
    .ledger-card.credit strong { color: #047857; }
    .ledger-toolbar { display: grid; grid-template-columns: minmax(180px, 1.6fr) 150px 150px 150px auto auto; gap: 8px; align-items: end; padding: 12px; }
    .ledger-field label { display: block; margin-bottom: 5px; color: #475569; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; }
    .ledger-field input, .ledger-field select { width: 100%; min-height: 36px; border: 1px solid #cbd5e1; border-radius: 11px; padding: 0 10px; color: #0f172a; background: #fff; font-size: 13px; }
    .ledger-filter-details { padding: 0 12px 12px; }
    .ledger-filter-details summary { width: max-content; margin-left: auto; }
    .ledger-filter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px; margin-top: 10px; }
    .ledger-check { display: flex; align-items: center; gap: 8px; min-height: 36px; color: #334155; font-size: 13px; font-weight: 800; }
    .ledger-chips { display: flex; flex-wrap: wrap; gap: 7px; padding: 0 12px 12px; }
    .ledger-chip, .ledger-badge { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; font-size: 11px; font-weight: 900; }
    .ledger-chip { padding: 6px 9px; background: #eef2ff; color: #3730a3; }
    .ledger-badge { padding: 4px 8px; background: #eef2ff; color: #3730a3; text-transform: uppercase; }
    .ledger-badge.success { background: #dcfce7; color: #166534; }
    .ledger-badge.warning { background: #fef3c7; color: #92400e; }
    .ledger-badge.danger { background: #fee2e2; color: #991b1b; }
    .ledger-table-card { overflow: hidden; }
    .ledger-table-head { display: flex; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
    .ledger-table-head h2 { margin: 0; font-size: 17px; }
    .ledger-table-head p { margin: 3px 0 0; color: #64748b; font-size: 12px; }
    .ledger-scroll { overflow: auto; }
    .ledger-table { width: 100%; min-width: 960px; border-collapse: collapse; }
    .ledger-table th { background: #f8fafc; color: #64748b; font-size: 10px; text-align: left; padding: 9px 12px; text-transform: uppercase; letter-spacing: .06em; }
    .ledger-table td { border-top: 1px solid #e2e8f0; padding: 10px 12px; color: #0f172a; font-size: 13px; vertical-align: top; }
    .ledger-table .amount { text-align: right; font-variant-numeric: tabular-nums; font-weight: 900; white-space: nowrap; }
    .ledger-table .muted { color: #64748b; font-size: 12px; }
    .ledger-side { display: grid; gap: 12px; position: sticky; top: 86px; }
    .ledger-panel { padding: 14px; }
    .ledger-panel h2 { margin: 0 0 8px; font-size: 16px; }
    .ledger-context-avatar { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 14px; background: #eef2ff; color: #4f46e5; font-weight: 900; }
    .ledger-context-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .ledger-context-row strong { display: block; font-size: 14px; }
    .ledger-context-row span { display: block; color: #64748b; font-size: 12px; }
    .ledger-related { display: grid; gap: 8px; }
    .ledger-related a { display: flex; justify-content: space-between; align-items: center; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; color: #0f172a; text-decoration: none; font-size: 13px; font-weight: 900; }
    .ledger-mobile { display: none; }
    .ledger-empty { padding: 34px 16px; text-align: center; color: #64748b; }
    .ledger-preview-modal[hidden] { display: none !important; }
    .ledger-preview-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 22px; background: rgba(15,23,42,.62); }
    .ledger-preview-dialog { width: min(1180px, 96vw); height: min(860px, 92vh); display: grid; grid-template-rows: auto minmax(0, 1fr) auto; overflow: hidden; border: 1px solid #dbe4f0; border-radius: 14px; background: #fff; box-shadow: 0 24px 80px rgba(15,23,42,.28); }
    .ledger-preview-header, .ledger-preview-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
    .ledger-preview-footer { border-top: 1px solid #e2e8f0; border-bottom: 0; }
    .ledger-preview-header h2 { margin: 0; font-size: 18px; }
    .ledger-preview-header p { margin: 3px 0 0; color: #64748b; font-size: 12px; font-weight: 800; }
    .ledger-preview-body { position: relative; min-height: 0; background: #f8fafc; }
    .ledger-preview-frame { width: 100%; height: 100%; border: 0; background: #f8fafc; }
    .ledger-preview-loading { position: absolute; inset: 0; display: grid; place-items: center; color: #64748b; font-size: 13px; font-weight: 900; pointer-events: none; }
    .ledger-preview-body.is-loaded .ledger-preview-loading { display: none; }
    @media (max-width: 1100px) { .ledger-shell { grid-template-columns: 1fr; } .ledger-side { position: static; grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 900px) {
        .ledger-hero { flex-direction: column; }
        .ledger-title { font-size: 22px; }
        .ledger-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ledger-toolbar { grid-template-columns: 1fr 1fr; }
        .ledger-filter-grid { grid-template-columns: 1fr; }
        .ledger-side { grid-template-columns: 1fr; }
        .ledger-scroll { display: none; }
        .ledger-mobile { display: grid; gap: 10px; padding: 12px; }
        .ledger-mobile-card { border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; }
        .ledger-mobile-card header { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .ledger-mobile-card h3 { margin: 0; font-size: 15px; }
        .ledger-mobile-card .date { color: #64748b; font-size: 12px; font-weight: 800; }
        .ledger-mobile-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px; }
        .ledger-mobile-grid span { display: block; color: #64748b; font-size: 10px; font-weight: 900; text-transform: uppercase; }
        .ledger-mobile-grid strong { display: block; margin-top: 3px; font-size: 13px; }
    }
    @media (max-width: 560px) {
        .ledger-preview-modal { padding: 8px; } .ledger-preview-dialog { width: 100vw; height: calc(100vh - 16px); border-radius: 12px; } .ledger-preview-footer { flex-wrap: wrap; } .ledger-preview-footer .ledger-btn { flex: 1; }
        .ledger-toolbar { grid-template-columns: 1fr; } .ledger-summary { grid-template-columns: 1fr; } .ledger-actions { width: 100%; justify-content: stretch; } .ledger-actions .ledger-btn, .ledger-actions .ledger-menu { flex: 1; } }
</style>

<div class="ledger-page">
    <section class="ledger-hero">
        <div>
            <div class="ledger-kicker">Finance Workspace</div>
            <h1 class="ledger-title">Unified Ledger Statement</h1>
            <div class="ledger-meta">
                <span>{{ $context['type'] }}</span>
                <span>{{ $statement['context_label'] }}</span>
                <span>{{ $statement['date_label'] }}</span>
            </div>
        </div>
        <div class="ledger-actions">
            <a class="ledger-btn" href="{{ route('ledger.export.csv', $query) }}">Export CSV</a>
            <button class="ledger-btn primary" type="button" data-ledger-preview-open>Preview PDF</button>
            <details class="ledger-menu">
                <summary>More</summary>
                <div class="ledger-menu-list">
                    @if($context['url'])
                        <a href="{{ $context['url'] }}">Open {{ $context['type'] }}</a>
                    @endif
                    <a href="{{ route('ledger.index') }}">Global Ledger</a>
                    <button type="button" onclick="window.print()">Print Page</button>
                </div>
            </details>
        </div>
    </section>

    <section class="ledger-summary" aria-label="Ledger totals">
        <div class="ledger-card"><span>Opening Balance</span><strong>{{ $money($summary['opening_balance']) }}</strong><small>Before period</small></div>
        <div class="ledger-card debit"><span>Invoiced</span><strong>{{ $money($summary['total_debit']) }}</strong><small>Debits in period</small></div>
        <div class="ledger-card credit"><span>Payments</span><strong>{{ $money($summary['total_credit']) }}</strong><small>Credits in period</small></div>
        <div class="ledger-card"><span>Closing Balance</span><strong>{{ $money($summary['closing_balance']) }}</strong><small>{{ $statement['entry_count_label'] }}</small></div>
    </section>

    <section class="ledger-panel">
        <form method="GET" action="{{ route('ledger.index') }}" class="ledger-toolbar">
            <div class="ledger-field"><label>Search</label><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Invoice, customer, reference"></div>
            <div class="ledger-field"><label>From</label><input type="date" name="from_date" value="{{ $filters['from_date']->format('Y-m-d') }}"></div>
            <div class="ledger-field"><label>To</label><input type="date" name="to_date" value="{{ $filters['to_date']->format('Y-m-d') }}"></div>
            <div class="ledger-field"><label>Status</label><select name="payment_status"><option value="">All statuses</option>@foreach(['pending','partial','paid','overdue','cancelled'] as $status)<option value="{{ $status }}" @selected($filters['payment_status'] === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
            <button class="ledger-btn primary" type="submit">Apply</button>
            <a class="ledger-btn" href="{{ route('ledger.index') }}">Clear</a>
        </form>

        <details class="ledger-filter-details" {{ $filterOpen ? 'open' : '' }}>
            <summary class="ledger-btn">More Filters</summary>
            <form method="GET" action="{{ route('ledger.index') }}" class="ledger-filter-grid">
                <div class="ledger-field"><label>Period</label><select name="period">
                    @foreach(\App\Services\Finance\LedgerService::PERIODS as $key => $label)
                        <option value="{{ $key }}" @selected($filters['period'] === $key)>{{ $label }}</option>
                    @endforeach
                </select></div>
                <div class="ledger-field"><label>Customer</label><select name="customer_id"><option value="">All customers</option>@foreach($filterOptions['customers'] as $customer)<option value="{{ $customer->id }}" @selected($filters['customer_id'] === $customer->id)>{{ $customer->name }}</option>@endforeach</select></div>
                <div class="ledger-field"><label>Business Partner</label><select name="business_partner_id"><option value="">All partners</option>@foreach($filterOptions['businessPartners'] as $partner)<option value="{{ $partner->id }}" @selected($filters['business_partner_id'] === $partner->id)>{{ $partner->displayName() }}</option>@endforeach</select></div>
                <div class="ledger-field"><label>Rental</label><select name="rental_id"><option value="">All rentals</option>@foreach($filterOptions['rentals'] as $rental)<option value="{{ $rental->id }}" @selected($filters['rental_id'] === $rental->id)>Rental #{{ $rental->id }}</option>@endforeach</select></div>
                <div class="ledger-field"><label>Sale</label><select name="sale_id"><option value="">All sales</option>@foreach($filterOptions['sales'] as $sale)<option value="{{ $sale->id }}" @selected($filters['sale_id'] === $sale->id)>Sale #{{ $sale->id }}</option>@endforeach</select></div>
                <div class="ledger-field"><label>Invoice</label><select name="invoice_id"><option value="">All invoices</option>@foreach($filterOptions['invoices'] as $invoice)<option value="{{ $invoice->id }}" @selected($filters['invoice_id'] === $invoice->id)>{{ $invoice->invoice_number ?: 'Invoice #' . $invoice->id }}</option>@endforeach</select></div>
                <div class="ledger-field"><label>City</label><select name="city"><option value="">All cities</option>@foreach($filterOptions['cities'] as $city)<option value="{{ $city }}" @selected($filters['city'] === $city)>{{ $city }}</option>@endforeach</select></div>
                <label class="ledger-check"><input type="checkbox" name="outstanding_only" value="1" @checked($filters['outstanding_only'])> Outstanding only</label>
                <input type="hidden" name="search" value="{{ $filters['search'] }}">
                <input type="hidden" name="from_date" value="{{ $filters['from_date']->format('Y-m-d') }}">
                <input type="hidden" name="to_date" value="{{ $filters['to_date']->format('Y-m-d') }}">
                <div class="ledger-actions"><button class="ledger-btn primary" type="submit">Apply Filters</button></div>
            </form>
        </details>

        <div class="ledger-chips">
            @foreach($activeFilters as $label => $value)
                <span class="ledger-chip">{{ $label }}: {{ $value }}</span>
            @endforeach
        </div>
    </section>

    <div class="ledger-shell">
        <section class="ledger-table-card">
            <div class="ledger-table-head">
                <div><h2>Statement Entries</h2><p>Invoice debits and payment credits, using the existing PHOS ledger source.</p></div>
                <span class="ledger-badge">{{ $statement['entry_count_label'] }}</span>
            </div>
            <div class="ledger-scroll">
                <table class="ledger-table">
                    <thead><tr><th>Date</th><th>Reference</th><th>Particulars</th><th>Status</th><th>Debit</th><th>Credit</th><th>Balance</th><th></th></tr></thead>
                    <tbody>
                        <tr><td>{{ $filters['from_date']->format('d M Y') }}</td><td><strong>Opening</strong></td><td>Opening balance before selected period</td><td><span class="ledger-badge">Opening</span></td><td class="amount">-</td><td class="amount">-</td><td class="amount">{{ $money($summary['opening_balance']) }}</td><td></td></tr>
                        @forelse($entries as $entry)
                            <tr>
                                <td>{{ $entry['entry_date']->format('d M Y') }}</td>
                                <td><strong>{{ $entry['reference'] }}</strong><div class="muted">{{ $entry['source_label'] }}</div></td>
                                <td>{{ $entry['particulars'] }}</td>
                                <td><span class="ledger-badge {{ $entry['status_tone'] ?? 'neutral' }}">{{ $entry['status_label'] ?? 'Open' }}</span></td>
                                <td class="amount">{{ $entry['debit'] > 0 ? $money($entry['debit']) : '-' }}</td>
                                <td class="amount">{{ $entry['credit'] > 0 ? $money($entry['credit']) : '-' }}</td>
                                <td class="amount">{{ $money($entry['running_balance']) }}</td>
                                <td><a class="ledger-icon-btn" href="{{ $entry['source_url'] }}" title="Open {{ $entry['source_label'] }}" aria-label="Open {{ $entry['source_label'] }}"><span aria-hidden="true">&#128065;</span></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8"><div class="ledger-empty">No ledger entries found for this filter.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ledger-mobile">
                @forelse($entries as $entry)
                    <article class="ledger-mobile-card">
                        <header><div><h3>{{ $entry['reference'] }}</h3><span class="date">{{ $entry['entry_date']->format('d M Y') }} | {{ $entry['source_label'] }}</span></div><span class="ledger-badge {{ $entry['status_tone'] ?? 'neutral' }}">{{ $entry['status_label'] ?? 'Open' }}</span></header>
                        <p style="margin:0; color:#475569;">{{ $entry['particulars'] }}</p>
                        <div class="ledger-mobile-grid"><div><span>Debit</span><strong>{{ $entry['debit'] > 0 ? $money($entry['debit']) : '-' }}</strong></div><div><span>Credit</span><strong>{{ $entry['credit'] > 0 ? $money($entry['credit']) : '-' }}</strong></div><div><span>Balance</span><strong>{{ $money($entry['running_balance']) }}</strong></div></div>
                        <a class="ledger-btn" style="margin-top:10px;" href="{{ $entry['source_url'] }}">Open {{ $entry['source_label'] }}</a>
                    </article>
                @empty
                    <div class="ledger-mobile-card">No ledger entries found for this filter.</div>
                @endforelse
            </div>
        </section>

        <aside class="ledger-side">
            <section class="ledger-panel">
                <h2>Statement Context</h2>
                <div class="ledger-context-row">
                    <div class="ledger-context-avatar">{{ strtoupper(substr($context['type'], 0, 2)) }}</div>
                    <div><strong>{{ $context['name'] ?: $context['label'] }}</strong><span>{{ $context['type'] }}</span></div>
                </div>
                @if(!empty($context['meta']))
                    <div class="ledger-chips" style="padding:0;">
                        @foreach($context['meta'] as $meta)
                            <span class="ledger-chip">{{ $meta }}</span>
                        @endforeach
                    </div>
                @endif
            </section>
            <section class="ledger-panel">
                <h2>Balance Snapshot</h2>
                <div class="ledger-related">
                    <a href="#"><span>Outstanding</span><strong>{{ $money($summary['outstanding']) }}</strong></a>
                    @if($summary['overdue_amount'] > 0)
                        <a href="#"><span>Overdue</span><strong>{{ $money($summary['overdue_amount']) }}</strong></a>
                    @endif
                    <a href="#"><span>Period Activity</span><strong>{{ $money($summary['period_activity']) }}</strong></a>
                </div>
            </section>
            @if(!empty($statement['related_records']))
                <section class="ledger-panel">
                    <h2>Related Records</h2>
                    <div class="ledger-related">
                        @foreach($statement['related_records'] as $record)
                            <a href="{{ $record['url'] }}"><span>{{ $record['label'] }}</span><strong>{{ $record['count'] }}</strong></a>
                        @endforeach
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>
<div id="ledgerPdfPreviewModal" class="ledger-preview-modal" hidden aria-hidden="true">
    <section class="ledger-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="ledgerPdfPreviewTitle">
        <header class="ledger-preview-header">
            <div>
                <h2 id="ledgerPdfPreviewTitle">Preview Statement of Accounts</h2>
                <p>{{ $statement['context_label'] }} &bull; {{ $statement['date_label'] }}</p>
            </div>
            <button class="ledger-icon-btn" type="button" data-ledger-preview-close aria-label="Close PDF preview">&times;</button>
        </header>
        <div class="ledger-preview-body" data-ledger-preview-body>
            <div class="ledger-preview-loading">Generating statement preview...</div>
            <iframe class="ledger-preview-frame" data-ledger-preview-frame title="Statement of Accounts PDF preview"></iframe>
        </div>
        <footer class="ledger-preview-footer">
            <a class="ledger-btn" href="{{ $pdfPreviewUrl }}" target="_blank" rel="noopener">Open in New Tab</a>
            <button class="ledger-btn" type="button" data-ledger-preview-print>Print</button>
            <a class="ledger-btn primary" href="{{ $pdfDownloadUrl }}">Download PDF</a>
            <button class="ledger-btn" type="button" data-ledger-preview-close>Close</button>
        </footer>
    </section>
</div>

<script>
    (() => {
        const modal = document.getElementById('ledgerPdfPreviewModal');
        if (!modal) return;

        const frame = modal.querySelector('[data-ledger-preview-frame]');
        const body = modal.querySelector('[data-ledger-preview-body]');
        const previewUrl = @json($pdfPreviewUrl);
        const firstFocusable = modal.querySelector('[data-ledger-preview-close]');

        const openPreview = () => {
            body?.classList.remove('is-loaded');
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            if (frame && frame.getAttribute('src') !== previewUrl) {
                frame.setAttribute('src', previewUrl);
            }
            firstFocusable?.focus();
        };

        const closePreview = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            if (frame) frame.setAttribute('src', 'about:blank');
            body?.classList.remove('is-loaded');
        };

        document.addEventListener('click', (event) => {
            const openButton = event.target.closest('[data-ledger-preview-open]');
            if (openButton) {
                event.preventDefault();
                openPreview();
                return;
            }

            if (event.target.closest('[data-ledger-preview-close]')) {
                event.preventDefault();
                closePreview();
                return;
            }

            if (event.target === modal) {
                closePreview();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) closePreview();
        });

        frame?.addEventListener('load', () => body?.classList.add('is-loaded'));

        modal.querySelector('[data-ledger-preview-print]')?.addEventListener('click', () => {
            try {
                frame?.contentWindow?.focus();
                frame?.contentWindow?.print();
            } catch (error) {
                window.open(previewUrl, '_blank', 'noopener');
            }
        });
    })();
</script>
@endsection
