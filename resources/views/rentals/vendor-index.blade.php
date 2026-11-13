@extends('layouts.app')

@section('content')
<section class="rounded-xl border bg-white p-4" aria-labelledby="assigned-rentals-heading">
    <h1 id="assigned-rentals-heading" class="mb-3 text-xl font-semibold">Assigned rentals</h1>
    <ul class="divide-y">
        @forelse($rentals as $rental)
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div>
                    <a class="font-semibold text-blue-700" href="{{ route('rentals.show', $rental) }}">Rental #{{ $rental->id }}</a>
                    <div>{{ $rental->deliveryContactName() }}</div>
                </div>
                <span>{{ $rental->product?->name }}</span>
            </li>
        @empty
            <li class="py-3">No rentals linked to your assigned tasks.</li>
        @endforelse
    </ul>
    {{ $rentals->links() }}
</section>
@endsection
