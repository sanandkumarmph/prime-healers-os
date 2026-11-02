@if(\App\Models\CylinderRefill::available() && auth()->user()->canAccessModule('deliveries','read') && app(\App\Services\Cylinders\SimpleCylinderRefills::class)->driver(auth()->user()))
@php $refillTasks=app(\App\Services\Cylinders\SimpleCylinderRefills::class)->query(auth()->user())->where('status','open')->where(fn($q)=>$q->whereColumn('delivered_quantity','<','quantity')->orWhereColumn('collected_quantity','<','quantity'))->with(['product','rental.customer','assignee'])->orderBy('scheduled_at')->limit(6)->get(); @endphp
@if($refillTasks->isNotEmpty())<link rel="stylesheet" href="{{ asset('css/phos-cylinder-refills.css') }}"><script src="{{ asset('js/phos-cylinder-refills.js') }}" defer></script>
<section aria-label="My cylinder refill tasks"><h2>My cylinder refills &amp; empty pickup</h2>@include('cylinder-refills.tasks-content')</section>@endif
@endif
