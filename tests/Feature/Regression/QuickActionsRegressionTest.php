<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class QuickActionsRegressionTest extends TestCase
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

    public function test_customer_show_renders_standardized_quick_actions(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Rohit Gupta',
            'phone' => '9556722014',
            'whatsapp_number' => '9556722014',
            'address' => '109, HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
            'map_url' => 'https://maps.example.test/customer',
        ]);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSeeText('Customer Quick Actions')
            ->assertSeeText('Call')
            ->assertSeeText('WhatsApp')
            ->assertSeeText('New Rental')
            ->assertSeeText('New Sale')
            ->assertSeeText('View Timeline');
    }

    public function test_rental_and_sale_quick_actions_keep_business_partner_contact_separation(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeProduct();

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'customer_id' => null,
            'customer_name' => $client->displayName(),
            'phone' => $client->primaryPhone(),
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
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
            'customer_id' => null,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 15000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 15000,
            'payment_status' => 'pending',
        ]);

        $rentalResponse = $this->get(route('rentals.show', $rental));
        $rentalResponse->assertOk()
            ->assertSeeText('Rental Quick Actions')
            ->assertSeeText('Reminder / Payment')
            ->assertSeeText($partner->displayName())
            ->assertSeeText('Delivery / Pickup')
            ->assertSeeText($client->displayName())
            ->assertSeeText('View Business Partner')
            ->assertSeeText('View Actual Client');

        $saleResponse = $this->get(route('sales.show', $sale));
        $saleResponse->assertOk()
            ->assertSeeText('Sale Quick Actions')
            ->assertSeeText('Reminder / Payment')
            ->assertSeeText($partner->displayName())
            ->assertSeeText('Delivery / Service')
            ->assertSeeText($client->displayName())
            ->assertSeeText('View Business Partner')
            ->assertSeeText('View Actual Client');
    }

    public function test_business_partner_show_and_invoice_show_render_quick_action_toolbars(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeProduct();

        $sale = Sale::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'customer_id' => null,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 12000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 12000,
            'payment_status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $this->organizationId,
            'invoice_number' => 'INV-QA-' . uniqid(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'sale_id' => $sale->id,
            'bill_to_name' => $partner->displayName(),
            'bill_to_phone' => $partner->phone,
            'bill_to_address' => $partner->address,
            'bill_to_city' => $partner->city,
            'bill_to_state' => $partner->state,
            'bill_to_pincode' => $partner->pincode,
            'ship_to_name' => $client->displayName(),
            'ship_to_phone' => $client->primaryPhone(),
            'ship_to_address' => $client->address,
            'ship_to_city' => $client->city,
            'ship_to_state' => $client->state,
            'ship_to_pincode' => $client->pincode,
            'place_of_supply_state' => $partner->state,
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'subtotal' => 12000,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 12000,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 12000,
            'paid_amount' => 0,
            'balance_amount' => 12000,
        ]);

        $this->get(route('business-partners.show', $partner))
            ->assertOk()
            ->assertSeeText('Business Partner Quick Actions')
            ->assertSeeText('Add Actual Client')
            ->assertSeeText('New Rental')
            ->assertSeeText('New Sale')
            ->assertSeeText('Open Map');

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSeeText('Invoice Quick Actions')
            ->assertSeeText('WhatsApp Invoice')
            ->assertSeeText('Print')
            ->assertSeeText('Payment')
            ->assertSeeText('Timeline')
            ->assertSeeText('View Sale');
    }

    private function makeBusinessPartnerContext(): array
    {
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
            'client_name' => 'Rohit Gupta',
            'phone' => '+919556722014',
            'address' => '109, HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
            'location' => 'https://maps.example.test/client',
            'delivery_notes' => 'Use side entrance',
            'status' => 'active',
        ]);

        return [$partner, $client];
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Wheelchair',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10,
            'total_quantity' => 10,
            'price_per_day' => 350,
            'rental_price' => 1500,
            'sale_price' => 12000,
        ]);
    }
}
