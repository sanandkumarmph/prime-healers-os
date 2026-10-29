@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<div class="sw" data-availability-workspace data-check-url="{{ route('availability.check') }}">
    <header class="sw-head"><div><div class="sw-eyebrow">Plan before you promise</div><h1>Rental availability</h1><p class="sw-muted">Check stock for the dates your customer needs.</p></div><a href="{{ auth()->user()->canAccessModule('rentals','read') ? route('rentals.index') : route('enquiries.index') }}" class="sw-btn">Back to workspace</a></header>
    <form data-availability-form class="sw-panel">
        <div class="sw-fields">
            <div class="sw-field"><label for="availability-start">From</label><input id="availability-start" name="start_date" type="date" value="{{ now(auth()->user()->organization->timezone ?: 'UTC')->toDateString() }}" required></div>
            <div class="sw-field"><label for="availability-end">Through</label><input id="availability-end" name="end_date" type="date" value="{{ now(auth()->user()->organization->timezone ?: 'UTC')->addDays(6)->toDateString() }}" required></div>
            <div class="sw-field"><label for="availability-product">Product</label><select id="availability-product" name="product_id" required><option value="">Select a rental product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></div>
            <div class="sw-field"><label for="availability-quantity">Quantity</label><input id="availability-quantity" name="quantity" type="number" min="1" max="10000" value="1" required></div>
            <div class="sw-field"><label for="availability-warehouse">Warehouse</label><select id="availability-warehouse" name="warehouse_id"><option value="">All accessible warehouses</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select></div>
        </div>
        <div class="sw-actions" style="margin-top:16px"><button class="sw-btn sw-primary" type="submit">Check dates</button></div>
    </form>
    <div data-availability-results aria-live="polite"></div>
    <div class="sw-panel"><strong>How to read the forecast</strong><p class="sw-meta">Ready now can be allocated immediately. Expected returns need return and inspection first. Stock reserved for a later booking needs scheduling review. Same-day returns, overdue units and items awaiting inspection are excluded. Vendor supply is arranged separately.</p><p class="sw-meta">Forecasts do not reserve stock. Confirm availability again when creating the order.</p></div>
</div>
@endsection
