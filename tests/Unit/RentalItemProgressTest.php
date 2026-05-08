<?php

use App\Models\Rental;
use App\Models\RentalItem;
use Illuminate\Support\Collection;

beforeEach(function () {
    $reflection = new ReflectionClass(Rental::class);
    $property = $reflection->getProperty('hasRentalItemsTable');
    $property->setAccessible(true);
    $property->setValue(null, true);
});

it('calculates rental item delivery and pickup progress quantities', function () {
    $item = new RentalItem([
        'quantity' => 4,
        'delivered_quantity' => 2,
        'returned_quantity' => 1,
    ]);

    expect($item->ordered_quantity)->toBe(4)
        ->and($item->delivered_quantity_value)->toBe(2)
        ->and($item->returned_quantity_value)->toBe(1)
        ->and($item->pending_delivery_quantity)->toBe(2)
        ->and($item->pending_pickup_quantity)->toBe(1)
        ->and($item->delivery_progress_status)->toBe('partial')
        ->and($item->pickup_progress_status)->toBe('partial');
});

it('calculates rental level partial and completed progress from rental items', function () {
    $rental = new Rental(['status' => 'active']);
    $rental->setRelation('rentalItems', new Collection([
        new RentalItem([
            'quantity' => 2,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
        ]),
        new RentalItem([
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 1,
        ]),
    ]));

    expect($rental->deliveryStatus())->toBe('partially_delivered')
        ->and($rental->pickupStatus())->toBe('partial_return')
        ->and($rental->pendingDeliveryQuantityTotal())->toBe(1)
        ->and($rental->pendingPickupQuantityTotal())->toBe(1);

    $completedRental = new Rental(['status' => 'active']);
    $completedRental->setRelation('rentalItems', new Collection([
        new RentalItem([
            'quantity' => 2,
            'delivered_quantity' => 2,
            'returned_quantity' => 2,
        ]),
    ]));

    expect($completedRental->deliveryStatus())->toBe('completed')
        ->and($completedRental->pickupStatus())->toBe('completed')
        ->and($completedRental->pendingDeliveryQuantityTotal())->toBe(0)
        ->and($completedRental->pendingPickupQuantityTotal())->toBe(0);
});
