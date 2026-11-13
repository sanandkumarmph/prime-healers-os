@extends('layouts.app')

@section('content')
<div class="space-y-3">
    <section class="rounded-xl border bg-white p-4" aria-labelledby="vendor-rental-heading">
        <a class="text-blue-700" href="{{ route('rentals.index') }}">Assigned rentals</a>
        <h1 id="vendor-rental-heading" class="mt-2 text-xl font-semibold">Rental #{{ $rental->id }}</h1>
        <p>{{ $rental->deliveryContactName() }}</p>
        @if($phone = $rental->deliveryContactPhone())
            <a class="text-blue-700" href="tel:{{ $phone }}">{{ $phone }}</a>
        @endif
        @if($address = $rental->deliveryContactAddress())
            <p>{{ $address }}</p>
        @endif
        @if($city = $rental->deliveryContactCity())
            <p>{{ $city }}</p>
        @endif
        @if($map = $rental->deliveryContactMapUrl())
            <a class="text-blue-700" href="{{ $map }}" target="_blank" rel="noopener noreferrer">Open map</a>
        @endif
    </section>
    <section class="rounded-xl border bg-white p-4" aria-labelledby="vendor-rental-items">
        <h2 id="vendor-rental-items" class="font-semibold">Rental items</h2>
        <ul class="divide-y">
            @foreach($rental->displayRentalItems() as $item)
                <li class="py-2">{{ $item->product?->name ?? 'Rental equipment' }} · {{ $item->quantity }}</li>
            @endforeach
        </ul>
    </section>
    <section class="rounded-xl border bg-white p-4" aria-labelledby="vendor-rental-tasks">
        <h2 id="vendor-rental-tasks" class="font-semibold">Assigned tasks</h2>
        <ul class="divide-y">
            @foreach($tasks as $task)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                    <a class="text-blue-700" href="{{ route('deliveries.show', $task) }}">{{ ucfirst($task->type) }} #{{ $task->id }}</a>
                    <span>{{ ucfirst(str_replace('_', ' ', $task->status)) }}</span>
                    @if($task->scheduled_at)
                        <span>{{ $task->scheduled_at->format('d M Y, h:i A') }} IST</span>
                    @endif
                </li>
            @endforeach
            @foreach($cylinderJobs as $job)
                <li class="py-2">
                    <a class="text-blue-700" href="{{ route('cylinder-services.show', $job) }}">Cylinder service #{{ $job->id }}</a>
                </li>
            @endforeach
            @if($hasRefillTasks)
                <li class="py-2">
                    <a class="text-blue-700" href="{{ route('cylinder-refills.popup', $rental) }}">Cylinder refill tasks</a>
                </li>
            @endif
        </ul>
    </section>
</div>
@endsection
