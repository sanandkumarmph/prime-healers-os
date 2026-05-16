<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerRentalPrefillRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization([
            'state' => 'Karnataka',
        ]);

        $this->organizationId = $organization->id;
        $this->actingAs(TestData::user($organization));

        Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'BiPAP Machine - Resmed Lumis 150',
            'brand' => 'Resmed',
            'model_name' => 'Lumis 150',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 3,
            'total_quantity' => 3,
            'price_per_day' => 700,
            'rental_price' => 700,
            'rental_price_15_days' => 9000,
            'rental_price_30_days' => 17000,
            'rental_price_3_months' => 45000,
            'sale_price' => 0,
        ]);
    }

    public function test_customer_show_create_rental_link_passes_customer_id(): void
    {
        $customer = $this->makeCustomer([
            'name' => 'Amit Iyer',
        ]);

        $response = $this->get(route('customers.show', $customer));

        $response->assertOk();
        $response->assertSee(route('rentals.create', ['customer_id' => $customer->id]), false);
    }

    public function test_rental_create_preselects_valid_customer_and_prefills_visible_fields(): void
    {
        $customer = $this->makeCustomer([
            'name' => 'Amit Iyer',
            'phone' => '+919739886843',
            'state' => 'Tamil Nadu',
        ]);

        $response = $this->get(route('rentals.create', ['customer_id' => $customer->id]));

        $response->assertOk();
        $customerSelectHtml = $this->extractSelectHtml($response->getContent(), 'customer_id');

        $this->assertStringContainsString('value="' . $customer->id . '"', $customerSelectHtml);
        $this->assertStringContainsString('data-state="' . e($customer->state) . '"', $customerSelectHtml);
        $this->assertStringContainsString('value="' . $customer->id . '"', $customerSelectHtml);
        $this->assertMatchesRegularExpression('/<option[^>]*value="' . preg_quote((string) $customer->id, '/') . '"[^>]*selected/i', $customerSelectHtml);
        $response->assertSee('value="Amit Iyer"', false);
        $response->assertSee('value="9739886843"', false);
        $response->assertSeeInOrder([
            'const organizationState = "Karnataka"',
            'const customerState = customerSelect?.selectedOptions?.[0]?.getAttribute(\'data-state\') || \'\'',
        ], false);
    }

    public function test_old_input_customer_id_takes_priority_over_query_prefill(): void
    {
        $queryCustomer = $this->makeCustomer([
            'name' => 'Query Customer',
            'phone' => '+919111111111',
        ]);

        $oldInputCustomer = $this->makeCustomer([
            'name' => 'Old Input Customer',
            'phone' => '+919222222222',
        ]);

        $response = $this->withSession([
            '_old_input' => [
                'customer_id' => $oldInputCustomer->id,
                'customer_name' => $oldInputCustomer->name,
                'phone' => '9222222222',
                'phone_country_code' => '+91',
            ],
        ])->get(route('rentals.create', ['customer_id' => $queryCustomer->id]));

        $response->assertOk();
        $customerSelectHtml = $this->extractSelectHtml($response->getContent(), 'customer_id');

        $this->assertMatchesRegularExpression('/<option[^>]*value="' . preg_quote((string) $oldInputCustomer->id, '/') . '"[^>]*selected/i', $customerSelectHtml);
        $response->assertSee('value="Old Input Customer"', false);
        $response->assertSee('value="9222222222"', false);
    }

    public function test_other_organization_customer_id_is_not_preloaded(): void
    {
        $otherOrganization = TestData::organization([
            'name' => 'Other Org',
            'state' => 'Tamil Nadu',
        ]);

        $otherCustomer = Customer::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Org Customer',
            'phone' => '+919999999999',
            'state' => 'Tamil Nadu',
        ]);

        $response = $this->get(route('rentals.create', ['customer_id' => $otherCustomer->id]));

        $response->assertOk();
        $customerSelectHtml = $this->extractSelectHtml($response->getContent(), 'customer_id');

        $response->assertDontSee('value="Other Org Customer"', false);
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*value="' . preg_quote((string) $otherCustomer->id, '/') . '"[^>]*selected/i', $customerSelectHtml);
    }

    public function test_normal_rental_create_without_customer_id_still_works(): void
    {
        $response = $this->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee('id="customer_id"', false);
        $customerSelectHtml = $this->extractSelectHtml($response->getContent(), 'customer_id');
        $this->assertDoesNotMatchRegularExpression('/<option[^>]*selected/i', $customerSelectHtml);
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $this->organizationId,
            'name' => 'Rental Customer',
            'phone' => '+919876543210',
            'email' => 'customer@example.test',
            'address' => '249, Kengeri',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560077',
        ], $attributes));
    }

    private function extractSelectHtml(string $html, string $selectId): string
    {
        $pattern = '/<select[^>]*id="' . preg_quote($selectId, '/') . '"[^>]*>(.*?)<\/select>/is';

        if (!preg_match($pattern, $html, $matches)) {
            $this->fail('Unable to find select with id "' . $selectId . '".');
        }

        return $matches[1];
    }
}
