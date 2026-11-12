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
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
            ->assertSee('data-customer-type-option="direct_customer"', false)
            ->assertSee('name="business_partner_id"', false)
            ->assertSee('name="partner_client_id"', false)
            ->assertSee('data-open-modal="rentalBusinessPartnerModal"', false)
            ->assertSee('Select a business partner to continue.')
            ->assertSee('Select or add an actual delivery client.')
            ->assertSee('Customer Summary')
            ->assertSee('Open Map')
            ->assertDontSee('Contact Usage')
            ->assertDontSee('Delivery Contact Name');

        $saleCreate->assertOk()
            ->assertSee('name="customer_type"', false)
            ->assertSee('data-sales-customer-type-option="direct_customer"', false)
            ->assertSee('name="business_partner_id"', false)
            ->assertSee('name="partner_client_id"', false)
            ->assertSee('data-open-modal="saleBusinessPartnerModal"', false)
            ->assertSee('Select a business partner to continue.')
            ->assertSee('Select or add an actual delivery client.')
            ->assertSee('Customer Summary')
            ->assertSee('Open Map')
            ->assertDontSee('Contact Usage');
    }

    public function test_rental_and_sales_create_fallback_to_direct_customer_when_partner_tables_are_missing(): void
    {
        Schema::dropIfExists('partner_clients');
        Schema::dropIfExists('business_partners');

        $rentalCreate = $this->get(route('rentals.create'));
        $saleCreate = $this->get(route('sales.create'));

        $rentalCreate->assertOk()
            ->assertSee('Business Partner', false)
            ->assertSee('Business Partner setup pending.', false)
            ->assertSee('aria-disabled="true"', false);

        $saleCreate->assertOk()
            ->assertSee('Business Partner', false)
            ->assertSee('Business Partner setup pending.', false)
            ->assertSee('aria-disabled="true"', false);
    }

    public function test_rental_and_sales_create_load_with_customer_and_partner_selectors_even_when_location_fields_are_nullable(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Direct Care Customer',
            'phone' => '+919900000111',
            'email' => 'direct@example.test',
            'address' => '42 Green Avenue',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
            'map_location_text' => null,
            'map_location_url' => null,
        ]);

        [$partner, $client] = $this->makeBusinessPartnerContext('Selector Partner', 'Selector Client');
        $partner->update(['location' => null]);
        $client->update(['location' => null, 'latitude' => null, 'longitude' => null]);

        $rentalCreate = $this->get(route('rentals.create', ['customer_id' => $customer->id]));
        $saleCreate = $this->get(route('sales.create'));

        $rentalCreate->assertOk()
            ->assertSee($customer->displayName())
            ->assertSee($partner->displayName());

        $saleCreate->assertOk()
            ->assertSee($customer->displayName())
            ->assertSee($partner->displayName());
    }

    public function test_business_partner_and_actual_client_can_be_created_inline_over_json(): void
    {
        $partnerResponse = $this->postJson(route('business-partners.store'), [
            'business_name' => 'Care Network',
            'contact_person' => 'Nisha',
            'phone' => '+919900001111',
            'email' => 'care-network@example.test',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
        ]);

        $partnerResponse->assertOk()
            ->assertJsonPath('business_partner.name', 'Care Network');

        $partner = BusinessPartner::query()->where('organization_id', $this->organizationId)->firstOrFail();

        $clientResponse = $this->postJson(route('business-partners.clients.store', $partner), [
            'client_name' => 'Lakshmi Home',
            'phone' => '+919900002222',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'address' => 'Door 12, Lake View',
            'delivery_notes' => 'Use side gate',
        ]);

        $clientResponse->assertOk()
            ->assertJsonPath('partner_client.business_partner_id', $partner->id)
            ->assertJsonPath('partner_client.name', 'Lakshmi Home');
    }

    public function test_rental_and_sales_actual_client_lookup_returns_only_selected_partner_clients(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        [$otherPartner] = $this->makeBusinessPartnerContext('Other Partner', 'Other Client');

        $rentalLookup = $this->getJson(route('rentals.business-partners.actual-clients', $partner));
        $saleLookup = $this->getJson(route('sales.business-partners.actual-clients', $partner));

        $rentalLookup->assertOk()
            ->assertJsonCount(1, 'partner_clients')
            ->assertJsonPath('partner_clients.0.id', $client->id);

        $saleLookup->assertOk()
            ->assertJsonCount(1, 'partner_clients')
            ->assertJsonPath('partner_clients.0.id', $client->id);

        $this->assertNotSame($partner->id, $otherPartner->id);
    }

    public function test_sales_staff_can_open_and_save_both_order_types_from_an_edited_actual_client(): void
    {
        [$partner] = $this->makeBusinessPartnerContext();
        $this->actingAs(TestData::user($partner->organization, ['role' => User::ROLE_SALES]));

        $profile = route('business-partners.show', $partner);
        $clientDetails = [
            'client_name' => 'Synthetic order recipient',
            'phone' => '+12025550141',
            'alternate_phone' => '+12025550142',
            'address' => 'Original synthetic delivery address',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'pincode' => '600001',
            'status' => 'active',
        ];
        $this->get(route('business-partners.clients.create', $partner))->assertOk();
        $this->post(route('business-partners.clients.store', $partner), $clientDetails)
            ->assertRedirect($profile)->assertSessionHasNoErrors();
        $client = $partner->partnerClients()->where('client_name', $clientDetails['client_name'])->sole();

        $edit = route('business-partners.clients.edit', [$partner, $client]);
        $this->get($edit)->assertOk()->assertSee($clientDetails['address']);
        $updatedDetails = array_replace($clientDetails, [
            'address' => 'Updated synthetic delivery address',
            'location' => 'https://maps.google.com/?q=13.0827001,80.2707001',
            'latitude' => '13.0827001',
            'longitude' => '80.2707001',
            'delivery_notes' => 'Synthetic access instruction',
        ]);
        $this->put(route('business-partners.clients.update', [$partner, $client]), $updatedDetails)
            ->assertRedirect($profile)->assertSessionHasNoErrors();
        $client->refresh();

        $orderContext = ['customer_type' => 'business_partner', 'business_partner_id' => $partner->id, 'partner_client_id' => $client->id];
        $rentalUrl = route('rentals.create', $orderContext);
        $saleUrl = route('sales.create', $orderContext);
        $this->get($profile)->assertOk()->assertSee($client->displayName())
            ->assertSee($rentalUrl)->assertSee($saleUrl)->assertSee($client->address);

        foreach ([$rentalUrl => '', $saleUrl => 'sale'] as $url => $prefix) {
            $form = $this->orderFormDom($this->get($url)->assertOk()->getContent());
            foreach ($orderContext as $field => $value) {
                $this->assertSame((string) $value, $form->evaluate('string(//select[@id="' . $field . '"]/option[@selected]/@value)'));
            }
            foreach ([
                'ReminderContactSummary' => $partner->displayName(),
                'DeliveryContactSummary' => $client->displayName(),
                'DeliveryAddressSummary' => $client->address,
                'DeliveryNotesSummary' => $client->delivery_notes,
            ] as $summary => $value) {
                $elementId = $prefix ? $prefix . $summary : lcfirst($summary);
                $this->assertStringContainsString($value, $form->evaluate('string(//*[@id="' . $elementId . '"])'));
            }
            $mapId = $prefix ? 'saleDeliveryOpenMapLink' : 'deliveryOpenMapLink';
            $this->assertSame($client->openMapUrl(), $form->evaluate('string(//a[@id="' . $mapId . '"]/@href)'));
        }

        $start = now()->addDay();
        $this->post(route('rentals.store'), $orderContext + [
            'product_id' => $this->makeRentalProduct()->id,
            'quantity' => 1,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(7)->toDateString(),
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('sales.store'), $orderContext + [
            'sale_date' => now()->toDateString(),
            'payment_status' => 'pending',
            'shipping_charges' => 0,
            'sale_items' => [[
                'product_id' => $this->makeSaleProduct()->id,
                'quantity' => 1,
                'unit_price' => 5000,
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        foreach ([Rental::sole(), Sale::sole()] as $order) {
            $this->assertSame($client->id, $order->partner_client_id);
            $this->assertSame($partner->id, $order->business_partner_id);
            $this->assertSame($client->primaryPhone(), $order->deliveryContactPhone());
            $this->assertSame($client->openMapUrl(), $order->deliveryContactMapUrl());
            $this->assertSame($client->delivery_notes, $order->deliveryContactNotes());
        }
        $this->assertDatabaseCount('invoices', 2);
        foreach (Invoice::all() as $invoice) {
            $this->assertSame($partner->displayName(), $invoice->bill_to_name);
            $this->assertSame($partner->address, $invoice->bill_to_address);
            $this->assertSame($client->displayName(), $invoice->ship_to_name);
            $this->assertSame($client->address, $invoice->ship_to_address);
        }
    }

    public function test_order_create_keeps_old_input_and_does_not_prefill_a_client_from_another_partner(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        [, $unrelatedClient] = $this->makeBusinessPartnerContext('Other billing partner', 'Unrelated client');

        foreach (['rentals.create', 'sales.create'] as $route) {
            $context = ['customer_type' => 'business_partner', 'business_partner_id' => $partner->id];
            $form = $this->orderFormDom($this->get(route($route, $context + ['partner_client_id' => $unrelatedClient->id]))
                ->assertOk()->getContent());
            $this->assertSame((string) $partner->id, $form->evaluate('string(//select[@id="business_partner_id"]/option[@selected]/@value)'));
            $this->assertSame(0, $form->query('//select[@id="partner_client_id"]/option[@value="' . $unrelatedClient->id . '"]')->length);
            $this->assertSame('', $form->evaluate('string(//select[@id="partner_client_id"]/option[@selected]/@value)'));

            $retry = $this->withSession(['_old_input' => ['customer_type' => 'direct_customer']])
                ->get(route($route, $context + ['partner_client_id' => $client->id]))->assertOk();
            $form = $this->orderFormDom($retry->getContent());
            $this->assertSame('direct_customer', $form->evaluate('string(//select[@id="customer_type"]/option[@selected]/@value)'));
            $this->assertSame('', $form->evaluate('string(//select[@id="partner_client_id"]/option[@selected]/@value)'));
            $this->app['session']->driver()->forget('_old_input');
        }
    }

    public function test_business_partner_can_be_created_with_gst_details_and_sale_invoice_uses_them(): void
    {
        $partnerResponse = $this->postJson(route('business-partners.store'), [
            'business_name' => 'Apollo Tie-up Billing',
            'contact_person' => 'Desk',
            'phone' => '+919900003333',
            'email' => 'billing@example.test',
            'address' => 'Ops Address 1',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'gst_registered' => '1',
            'gstin' => '29ABCDE1234F1Z5',
            'legal_name' => 'Apollo Tie-up Private Limited',
            'billing_state' => 'Tamil Nadu',
            'billing_address' => 'No 11 GST Road',
            'billing_city' => 'Chennai',
            'billing_pincode' => '600001',
        ]);

        $partnerResponse->assertOk()
            ->assertJsonPath('business_partner.gst_registered', true)
            ->assertJsonPath('business_partner.gstin', '29ABCDE1234F1Z5')
            ->assertJsonPath('business_partner.legal_name', 'Apollo Tie-up Private Limited');

        $partner = BusinessPartner::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Ram Home Care',
            'phone' => '+919811119999',
            'address' => '22 Lake Street',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'status' => 'active',
        ]);

        $product = $this->makeSaleProduct();

        $this->post(route('sales.store'), [
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'sale_date' => '2026-05-19',
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
        ])->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

        $invoice = Invoice::query()->where('organization_id', $this->organizationId)->latest('id')->firstOrFail();

        $this->assertSame('Apollo Tie-up Private Limited', $invoice->bill_to_name);
        $this->assertSame('29ABCDE1234F1Z5', $invoice->bill_to_gstin);
        $this->assertSame('No 11 GST Road', $invoice->bill_to_address);
        $this->assertSame('Chennai', $invoice->bill_to_city);
        $this->assertSame('Tamil Nadu', $invoice->bill_to_state);
        $this->assertSame('600001', $invoice->bill_to_pincode);
    }

    public function test_business_partner_gstin_validation_is_enforced(): void
    {
        $response = $this->postJson(route('business-partners.store'), [
            'business_name' => 'Invalid GST Partner',
            'gst_registered' => '1',
            'gstin' => 'INVALID',
            'legal_name' => 'Invalid GST Partner LLP',
            'billing_state' => 'Karnataka',
            'billing_address' => 'Billing street',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['gstin']);
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
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

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
        $this->assertSame($partner->billingStateValue() ?: $client->state, $invoice->place_of_supply_state);

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
        ])->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

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
        $this->assertSame($partner->billingStateValue() ?: $client->state, $invoice->place_of_supply_state);
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
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

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

    private function makeBusinessPartnerContext(
        string $businessName = 'Apollo Tie-up',
        string $clientName = 'Rajesh Kumar'
    ): array
    {
        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => $businessName,
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
            'client_name' => $clientName,
            'phone' => '+919822223333',
            'address' => '12 Patient Home',
            'city' => 'Chennai',
            'state' => 'Tamil Nadu',
            'delivery_notes' => 'Ring bell and call family contact.',
            'status' => 'active',
        ]);

        return [$partner, $client];
    }

    private function orderFormDom(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
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
