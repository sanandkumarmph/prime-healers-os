@extends('layouts.app')
@section('content')
<div class="cyl-page">@include('cylinder-services.style')
<header class="cyl-head"><div><h1>Cylinder service #{{ $job->id }}</h1><p>{{ $job->rental->billingContactName() }} · {{ $job->mode === 'same' ? 'Same-cylinder refill' : 'Full-for-empty exchange' }}</p></div><a class="cyl-button" href="{{ route('cylinder-services.index') }}">All services</a></header>
<div class="cyl-grid"><section class="cyl-card"><h2>Customer handover</h2><p>Collect: <strong>{{ $job->incoming->asset->serial_number }}</strong> · {{ $job->incoming->contents }} / {{ str_replace('_',' ',$job->incoming->custody) }}</p><p>Deliver: <strong>{{ $job->outgoing->asset->serial_number }}</strong> · {{ $job->outgoing->contents }} / {{ str_replace('_',' ',$job->outgoing->custody) }}</p><p>{{ $job->assignee?->name }} · {{ $job->scheduled_at?->format('d M Y, h:i A') }} IST</p><span class="cyl-badge">{{ ucfirst($job->status) }}</span><p>{{ $job->notes }}</p><a href="{{ route('rentals.show',$job->rental) }}">Open rental #{{ $job->rental_id }}</a></section>
<section class="cyl-card"><h2>Service billing</h2>
@if(auth()->user()->canViewDues() || auth()->user()->canViewFinance())<p>Refill ₹{{ number_format($job->refill_charge,2) }} + transportation ₹{{ number_format($job->transport_charge,2) }} (GST included, {{ $job->tax_rate }}%).</p>@endif
@if($job->invoice_id && auth()->user()->canAccessModule('invoices','read'))<a class="cyl-button" href="{{ route('invoices.show',$job->invoice_id) }}">Open service invoice</a>
@elseif(!$job->invoice_id && $job->status === 'completed' && auth()->user()->canAccessModule('invoices','create'))<form method="post" action="{{ route('cylinder-services.invoice',$job) }}">@csrf<button type="submit">Create service invoice</button></form>@else<p>Invoice becomes available after delivery and empty-cylinder warehouse receipt are complete.</p>@endif
<small>No new rental, deposit or payment is created by a cylinder movement.</small></section></div>
@php
$stock = auth()->user()->canAccessModule('assets','update') && auth()->user()->scopeType('assets') === 'all';
$actions = [];
if($job->status !== 'cancelled') {
if(!$job->collected_at) $actions['empty_collected']='Collect empty cylinder';
if($job->collected_at && !$job->warehouse_received_at && $stock) $actions['warehouse_received']='Receive empty at warehouse';
if($job->warehouse_received_at && !$job->refill_sent_at && $stock && $job->incoming->contents==='empty') $actions['refill_sent']='Send empty for refill';
if($job->refill_sent_at && !$job->refilled_at && $stock && $job->incoming->custody==='refiller') $actions['refill_received']='Receive inspected full cylinder';
if(!$job->delivered_at && ($job->mode==='exchange' || $job->refilled_at)) $actions['full_delivered']='Deliver full cylinder';
if(!$job->collected_at && !$job->delivered_at) $actions['cancelled']='Cancel unstarted service';
}
@endphp
@if(auth()->user()->canAccessModule('deliveries','update'))<div class="cyl-grid">@foreach($actions as $action=>$label)
<details class="cyl-card"><summary>{{ $label }}</summary><form method="post" action="{{ route('cylinder-services.action',$job) }}" data-cylinder-step>@csrf
<input type="hidden" name="request_id" value="{{ (string) Str::uuid() }}"><input type="hidden" name="lock_version" value="{{ $job->lock_version }}"><input type="hidden" name="action" value="{{ $action }}">
@if($action!=='cancelled')<label>Scan / enter {{ $action==='full_delivered' ? $job->outgoing->asset->serial_number : $job->incoming->asset->serial_number }}<input name="scanned_code" maxlength="255" required autocomplete="off"></label>@endif
@if(in_array($action,['warehouse_received','refill_received']))<label>Receiving warehouse<select name="warehouse_id" required>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label>@endif
@if($action==='empty_collected')<label><input type="checkbox" name="confirm_empty" value="1" required> Collected cylinder is empty</label>@endif
@if($action==='refill_received')<label><input type="checkbox" name="inspection_confirmed" value="1" required> Refill and cylinder condition inspected</label>@endif
@if(in_array($action,['empty_collected','full_delivered']))<label>Name of person signing<input name="signer_name" maxlength="150" autocomplete="name"></label><label>Customer signature</label><canvas data-cylinder-signature aria-label="Customer signature drawing area"></canvas><input type="hidden" name="signature_data"><button type="button" data-signature-clear>Clear signature</button><label>If signing is not possible, record the reason<input name="signature_reason" maxlength="500"></label>@endif
<label>Notes<textarea name="notes" maxlength="2000"></textarea></label><button type="submit">{{ $label }}</button></form></details>@endforeach</div>@endif
<section class="cyl-card"><h2>Movement history</h2>@foreach($job->events->sortByDesc('id') as $event)<article class="cyl-event"><strong>{{ ucfirst(str_replace('_',' ',$event->action)) }}</strong><time>{{ $event->created_at->format('d M Y, h:i:s A') }} IST · {{ $event->user?->name }}</time><p>{{ $event->details['serial_number'] ?? '' }} · {{ $event->details['notes'] ?? '' }}</p>@if(!empty($event->details['signer_name']))<p>Signed by {{ $event->details['signer_name'] }}</p>@endif @if(!empty($event->details['signature_reason']))<p>Signature unavailable: {{ $event->details['signature_reason'] }}</p>@endif @if(!empty($event->details['signature_path']))<a href="{{ route('cylinder-services.signature',[$job,$event]) }}" target="_blank" rel="noopener">View signature</a>@endif</article>@endforeach</section>
</div><script src="{{ asset('js/phos-cylinder-service.js') }}" defer></script>
@endsection
