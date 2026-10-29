@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw"><header class="sw-head"><div><div class="sw-eyebrow">Accounts</div><h1>Online collection review</h1><p class="sw-muted">Only verification against actual funds received posts a customer receipt.</p></div><a class="sw-btn" href="{{ route('cash-handovers.index') }}">Cash handovers</a></header>
    @forelse($entries as $entry)<article class="sw-panel sw-head"><div><strong>Task #{{ $entry->delivery_id }} · ₹{{ number_format($entry->amount,2) }}</strong><p class="sw-muted">{{ $entry->collector?->name }} · {{ $entry->reference ?: 'Private proof attached' }}</p></div><a class="sw-btn" href="{{ route('delivery-collections.show',$entry->delivery_id) }}">Review report</a></article>@empty<p class="sw-panel">No online reports are awaiting verification.</p>@endforelse
    {{ $entries->links() }}
</main>
@endsection
