@extends('layouts.app')
@section('content')
<div class="cyl-page">
@include('cylinder-services.style')
<header class="cyl-head"><div><h1>Cylinder services</h1><p>Full-cylinder exchanges, refills and empty-cylinder returns.</p></div><div>
@if(auth()->user()->canAccessModule('assets','update') && auth()->user()->scopeType('assets') === 'all')<a class="cyl-button" href="{{ route('cylinder-services.stock') }}">Cylinder stock</a>@endif
<a class="cyl-button" href="{{ route('deliveries.index') }}">Tasks board</a></div></header>
<p>Start a service from an active rental. Only existing PHOS-owned, serialised rental cylinders are supported.</p>
<div class="cyl-grid">@forelse($jobs as $job)
<article class="cyl-card"><div class="cyl-head"><h2>Service #{{ $job->id }}</h2><span class="cyl-badge">{{ ucfirst($job->status) }}</span></div>
<p>{{ $job->rental->billingContactName() }} · {{ $job->mode === 'same' ? 'Same-cylinder refill' : 'Full-for-empty exchange' }}</p>
<p>Collect {{ $job->incoming->asset->serial_number }} → deliver {{ $job->outgoing->asset->serial_number }}</p>
<p>{{ $job->scheduled_at?->format('d M Y, h:i A') }} IST · {{ $job->assignee?->name }}</p>
<a class="cyl-button" href="{{ route('cylinder-services.show',$job) }}">Open service</a></article>
@empty<p>No cylinder services yet.</p>@endforelse</div>{{ $jobs->links() }}
</div>
@endsection
