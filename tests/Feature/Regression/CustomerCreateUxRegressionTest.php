<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerCreateUxRegressionTest extends TestCase
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
    }

    public function test_customer_create_page_uses_clean_direct_customer_flow_and_keeps_business_partner_separate(): void
    {
        $response = $this->get(route('customers.create'));

        $response->assertOk()
            ->assertSee('data-type-trigger="Individual"', false)
            ->assertSee('data-type-trigger="Business"', false)
            ->assertSee('Use Current Location')
            ->assertSee('Pick Location on Map')
            ->assertSee('GST Details')
            ->assertSee('ID Proof')
            ->assertDontSee('Business Partner / Tie-up');
    }

    public function test_quick_customer_create_supports_business_customer_gst_and_location(): void
    {
        $response = $this->postJson(route('customers.quick-store'), [
            'customer_type' => 'Business',
            'company_name' => 'Prime Diagnostics',
            'contact_name' => 'Operations Desk',
            'phone_country_code' => '+91',
            'phone' => '9876543210',
            'whatsapp_number_country_code' => '+91',
            'whatsapp_number' => '9988776655',
            'email' => 'ops@prime-diagnostics.test',
            'address' => 'No 12 Main Road',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
            'map_location_text' => 'MG Road',
            'map_location_url' => 'https://maps.google.com/?q=12.9716,77.5946',
            'gst_registered' => '1',
            'gst_number' => '29ABCDE1234F1Z5',
            'legal_name' => 'Prime Diagnostics Private Limited',
            'billing_address' => 'No 12 Main Road, Bengaluru, Karnataka, 560001',
        ]);

        $response->assertOk()
            ->assertJsonPath('customer.customer_type', 'Business')
            ->assertJsonPath('customer.gst_registered', true)
            ->assertJsonPath('customer.gst_number', '29ABCDE1234F1Z5')
            ->assertJsonPath('customer.legal_name', 'Prime Diagnostics Private Limited')
            ->assertJsonPath('customer.map_location_text', 'MG Road');

        $customer = Customer::query()->where('organization_id', $this->organizationId)->firstOrFail();

        $this->assertSame('Business', $customer->normalizedCustomerType());
        $this->assertTrue((bool) $customer->gst_registered);
        $this->assertSame('29ABCDE1234F1Z5', $customer->gst_number);
        $this->assertSame('Prime Diagnostics Private Limited', $customer->legal_name);
        $this->assertSame('No 12 Main Road, Bengaluru, Karnataka, 560001', $customer->billing_address);
        $this->assertSame('MG Road', $customer->map_location_text);
        $this->assertSame('https://maps.google.com/?q=12.9716,77.5946', $customer->map_location_url);
    }

    public function test_quick_customer_gst_requires_required_fields_when_enabled(): void
    {
        $response = $this->postJson(route('customers.quick-store'), [
            'customer_type' => 'Business',
            'company_name' => 'GST Missing Fields Co',
            'phone_country_code' => '+91',
            'phone' => '9876500000',
            'gst_registered' => '1',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gst_number', 'legal_name', 'billing_address']);
    }

    public function test_rental_and_sales_create_pages_include_clean_customer_modal(): void
    {
        $rental = $this->get(route('rentals.create'));
        $sale = $this->get(route('sales.create'));

        $rental->assertOk()
            ->assertSee('id="rentalQuickCustomerModal"', false)
            ->assertSee('data-quick-type-trigger="Individual"', false)
            ->assertSee('data-quick-type-trigger="Business"', false)
            ->assertSee('Google Maps Link / Location URL')
            ->assertSee('Use Current Location');

        $sale->assertOk()
            ->assertSee('id="saleQuickCustomerModal"', false)
            ->assertSee('data-quick-type-trigger="Individual"', false)
            ->assertSee('data-quick-type-trigger="Business"', false)
            ->assertSee('Google Maps Link / Location URL')
            ->assertSee('Use Current Location');
    }
}
