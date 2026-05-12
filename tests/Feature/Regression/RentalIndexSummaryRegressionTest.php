<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalIndexSummaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_summary_cards_remain_stable_when_list_filter_is_active(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Summary Customer',
            'phone' => '9876543201',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Summary Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 500,
            'rental_price' => 500,
            'sale_price' => 0,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $activeRental = $this->makeRental($organization->id, $customer->id, $product->id, [
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);

        $overdueRental = $this->makeRental($organization->id, $customer->id, $product->id, [
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);

        $returnedRental = $this->makeRental($organization->id, $customer->id, $product->id, [
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'returned',
            'returned_at' => now()->subDay(),
        ]);

        $this->completeDeliveryLifecycle($organization->id, $activeRental->id);
        $this->completeDeliveryLifecycle($organization->id, $overdueRental->id);

        $baseline = $this->get(route('rentals.index'));
        $baseline->assertOk();
        $this->assertSame(3, $baseline->viewData('totalRentals'));
        $this->assertSame(1, $baseline->viewData('activeRentals'));
        $this->assertSame(1, $baseline->viewData('overdueCount'));
        $this->assertSame(1, $baseline->viewData('returnedRentals'));

        $activeFiltered = $this->get(route('rentals.index', ['status' => 'active']));
        $activeFiltered->assertOk();
        $this->assertSame(3, $activeFiltered->viewData('totalRentals'));
        $this->assertSame(1, $activeFiltered->viewData('activeRentals'));
        $this->assertSame(1, $activeFiltered->viewData('overdueCount'));
        $this->assertSame(1, $activeFiltered->viewData('returnedRentals'));
        $this->assertSame(1, $activeFiltered->viewData('rentals')->total());

        $overdueFiltered = $this->get(route('rentals.index', ['filter' => 'overdue']));
        $overdueFiltered->assertOk();
        $this->assertSame(3, $overdueFiltered->viewData('totalRentals'));
        $this->assertSame(1, $overdueFiltered->viewData('activeRentals'));
        $this->assertSame(1, $overdueFiltered->viewData('overdueCount'));
        $this->assertSame(1, $overdueFiltered->viewData('returnedRentals'));
        $this->assertSame(1, $overdueFiltered->viewData('rentals')->total());
    }

    private function makeRental(int $organizationId, int $customerId, int $productId, array $overrides = []): Rental
    {
        return Rental::create(array_merge([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => 1,
            'customer_name' => 'Rental Summary Customer',
            'phone' => '9876543201',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'rental_amount' => 500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function completeDeliveryLifecycle(int $organizationId, int $rentalId): void
    {
        Delivery::create([
            'organization_id' => $organizationId,
            'rental_id' => $rentalId,
            'type' => 'delivery',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);
    }
}
