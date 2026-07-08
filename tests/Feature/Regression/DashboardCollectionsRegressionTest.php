<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class DashboardCollectionsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_collections_ignore_orphaned_payments(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Dashboard Customer',
            'phone' => '9999999999',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-DASH-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'partial',
            'subtotal' => 250,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 250,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 250,
            'paid_amount' => 250,
            'balance_amount' => 0,
        ]);

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'invoice_id' => null,
            'rental_id' => null,
            'payment_date' => now()->toDateString(),
            'amount' => 189,
            'payment_method' => 'cash',
            'notes' => 'Orphaned payment should not count on dashboard',
        ]);

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'rental_id' => null,
            'payment_date' => now()->toDateString(),
            'amount' => 250,
            'payment_method' => 'upi',
            'notes' => 'Linked payment should count on dashboard',
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('250.00 received today')
            ->assertDontSee('439.00 received today');
    }
}
