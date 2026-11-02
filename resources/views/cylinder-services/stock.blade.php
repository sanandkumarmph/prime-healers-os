@extends('layouts.app')
@section('content')
<div class="cyl-page">@include('cylinder-services.style')
<header class="cyl-head"><div><h1>Cylinder stock</h1><p>Track each existing PHOS cylinder by its permanent serial number.</p></div><a class="cyl-button" href="{{ route('cylinder-services.index') }}">Services</a></header>
<details class="cyl-card" id="cylinder-registration" @if(request()->boolean('register')) open @endif><summary>Register existing PHOS-owned cylinder</summary><p>Select only PHOS-owned oxygen rental cylinders. Registration does not purchase, duplicate or add a new asset.</p><form method="post" action="{{ route('cylinder-services.register') }}">@csrf
<label>Existing rental asset<select name="asset_id" required><option value="">Choose asset</option>@foreach($assets as $asset)<option value="{{ $asset->id }}">{{ $asset->serial_number }} · {{ $asset->product?->name }} · {{ $asset->asset_status }}</option>@endforeach</select></label>
<label>Confirmed contents<select name="contents"><option value="unknown">Not confirmed</option><option value="full">Full</option><option value="empty">Empty</option></select></label><button type="submit">Register cylinder</button></form></details>
<div class="cyl-grid">@forelse($cylinders as $c)<section class="cyl-card"><h2>{{ $c->asset->serial_number }}</h2><p>{{ $c->asset->product?->name }} · {{ ucfirst($c->contents) }} · {{ str_replace('_',' ',$c->custody) }}</p>
@if(!$c->reserved_for_rental_id && !$c->asset->activeRentalAssignments()->exists())
@php $step = match(true) { $c->custody==='warehouse' && $c->contents==='unknown'=>'inspection_recorded', $c->custody==='in_transit' && $c->contents==='unknown'=>'return_received', $c->custody==='warehouse' && $c->contents==='empty'=>'sent_for_refill', $c->custody==='refiller' && $c->contents==='refilling'=>'received_full', default=>null }; @endphp
@if($step)<details><summary>{{ ucfirst(str_replace('_',' ',$step)) }}</summary><form method="post" action="{{ route('cylinder-services.stock-action',$c) }}">@csrf<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><input type="hidden" name="action" value="{{ $step }}">
<label>Confirm serial / barcode<input name="scanned_code" maxlength="255" required autocomplete="off"></label><label>Warehouse<select name="warehouse_id" required>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected($w->id===$c->asset->warehouse_id)>{{ $w->name }}</option>@endforeach</select></label>
@if(in_array($step,['return_received','inspection_recorded']))<label>Contents after inspection<select name="contents" required><option value="empty">Empty</option><option value="full">Full</option></select></label>@endif
@if($step!=='sent_for_refill')<label><input type="checkbox" name="inspection_confirmed" value="1" required> Cylinder inspected; condition is recorded on the asset</label>@endif
<label>Notes<textarea name="notes" maxlength="2000"></textarea></label><button type="submit">Record stock step</button></form></details>@endif
@else<p>Assigned / reserved for Rental #{{ $c->reserved_for_rental_id }}. Use its service job for movements.</p>@endif
<a href="{{ route('assets.show',$c->asset_id) }}">Open asset / condition</a><details><summary>Recent history</summary>@foreach($c->events->take(6) as $event)<div class="cyl-event">{{ ucfirst(str_replace('_',' ',$event->action)) }}<time>{{ $event->created_at->format('d M Y, h:i:s A') }} IST</time>@if($event->cylinder_job_id)<a href="{{ route('cylinder-services.show',$event->cylinder_job_id) }}">Service #{{ $event->cylinder_job_id }}</a>@endif</div>@endforeach</details></section>@empty<p>No cylinders registered.</p>@endforelse</div>{{ $cylinders->links() }}
</div>
@endsection
