@php
    $canBookCylinder = $canCreateDeliveries && $canUpdateRentals && $rental->status === 'active';
    $canReadCylinders = auth()->user()->canAccessModule('deliveries','read') || (auth()->user()->canViewDues() && auth()->user()->canAccessModule('rentals','read') && auth()->user()->canAccessModule('invoices','read'));
    $canBookCylinder=$canBookCylinder && $canReadCylinders;
@endphp
@if(\App\Models\Cylinder::hasTable() && ($canBookCylinder || $canReadCylinders) && app(\App\Services\Cylinders\CylinderWorkflow::class)->rentalHasCylinder($rental))
<section class="rcc-cylinder-panel" aria-label="Cylinder refill and exchange">
    @php $pendingEmpty=$canReadCylinders && \App\Models\CylinderRefill::available()?(int)app(\App\Services\Cylinders\SimpleCylinderRefills::class)->query(auth()->user())->where('rental_id',$rental->id)->where('status','open')->sum(\Illuminate\Support\Facades\DB::raw('quantity - received_quantity')):0; @endphp
    <div class="rcc-cylinder-copy"><h3>Cylinder refill</h3><p>Book a refill, assign delivery and track the empty return here.</p><p class="cr-reminder" data-refill-pending-empty @if(!$pendingEmpty) hidden @endif role="status">{{ $pendingEmpty }} empty cylinder(s) still need to be received back.</p></div>
    <div class="rcc-cylinder-actions">
        @if($canBookCylinder)<a class="rcc-cylinder-action rcc-cylinder-action-primary" data-open-cylinder-refill href="{{ route('cylinder-refills.popup',$rental) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4"/></svg>Refill cylinder</a>@endif
        @if($canReadCylinders)<a class="rcc-cylinder-action" data-open-cylinder-refill href="{{ route('cylinder-refills.popup',$rental) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Refill history</a>@endif
    </div>
</section>
@endif
