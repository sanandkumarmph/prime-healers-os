<?php

namespace Tests\Feature\Regression;

use App\Models\ActivityLog;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class TimelineActivityRegressionTest extends TestCase
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

    public function test_customer_timeline_loads_rental_and_payment_activity(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeRentalProduct();

        $this->post(route('rentals.store'), [
            'customer_type' => 'direct_customer',
            'customer_id' => $customer->id,
            'customer_name' => $customer->displayName(),
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-25',
            'rental_amount' => 2500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = Rental::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $this->post(route('payments.store', $rental), [
            'amount' => 2000,
            'payment_date' => '2026-05-19',
            'payment_method' => 'upi',
            'notes' => 'Advance payment received.',
        ])->assertRedirect(route('rentals.show', $rental));

        $response = $this->get(route('customers.show', $customer));

        $response->assertOk()
            ->assertSee('Timeline')
            ->assertSee('Rental created')
            ->assertSee('Payment received');
    }

    public function test_rental_timeline_notes_filter_shows_added_note(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeRentalProduct();

        $this->post(route('rentals.store'), [
            'customer_type' => 'direct_customer',
            'customer_id' => $customer->id,
            'customer_name' => $customer->displayName(),
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-25',
            'rental_amount' => 2500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = Rental::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $this->post(route('rentals.notes.store', $rental), [
            'note_type' => 'follow-up',
            'note' => 'Customer requested a callback before dispatch.',
        ])->assertRedirect();

        $response = $this->get(route('rentals.show', [
            'rental' => $rental,
            'timeline_filter' => 'notes',
        ]));

        $response->assertOk()
            ->assertSee('Customer requested a callback before dispatch.')
            ->assertDontSee('Rental created and invoice generated.');
    }

    public function test_sale_timeline_loads_created_activity(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeSaleProduct();

        $this->post(route('sales.store'), [
            'customer_type' => 'direct_customer',
            'customer_id' => $customer->id,
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
                'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            ]],
        ])->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

        $sale = Sale::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $response = $this->get(route('sales.show', $sale));

        $response->assertOk()
            ->assertSee('Sale created and invoice generated.')
            ->assertSee($customer->displayName());
    }

    public function test_business_partner_timeline_shows_actual_client_and_reminder_relationship(): void
    {
        $this->postJson(route('business-partners.store'), [
            'business_name' => 'Portea',
            'contact_person' => 'Coordination Desk',
            'phone' => '+919811110001',
            'whatsapp' => '+919811110001',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
        ])->assertOk();

        $partner = BusinessPartner::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $this->postJson(route('business-partners.clients.store', $partner), [
            'client_name' => 'Mr. Ramesh',
            'phone' => '+919822220002',
            'address' => 'Indiranagar, Bangalore',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'delivery_notes' => 'Ring once before entering.',
        ])->assertOk();

        $client = PartnerClient::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();
        $product = $this->makeRentalProduct();

        $this->post(route('rentals.store'), [
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-25',
            'rental_amount' => 2500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = Rental::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $this->post(route('renewal-center.mark-reminder-sent', $rental))->assertRedirect();

        $response = $this->get(route('business-partners.show', $partner));

        $response->assertOk()
            ->assertSee('Actual client added')
            ->assertSee('Renewal reminder sent')
            ->assertSee('Reminder To:')
            ->assertSee('Portea')
            ->assertSee('Service Location:')
            ->assertSee('Mr. Ramesh');
    }

    public function test_customer_timeline_is_scoped_to_organization_and_page_load_does_not_duplicate_logs(): void
    {
        $customer = $this->makeCustomer();

        $this->post(route('customers.notes.store', $customer->id), [
            'note_type' => 'general',
            'note' => 'First customer note.',
        ])->assertRedirect();

        $countBefore = ActivityLog::query()->where('organization_id', $this->organizationId)->count();

        $this->get(route('customers.show', $customer))->assertOk();
        $this->get(route('customers.show', $customer))->assertOk();

        $this->assertSame($countBefore, ActivityLog::query()->where('organization_id', $this->organizationId)->count());

        $otherOrganization = TestData::organization(['name' => 'Other Org']);
        $otherUser = TestData::user($otherOrganization, ['email' => 'other-org-timeline@example.test']);

        $this->actingAs($otherUser)
            ->get(route('customers.show', $customer))
            ->assertNotFound();
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'Individual',
            'name' => 'Ramesh Kumar',
            'first_name' => 'Ramesh',
            'last_name' => 'Kumar',
            'phone' => '+919900001234',
            'whatsapp_number' => '+919900001234',
            'address' => '12 Health Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
            'map_location_url' => 'https://maps.google.com/?q=12.9716,77.5946',
        ]);
    }

    private function makeRentalProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Wheelchair',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 500,
            'rental_price' => 2500,
            'rental_price_15_days' => 2500,
            'rental_price_30_days' => 5000,
            'rental_price_3_months' => 12000,
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
