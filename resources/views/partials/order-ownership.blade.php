@if($orderOwnership)
    <p class="flex flex-wrap gap-x-4 gap-y-1 break-words" data-order-ownership>
        <span><strong>Owner</strong> – {{ $orderOwnership['owner'] }}</span>
        <span><strong>Created by</strong> – {{ $orderOwnership['creator'] ?? 'Unavailable' }}</span>
    </p>
@endif
