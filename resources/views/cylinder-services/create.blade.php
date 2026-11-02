@extends('layouts.app')
@section('content')
<div class="cyl-page">@include('cylinder-services.style')
<header class="cyl-head"><div><h1>Book cylinder service</h1><p>{{ $rental->billingContactName() }} · Rental #{{ $rental->id }}</p></div><a class="cyl-button" href="{{ route('rentals.show',$rental) }}">Back to rental</a></header>
@if($incoming->isEmpty())<div class="cyl-card"><p>No registered cylinder is currently assigned to this customer. An asset manager must register the existing PHOS-owned cylinder and reconcile its rental assignment first.</p></div>@else
<form class="cyl-card" method="post" action="{{ route('cylinder-services.store',$rental) }}">@csrf
<input type="hidden" name="request_id" value="{{ old('request_id', (string) Str::uuid()) }}"><input type="hidden" name="tax_type" value="cgst_sgst">
<div class="cyl-grid"><label>Service type<select name="mode" required><option value="exchange" @selected(old('mode')==='exchange')>Exchange full for empty</option><option value="same" @selected(old('mode')==='same')>Refill and return the same cylinder</option></select></label>
<label>Customer cylinder to collect<select name="incoming_cylinder_id" required>@foreach($incoming as $c)<option value="{{ $c->id }}" @selected((int) old('incoming_cylinder_id')===$c->id)>{{ $c->asset->serial_number }} · {{ $c->asset->product->name }}</option>@endforeach</select></label>
<label>Replacement cylinder (exchange only)<select name="outgoing_cylinder_id"><option value="">Same-cylinder refill: leave blank</option>@foreach($outgoing as $c)<option value="{{ $c->id }}" @selected((int) old('outgoing_cylinder_id')===$c->id)>{{ $c->asset->serial_number }} · {{ $c->asset->product->name }}</option>@endforeach</select></label>
<label>Assigned staff<select name="assigned_user_id" required>@foreach($staff as $person)<option value="{{ $person->id }}" @selected((int) old('assigned_user_id',auth()->id())===$person->id)>{{ $person->name }}</option>@endforeach</select></label>
<label>Scheduled date & time (IST)<input type="datetime-local" name="scheduled_at" required value="{{ old('scheduled_at',now()->format('Y-m-d\TH:i')) }}"></label>
<label>Refill charge (₹, including GST)<input type="number" min="0" max="999999.99" step="0.01" name="refill_charge" required value="{{ old('refill_charge',0) }}"></label>
<label>Transportation (₹, including GST)<input type="number" min="0" max="999999.99" step="0.01" name="transport_charge" required value="{{ old('transport_charge',0) }}"></label>
<label>GST slab<select name="tax_rate">@foreach([0,5,12,18,28] as $rate)<option value="{{ $rate }}" @selected((int) old('tax_rate',18)===$rate)>{{ $rate }}%</option>@endforeach</select></label></div>
<label>Service notes<textarea name="notes" maxlength="2000">{{ old('notes') }}</textarea></label><p>Rental duration, rental charges and deposit stay unchanged. Invoice payments use the existing collections workflow.</p><button type="submit">Book cylinder service</button></form>@endif
</div>
@endsection
