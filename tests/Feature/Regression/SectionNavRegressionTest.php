<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SectionNavRegressionTest extends TestCase
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

    public function test_create_pages_render_sticky_section_navigation(): void
    {
        $this->get(route('rentals.create'))
            ->assertOk()
            ->assertSee('data-section-nav', false)
            ->assertSee('Rental form sections')
            ->assertSee('href="#rental-customer-section"', false)
            ->assertSee('href="#rental-assets-section"', false);

        $this->get(route('sales.create'))
            ->assertOk()
            ->assertSee('data-section-nav', false)
            ->assertSee('Sale form sections')
            ->assertSee('href="#sale-details-section"', false)
            ->assertSee('href="#sale-pricing-section"', false);
    }

    public function test_show_pages_render_section_navigation_with_real_targets(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Rohit Gupta',
            'phone' => '9556722014',
            'address' => '109, HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
            'map_url' => 'https://maps.example.test/customer',
        ]);

        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Portea',
            'contact_person' => 'Billing Desk',
            'phone' => '+919556722014',
            'whatsapp' => '+919556722014',
            'email' => 'portea@example.test',
            'address' => '45 Business Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560034',
            'location' => 'https://maps.example.test/partner',
            'status' => 'active',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Lakshmi Home Care',
            'phone' => '+919955667788',
            'address' => '22 Lake Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560095',
            'location' => 'https://maps.example.test/client',
            'delivery_notes' => 'Use side gate',
            'status' => 'active',
        ]);

        $rentalProduct = Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Wheelchair',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10,
            'total_quantity' => 10,
            'status' => 'active',
            'rental_price' => 1500,
            'rental_price_15_days' => 1500,
            'rental_price_30_days' => 3000,
            'rental_price_3_months' => 9000,
            'sale_price' => 0,
            'price_per_day' => 75,
        ]);

        $saleProduct = Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Oxygen Concentrator',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 12,
            'total_quantity' => 12,
            'status' => 'active',
            'sale_price' => 45000,
            'rental_price' => 0,
            'price_per_day' => 0,
            'price' => 45000,
        ]);

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $rentalProduct->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 300,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        $sale = Sale::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'product_id' => $saleProduct->id,
            'quantity' => 1,
            'unit_price' => 45000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 45000,
            'payment_status' => 'pending',
        ]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Customer page sections')
            ->assertSee('href="#customer-overview-section"', false)
            ->assertSee('href="#customer-timeline"', false);

        $this->get(route('rentals.show', $rental))
            ->assertOk()
            ->assertSee('Rental page sections')
            ->assertSee('href="#rental-billing-actions"', false)
            ->assertSee('href="#rental-activity-timeline"', false);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Sale page sections')
            ->assertSee('href="#sale-billing-actions"', false)
            ->assertSee('href="#sale-activity-timeline"', false);

        $this->get(route('business-partners.show', $partner))
            ->assertOk()
            ->assertSee('Business partner page sections')
            ->assertSee('href="#actual-clients"', false)
            ->assertSee('href="#business-partner-timeline"', false);
    }
}
