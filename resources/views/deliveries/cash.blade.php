@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<main class="sw" data-cash-handovers>
    <header class="sw-head"><div><div class="sw-eyebrow">Cash accountability</div><h1>{{ $canReceive ? 'Cash handovers' : 'My cash handovers' }}</h1><p class="sw-muted">A handover records custody. It does not credit the customer invoice again.</p></div>@if($canReceive)<a class="sw-btn" href="{{ route('delivery-collections.index') }}">Online reports awaiting review</a>@endif</header>
    @include('sales-workspace.alerts')
    @forelse($entries as $entry)
    @php($cash=$entry->cashAmounts())
    <section class="sw-panel"><header class="sw-head"><div><h2>Collection #{{ $entry->id }} · Task #{{ $entry->delivery_id }}</h2><p class="sw-muted">{{ $entry->collector?->name }} · {{ $entry->received_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }} · {{ ucfirst($entry->status) }}</p></div><a class="sw-btn" href="{{ route('delivery-collections.show',$entry->delivery_id) }}">Collection history</a></header>
        <div class="sw-fields"><p>Collected <strong>₹{{ number_format($cash['collected']/100,2) }}</strong></p><p>Confirmed with Accounts <strong>₹{{ number_format($cash['confirmed']/100,2) }}</strong></p><p>Awaiting confirmation <strong>₹{{ number_format($cash['pending']/100,2) }}</strong></p><p>Still outstanding <strong>₹{{ number_format($cash['outstanding']/100,2) }}</strong></p></div>
        @if((int)$entry->collector_user_id===(int)auth()->id() && $cash['available']>0)
        <details><summary>Submit cash handover</summary><form method="post" action="{{ route('cash-handovers.store',$entry) }}">@csrf<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><div class="sw-field"><label>Amount being handed over<input type="number" name="amount" min="0.01" step="0.01" max="{{ $cash['available']/100 }}" required></label></div><label><input type="checkbox" name="confirmed" value="1" required> Submit this cash for receipt confirmation.</label><button class="sw-btn sw-primary">Submit handover</button></form></details>
        @endif
        @foreach($entry->handovers->sortByDesc('id') as $handover)
        <article class="sw-panel" style="margin-top:14px"><strong>Handover #{{ $handover->id }} · {{ ucfirst($handover->status) }}</strong><p>Submitted ₹{{ number_format($handover->submitted_amount,2) }} · {{ $handover->submitted_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</p>
            @if($handover->confirmed_at)<p>Actually received ₹{{ number_format($handover->received_amount,2) }} by {{ $handover->receiver?->name }} · {{ $handover->confirmed_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</p>@if($handover->discrepancy_reason)<p>Difference: {{ $handover->discrepancy_reason }}</p>@endif @endif
            @if($handover->corrected_at)<p>Correction: {{ $handover->correction_reason }} · {{ $handover->corrected_at?->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i:s A \I\S\T') }}</p>@endif
            @if($canReceive && (int)$handover->collector_user_id!==(int)auth()->id() && $handover->status==='pending')
            <details><summary>Confirm actual cash received</summary><form method="post" action="{{ route('cash-handovers.confirm',$handover) }}">@csrf<input type="hidden" name="confirmation_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><div class="sw-fields"><div class="sw-field"><label>Actual amount received<input name="received_amount" type="number" min="0" max="{{ $handover->submitted_amount }}" step="0.01" required></label></div><div class="sw-field"><label>Reason if less than submitted<textarea name="reason" maxlength="1000" rows="2"></textarea></label></div></div><label><input type="checkbox" name="confirmed" value="1" required> I received and counted this cash.</label><button class="sw-btn">Confirm receipt</button></form></details>
            @endif
            @if(($handover->status==='pending' && (int)$handover->collector_user_id===(int)auth()->id()) || ($handover->status==='confirmed' && $canReceive && (int)$handover->collector_user_id!==(int)auth()->id()))
            <details><summary>{{ $handover->status==='pending' ? 'Cancel pending submission' : 'Correct erroneous cash receipt' }}</summary><form method="post" action="{{ route('cash-handovers.correct',$handover) }}">@csrf<input type="hidden" name="action" value="{{ $handover->status==='pending' ? 'cancel' : 'reverse' }}"><div class="sw-field"><label>Reason<textarea name="reason" minlength="5" maxlength="1000" required rows="2"></textarea></label></div><label><input type="checkbox" name="confirmed" value="1" required> Correct this entry, keeping its original history. No money is transferred.</label><button class="sw-btn">Save correction</button></form></details>
            @endif
        </article>
        @endforeach
    </section>
    @empty<p class="sw-panel">No cash collections are available in this ledger. Record new authorised collections from their task; reconcile historical reports with Accounts.</p>@endforelse
    {{ $entries->links() }}
</main>
@endsection
