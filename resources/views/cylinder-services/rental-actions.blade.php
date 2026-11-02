@php
    $canBookCylinder = $canCreateDeliveries && $canUpdateRentals && $rental->status === 'active';
    $canReadCylinders = auth()->user()->canAccessModule('deliveries','read') || (auth()->user()->canViewDues() && auth()->user()->canAccessModule('rentals','read') && auth()->user()->canAccessModule('invoices','read'));
@endphp
@if(\App\Models\Cylinder::hasTable() && ($canBookCylinder || $canReadCylinders) && app(\App\Services\Cylinders\CylinderWorkflow::class)->rentalHasCylinder($rental))
<section class="rcc-cylinder-panel" aria-label="Cylinder refill and exchange">
    <div class="rcc-cylinder-copy"><h3>Cylinder refill &amp; exchange</h3><p>Exchange a full cylinder for an empty one, or refill and return the same cylinder.</p></div>
    <div class="rcc-cylinder-actions">
        @if($canBookCylinder)<a class="rcc-cylinder-action rcc-cylinder-action-primary" href="{{ route('cylinder-services.create',$rental) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4"/></svg>Book refill / exchange</a>@endif
        @if($canReadCylinders)<a class="rcc-cylinder-action" href="{{ route('cylinder-services.index',['rental_id'=>$rental->id]) }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>View cylinder history</a>@endif
    </div>
</section>
@endif
