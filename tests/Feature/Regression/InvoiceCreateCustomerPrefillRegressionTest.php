<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class InvoiceCreateCustomerPrefillRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_show_create_invoice_link_prefills_customer_on_invoice_create(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Chetan Kumar',
            'phone' => '9876543210',
            'email' => 'chetan@example.com',
            'address' => 'Prime Healers Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'place_of_supply' => 'Karnataka',
            'pincode' => '560001',
            'gst_number' => '29ABCDE1234F1Z5',
        ]);

        $customerShow = $this->get(route('customers.show', $customer));

        $customerShow->assertOk()
            ->assertSee(route('invoices.create', ['customer_id' => $customer->id]), false);

        $invoiceCreate = $this->get(route('invoices.create', ['customer_id' => $customer->id]));

        $invoiceCreate->assertOk()
            ->assertSee('name="customer_id" id="customer_id" value="'.$customer->id.'"', false)
            ->assertSee('Chetan Kumar - 9876543210')
            ->assertSee('name="bill_to_name" value="Chetan Kumar"', false)
            ->assertSee('name="bill_to_phone" value="9876543210"', false)
            ->assertSee('name="bill_to_city" value="Bengaluru"', false)
            ->assertSee('name="bill_to_gstin" value="29ABCDE1234F1Z5"', false);
    }
}
