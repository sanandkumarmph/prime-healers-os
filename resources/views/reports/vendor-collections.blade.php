@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw">
    <header class="sw-head"><div><div class="sw-eyebrow">Vendor Analytics</div><h1>Vendor-collected payments</h1></div><a class="sw-btn" href="{{ route('reports.index',['tab'=>'vendors']) }}">Back to Vendor Analytics</a></header>
    <form class="sw-panel" method="get">
        <div class="sw-fields">
            <div class="sw-field"><label for="from">Collected from</label><input id="from" name="from" type="date" value="{{ $from }}"></div>
            <div class="sw-field"><label for="to">Collected through</label><input id="to" name="to" type="date" value="{{ $to }}"></div>
            <div class="sw-field"><label for="vendor">Vendor</label><select id="vendor" name="vendor"><option value="">All vendors</option>@foreach($options as $key => $name)<option value="{{ $key }}" @selected($key === $vendor)>{{ $name }}</option>@endforeach</select></div>
        </div>
        <button class="sw-btn sw-primary">Apply filters</button>
    </form>
    <section class="sw-panel">
        <h2>Collections by vendor</h2>
        <p class="sw-muted">Use posted receipts for reconciliation. Completion reports may describe the same payment. Check the linked task before posting or settling a report.</p>
        <div style="overflow:auto"><table class="sw-table"><thead><tr><th>Vendor</th><th>Posted receipts</th><th>Posted amount</th><th>Completion reports</th><th>Reported amount</th></tr></thead>
            <tbody>@forelse($rows as $key => $name)<tr>
                <td>{{ $name }}</td><td>{{ $postedTotals->get($key)?->entries ?? 0 }}</td><td>₹{{ number_format($postedTotals->get($key)?->amount ?? 0, 2) }}</td>
                <td>{{ $reportedTotals->get($key)?->entries ?? 0 }}</td><td>₹{{ number_format($reportedTotals->get($key)?->amount ?? 0, 2) }}</td>
            </tr>@empty<tr><td colspan="5">No payments explicitly attributed to a vendor in this period.</td></tr>@endforelse</tbody>
            <tfoot><tr><th>Total</th><td>{{ $postedTotals->sum('entries') }}</td><td>₹{{ number_format($postedTotals->sum('amount'), 2) }}</td><td>{{ $reportedTotals->sum('entries') }}</td><td>₹{{ number_format($reportedTotals->sum('amount'), 2) }}</td></tr></tfoot>
        </table></div>
    </section>
    <section class="sw-panel"><h2>Posted receipts</h2>
        @forelse($receipts as $entry)<p>{{ $entry->payment_date?->format('d M Y') }} · {{ $entry->collection_vendor_name }} · ₹{{ number_format($entry->amount,2) }} · Receipt #{{ $entry->id }} @if($entry->invoice_id && auth()->user()->canAccessModule('invoices','read')) · <a href="{{ route('invoices.show',$entry->invoice_id) }}">Invoice #{{ $entry->invoice_id }}</a>@endif</p>
        @empty<p class="sw-muted">No posted receipts.</p>@endforelse
        {{ $receipts->links() }}
    </section>
    <section class="sw-panel"><h2>Delivery completion reports</h2>
        @forelse($reports as $task)<p>{{ $task->completed_at?->format('d M Y') }} · {{ $task->collection_vendor_name }} · ₹{{ number_format($task->collection_amount_collected,2) }} · <a href="{{ route('deliveries.show',$task) }}">Delivery #{{ $task->id }}</a></p>
        @empty<p class="sw-muted">No vendor collection reports from completed deliveries.</p>@endforelse
        {{ $reports->links() }}
    </section>
</main>
@endsection
