<?php

namespace Tests\Feature\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesRentalSearchableSelectRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
    }

    public function test_sales_create_page_has_searchable_customer_and_product_selects(): void
    {
        $response = $this->get(route('sales.create'));

        $response->assertOk()
            ->assertSee('id="customer_id"', false)
            ->assertSee('id="product_id"', false)
            ->assertSee('data-searchable-select', false)
            ->assertSee('data-search-placeholder="Search customer by name or phone"', false)
            ->assertSee('data-search-placeholder="Search product by name, brand, model, SKU, or code"', false);
    }

    public function test_rentals_create_page_has_searchable_customer_and_product_selects(): void
    {
        $response = $this->get(route('rentals.create'));

        $response->assertOk()
            ->assertSee('id="customer_id"', false)
            ->assertSee('id="product_id"', false)
            ->assertSee('data-searchable-select', false)
            ->assertSee('data-search-placeholder="Search customer by name or phone"', false)
            ->assertSee('data-search-placeholder="Search product by name, brand, model, SKU, or code"', false);
    }
}
