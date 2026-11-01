@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw" data-collection-ledger>
    <header class="sw-head"><div><div class="sw-eyebrow">Task #{{ $task->id }}</div><h1>Collections & receipts</h1><p class="sw-muted">Cash posts one customer receipt. Online reports wait for Accounts verification.</p></div><div class="sw-actions">@can('view',$task)<a class="sw-btn" href="{{ route('deliveries.show',$task) }}">Back to task</a>@endcan<a class="sw-btn" href="{{ route('cash-handovers.index') }}">Cash handovers</a></div></header>
    @include('sales-workspace.alerts')
    @if((float)$task->collection_amount_collected > 0)
        <p class="sw-guide">The completion report shows ₹{{ number_format($task->collection_amount_collected,2) }}. Reports are preserved separately; check Payments and the saved entries below before recording a new receipt. Existing reports are not automatically posted here.</p>
    @endif
    @if($canRecord && $available > 0)
    <section class="sw-panel"><h2>Record a collection</h2><p class="sw-muted">Up to ₹{{ number_format($available,2) }} remains within this task's authorisation and invoice balance. Other pending reports can reduce the amount accepted at save.</p>
        <form method="post" action="{{ route('delivery-collections.store',$task) }}">@csrf
            <input type="hidden" name="submission_token" value="{{ old('submission_token',(string)\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="version" value="{{ $version }}">
            <div class="sw-fields"><div class="sw-field"><label for="collect-amount">Amount received</label><input type="number" name="amount" id="collect-amount" required step="0.01" min="0.01" max="{{ $available }}" value="{{ old('amount') }}"></div><div class="sw-field"><label for="collect-method">Method</label><select id="collect-method" name="method"><option value="cash" @selected(old('method')==='cash')>Cash</option><option value="upi" @selected(old('method')==='upi')>UPI — verify with Accounts</option><option value="bank_transfer" @selected(old('method')==='bank_transfer')>Bank transfer — verify with Accounts</option><option value="card" @selected(old('method')==='card')>Card — verify with Accounts</option></select></div><div class="sw-field"><label for="collect-reference">Transaction reference</label><input id="collect-reference" name="reference" maxlength="255" value="{{ old('reference') }}"></div><div class="sw-field"><label for="collect-proof">Existing private payment proof</label><select id="collect-proof" name="proof_id"><option value="">No proof selected</option>@foreach($proofs as $proof)<option value="{{ $proof->id }}" @selected((string)old('proof_id')===(string)$proof->id)>Proof #{{ $proof->id }} · {{ $proof->captured_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</option>@endforeach</select></div></div>
            <div class="sw-field"><label for="collect-note">Note</label><textarea id="collect-note" name="note" maxlength="1000" rows="2">{{ old('note') }}</textarea></div>
            <label style="display:block;margin:16px 0"><input type="checkbox" name="confirmed" value="1" required> I received this amount and checked that its receipt has not already been recorded.</label><button class="sw-btn sw-primary">Save collection</button>
        </form>
    </section>
    @endif
    <section class="sw-panel"><h2>Saved collection history</h2>
        @forelse($entries as $entry)
        <article class="sw-panel" style="margin-top:16px"><div class="sw-head"><div><strong>Collection #{{ $entry->id }} · ₹{{ number_format($entry->amount,2) }}</strong><p class="sw-muted">{{ ucfirst(str_replace('_',' ',$entry->method)) }} · {{ $entry->collector?->name }} · {{ $entry->received_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</p></div><span class="sw-badge">{{ ucfirst(str_replace('_',' ',$entry->status)) }}</span></div>
            @if($entry->reference)<p>Reference: {{ $entry->reference }}</p>@endif @if($entry->note)<p>{{ $entry->note }}</p>@endif
            @if($entry->proof)<a class="sw-btn sw-small" href="{{ route('delivery-collections.proof',$entry) }}">View private proof</a>@endif
            @if($entry->payment_id)<p class="sw-meta">Customer receipt #{{ $entry->payment_id }}</p>@endif
            @if($entry->reviewed_at)<p class="sw-meta">Reviewed by {{ $entry->reviewer?->name }} · {{ $entry->reviewed_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }} · {{ $entry->review_reason }}</p>@endif
            @if($entry->reversed_at)<p class="sw-meta">Corrected {{ $entry->reversed_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }} · {{ $entry->reversal_reason }} · Reversal receipt #{{ $entry->reversal_payment_id }}. No customer refund recorded.</p>@endif
            @if($canReceive && $entry->status==='pending_review')<details><summary>Verify or reject online report</summary><form method="post" action="{{ route('delivery-collections.review',$entry) }}">@csrf<div class="sw-field"><label>Decision<select name="action"><option value="verify">Verified against actual funds received</option><option value="reject">Reject — funds not verified</option></select></label><label>Verification note<textarea name="reason" required minlength="5" maxlength="1000" rows="2"></textarea></label></div><label><input type="checkbox" name="confirmed" value="1" required> I checked the actual transaction.</label><button class="sw-btn">Save decision</button></form></details>@endif
            @if($canReceive && auth()->user()->canAccessModule('payments','delete') && in_array($entry->status,['cash_received','verified']))<details><summary>Correct this receipt</summary><p class="sw-muted">Retains the original receipt and posts its reversal. Resolve cash handovers first. This does not refund the customer.</p><form method="post" action="{{ route('delivery-collections.reverse',$entry) }}">@csrf<div class="sw-field"><label>Reason<textarea name="reason" required minlength="5" maxlength="1000" rows="2"></textarea></label></div><label><input type="checkbox" name="confirmed" value="1" required> Reverse this recorded collection.</label><button class="sw-btn">Save correction</button></form></details>@endif
        </article>
        @empty<p class="sw-muted">No collections have been posted through this ledger. Existing task reports remain available in task history.</p>@endforelse
    </section>
</main>
@endsection
