<?php

namespace Tests\Feature\Regression;

use App\Models\{City, Customer, Product, Rental, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalMobileSubmissionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_rental_form_keeps_customer_product_and_submit_ownership(): void
    {
        $org = TestData::organization(['state' => 'Karnataka']);
        $this->actingAs(TestData::user($org));
        $city = City::create(['organization_id' => $org->id, 'name' => 'Bengaluru', 'is_active' => true]);
        Warehouse::create(['organization_id' => $org->id, 'city_id' => $city->id, 'name' => 'Main', 'is_active' => true]);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Synthetic rental customer',
            'phone' => '9876543210', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'address' => 'Synthetic address']);
        Product::create(['organization_id' => $org->id, 'name' => 'Synthetic untracked rental product',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10, 'total_quantity' => 10, 'price_per_day' => 100, 'rental_price' => 100]);
        $response = $this->get(route('rentals.create', ['customer_id' => $customer->id]))->assertOk();
        $response->assertSee('id="rentalCreateForm"', false)
            ->assertSee('id="rentalMobileSaveButton"', false)->assertSee('name="_token"', false);
        if ($folder = getenv('PHOS_RENTAL_MOBILE_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder . '/rental-create.html', $response->getContent());
        }
        $this->assertDatabaseCount('rentals', 0);
        $product = Product::firstOrFail();
        $rental = Rental::create(['organization_id' => $org->id, 'customer_id' => $customer->id,
            'product_id' => $product->id, 'customer_name' => $customer->name, 'phone' => $customer->phone,
            'quantity' => 1, 'start_date' => '2026-11-05', 'end_date' => '2026-11-10',
            'rental_amount' => 600, 'deposit_amount' => 0, 'status' => 'active']);
        $before = $rental->fresh()->getRawOriginal();
        $edit = $this->get(route('rentals.edit', $rental))->assertOk();
        $edit->assertSee('id="rentalCreateForm"', false)->assertSee('name="_method" value="PUT"', false);
        if ($folder) file_put_contents($folder . '/rental-edit.html', $edit->getContent());
        $this->assertSame($before, $rental->fresh()->getRawOriginal());
    }

    public function test_rental_save_still_creates_one_rental_and_rejects_missing_product(): void
    {
        $org = TestData::organization();
        $this->actingAs(TestData::user($org));
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Synthetic customer', 'phone' => '9876543210']);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Rental product',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10, 'total_quantity' => 10, 'price_per_day' => 100]);
        $data = ['customer_id' => $customer->id, 'customer_name' => $customer->name, 'phone' => $customer->phone,
            'product_id' => $product->id, 'quantity' => 1, 'start_date' => '2026-11-05', 'end_date' => '2026-11-10',
            'rental_amount' => 600, 'deposit_amount' => 0, 'transport_amount' => 0, 'other_amount' => 0, 'action' => 'save'];
        $this->post(route('rentals.store'), array_replace($data, ['product_id' => '']))->assertSessionHasErrors('product_id');
        $this->assertDatabaseCount('rentals', 0);
        $this->post(route('rentals.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('rentals', 1);
        $this->assertSame($customer->id, Rental::sole()->customer_id);
    }
}
