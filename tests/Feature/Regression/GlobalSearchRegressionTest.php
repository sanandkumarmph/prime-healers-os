<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Product;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class GlobalSearchRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_finds_customer_by_name(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Anika Sharma',
            'first_name' => 'Anika',
            'phone' => PhoneNumber::normalize('9876500100', '+91'),
            'email' => 'anika@example.com',
        ]);

        $response = $this->get(route('search.global', ['q' => 'Anika']));

        $response->assertOk()
            ->assertSee('Customers')
            ->assertSee('Anika Sharma');
    }

    public function test_global_search_finds_customer_by_phone(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Rohan Mehta',
            'first_name' => 'Rohan',
            'phone' => PhoneNumber::normalize('9876500200', '+91'),
            'email' => 'rohan@example.com',
        ]);

        $response = $this->get(route('search.global', ['q' => '9876500200']));

        $response->assertOk()
            ->assertSee('Rohan Mehta');
    }

    public function test_global_search_finds_product_by_name_or_model(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'brand' => 'PrimeCare',
            'model_name' => 'OC-10',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'total_quantity' => 5,
            'available_quantity' => 5,
            'price_per_day' => 250,
            'rental_price' => 250,
        ]);

        $response = $this->get(route('search.global', ['q' => 'OC-10']));

        $response->assertOk()
            ->assertSee('Medical Equipment')
            ->assertSee('Oxygen Concentrator');
    }

    public function test_global_search_does_not_return_records_from_other_organizations(): void
    {
        $organizationA = TestData::organization(['name' => 'Org A']);
        $organizationB = TestData::organization(['name' => 'Org B']);
        $this->actingAs(TestData::user($organizationA));

        Customer::create([
            'organization_id' => $organizationA->id,
            'customer_type' => 'Individual',
            'name' => 'Visible Customer',
            'first_name' => 'Visible',
            'phone' => PhoneNumber::normalize('9876500300', '+91'),
            'email' => 'visible@example.com',
        ]);

        Customer::create([
            'organization_id' => $organizationB->id,
            'customer_type' => 'Individual',
            'name' => 'Hidden Customer',
            'first_name' => 'Hidden',
            'phone' => PhoneNumber::normalize('9876500399', '+91'),
            'email' => 'hidden@example.com',
        ]);

        $response = $this->get(route('search.global', ['q' => 'Customer']));

        $response->assertOk()
            ->assertSee('Visible Customer')
            ->assertDontSee('Hidden Customer');
    }
}
