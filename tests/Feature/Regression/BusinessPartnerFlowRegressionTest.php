<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class BusinessPartnerFlowRegressionTest extends TestCase
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

    public function test_rental_and_sales_create_default_to_direct_customer_and_show_partner_fields(): void
    {
        $rentalCreate = $this->get(route('rentals.create'));
        $saleCreate = $this->get(route('sales.create'));

        $rentalCreate->assertOk()
            ->assertSee('name="customer_type"', false)
            ->assertSee('value="direct_customer" selected', false)
            ->assertSee('name="business_partner_id"', false)
            ->assertSee('name="partner_client_id"', false);

        $saleCreate->assertOk()
            ->assertSee('name="customer_type"', false)
            ->assertSee('value="direct_customer" selected', false)
            ->assertSee('name="business_partner_id"', false)
            ->assertSee('name="partner_client_id"', false);
    }

    public function test_business_partner_rental_creation_uses_partner_for_reminders_and_client_for_delivery(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeRentalProduct();

        $this->post(route('rentals.store'), [
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-25',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_IGST,
        ])->assertRedirect(route('rentals.index'));

        $rental = Rental::query()
            ->where('organization_id', $this->organizationId)
            ->with(['businessPartner', 'partnerClient', 'customer'])
            ->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $this->organizationId)->firstOrFail();

        $this->assertSame('business_partner', $rental->customer_type);
        $this->assertNull($rental->customer_id);
        $this->assertSame($partner->id, $rental->business_partner_id);
        $this->assertSame($client->id, $rental->partner_client_id);
        $this->assertSame($client->displayName(), $rental->customer_name);
        $this->assertSame($partner->displayName(), $rental->billingContactName());
        $this->assertSame($client->displayName(), $rental->deliveryContactName());
        $this->assertSame($partner->phone, $invoice->bill_to_phone);
        $this->assertSame($client->address, $invoice->ship_to_address);
        $this->assertSame($client->state, $invoice->place_of_supply_state);

        $reminderResponse = $this->get(route('rentals.reminders.open', [$rental, 'renewal']));
        $reminderResponse->assertRedirect();
        $this->assertStringContainsString(preg_replace('/\D+/', '', $partner->whatsapp), $reminderResponse->headers->get('Location', ''));
    }

    public function test_business_partner_sale_creation_uses_partner_for_invoice_and_client_for_delivery(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeSaleProduct();

        $this->post(route('sales.store'), [
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'sale_date' => '2026-05-18',
            'payment_status' => 'pending',
            'shipping_charges' => 0,
            'sale_items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 5000,
                'discount_amount' => 0,
                'tax_percentage' => 18,
                'tax_calculation_mode' => 'exclusive',
                'tax_type' => Product::GST_TAX_TYPE_IGST,
            ]],
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::query()
            ->where('organization_id', $this->organizationId)
            ->with(['businessPartner', 'partnerClient', 'customer'])
            ->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $this->assertSame('business_partner', $sale->customer_type);
        $this->assertNull($sale->customer_id);
        $this->assertSame($partner->id, $sale->business_partner_id);
        $this->assertSame($client->id, $sale->partner_client_id);
        $this->assertSame($partner->displayName(), $sale->billingContactName());
        $this->assertSame($client->displayName(), $sale->deliveryContactName());
        $this->assertSame($partner->phone, $invoice->bill_to_phone);
        $this->assertSame($client->address, $invoice->ship_to_address);
        $this->assertSame($client->state, $invoice->place_of_supply_state);
    }

    public function test_delivery_show_uses_actual_client_details_for_business_partner_orders(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeRentalProduct();

        $this->post(route('rentals.store'), [
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-20',
            'end_date' => '2026-05-27',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_IGST,
        ])->assertRedirect(route('rentals.index'));

        $rental = Rental::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $delivery = Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'pending',
        ]);

        $response = $this->get(route('deliveries.show', $delivery));

        $response->assertOk()
            ->assertSee($client->displayName())
            ->assertSee($client->primaryPhone())
            ->assertSee($client->address)
            ->assertSee($client->city);
    }

    public function test_other_organization_business_partner_is_rejected(): void
    {
        $otherOrg = TestData::organization(['name' => 'Other Org']);
        $otherPartner = BusinessPartner::create([
            'organization_id' => $otherOrg->id,
            'business_name' => 'Outside Partner',
            'phone' => '+919999000001',
            'status' => 'active',
        ]);
        $otherClient = PartnerClient::create([
            'organization_id' => $otherOrg->id,
            'business_partner_id' => $otherPartner->id,
            'client_name' => 'Outside Client',
            'phone' => '+919999000002',
            'status' => 'active',
        ]);

        $product = $this->makeRentalProduct();

        $response = $this->from(route('rentals.create'))->post(route('rentals.store'), [
            'customer_type' => 'business_partner',
            'business_partner_id' => $otherPartner->id,
            'partner_client_id' => $otherClient->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-25',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        $response->assertRedirect(route('rentals.create'));
        $response->assertSessionHasErrors(['business_partner_id', 'partner_client_id']);
    }

    private function makeBusinessPartnerContext(): array
    {
        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Apollo Tie-up',
            'contact_person' => 'Operations Desk',
            'phone' => '+919811112222',
            'whatsapp' => '+919811112222',
            'email' => 'apollo@example.test',
            'address' => 'Billing Street 1',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Rajesh Kumar',
            'phone' => '+919822223333',
            'address' => '12 Patient Home',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'delivery_notes' => 'Ring bell and call family contact.',
            'status' => 'active',
        ]);

        return [$partner, $client];
    }

    private function makeRentalProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 500,
            'rental_price' => 1000,
            'rental_price_15_days' => 1000,
            'rental_price_30_days' => 2000,
            'rental_price_3_months' => 5000,
            'sale_price' => 0,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
        ]);
    }

    private function makeSaleProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Oxygen Concentrator',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'sale_price' => 5000,
            'rental_price' => 0,
            'price_per_day' => 0,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
        ]);
    }
}
