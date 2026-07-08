<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class AmountReductionGuardRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_update_is_blocked_when_new_total_is_below_received_payments(): void
    {
        [$organization, $user, $customer, $product] = $this->rentalContext();
        $this->actingAs($user);

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 4500,
            'deposit_amount' => 500,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = Rental::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 3000,
            'payment_method' => 'cash',
            'notes' => 'Rental guard test payment',
        ]);

        $this->from(route('rentals.edit', $rental))
            ->put(route('rentals.update', $rental), [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'phone' => $customer->phone,
                'product_id' => $product->id,
                'quantity' => 1,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-05',
                'rental_amount' => 2000,
                'deposit_amount' => 0,
                'transport_amount' => 0,
                'other_amount' => 0,
            ])
            ->assertRedirect(route('rentals.edit', $rental))
            ->assertSessionHasErrors('finance');

        $this->assertSame(4500.0, (float) $rental->fresh()->rental_amount);
        $this->assertSame(5000.0, (float) $invoice->fresh()->total_amount);
    }

    public function test_sale_update_is_blocked_when_new_total_is_below_received_payments(): void
    {
        [$organization, $user, $customer, $product] = $this->saleContext();
        $this->actingAs($user);

        $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => '2026-05-01',
            'sale_amount' => 1500,
            'payment_status' => 'pending',
        ])->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'rental_id' => null,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 1200,
            'payment_method' => 'cash',
            'notes' => 'Sale guard test payment',
        ]);

        $this->from(route('sales.edit', $sale))
            ->put(route('sales.update', $sale), [
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_amount' => 0,
                'shipping_charges' => 0,
                'tax_percentage' => 0,
                'tax_calculation_mode' => 'exclusive',
                'sale_date' => '2026-05-01',
                'sale_amount' => 1000,
                'payment_status' => 'pending',
            ])
            ->assertRedirect(route('sales.edit', $sale))
            ->assertSessionHasErrors('finance');

        $this->assertSame(1500.0, (float) $sale->fresh()->sale_amount);
        $this->assertSame(1500.0, (float) $invoice->fresh()->total_amount);
    }

    public function test_invoice_update_is_blocked_when_new_total_is_below_received_payments(): void
    {
        [$organization, $user, $customer] = $this->invoiceContext();
        $this->actingAs($user);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-GUARD-001',
            'invoice_date' => '2026-05-01',
            'due_date' => '2026-05-01',
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 1000,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
            'created_by' => $user->id,
        ]);

        $invoice->items()->create([
            'product_id' => null,
            'source_type' => 'manual',
            'source_id' => null,
            'description' => 'Manual charge',
            'quantity' => 1,
            'unit' => 'service',
            'days' => null,
            'rate' => 1000,
            'discount_amount' => 0,
            'taxable_amount' => 1000,
            'tax_percentage' => 0,
            'tax_type' => 'cgst_sgst',
            'cgst_rate' => 0,
            'sgst_rate' => 0,
            'igst_rate' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'line_total' => 1000,
        ]);

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'rental_id' => null,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 600,
            'payment_method' => 'cash',
            'notes' => 'Invoice guard test payment',
        ]);

        $this->from(route('invoices.edit', $invoice))
            ->put(route('invoices.update', $invoice), [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => '2026-05-01',
                'due_date' => '2026-05-01',
                'customer_id' => $customer->id,
                'bill_to_name' => $customer->name,
                'bill_to_phone' => $customer->phone,
                'tax_calculation_mode' => 'exclusive',
                'deposit_amount' => 0,
                'shipping_charges' => 0,
                'paid_amount' => 0,
                'item_description' => ['Manual charge'],
                'item_custom_name' => [''],
                'item_product_id' => [''],
                'item_source_type' => ['manual'],
                'item_source_id' => [''],
                'item_quantity' => [1],
                'item_rate' => [500],
                'item_discount' => [0],
                'item_tax_percentage' => [0],
            ])
            ->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHasErrors('finance');

        $this->assertSame(1000.0, (float) $invoice->fresh()->total_amount);
    }

    private function rentalContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Guard Customer',
            'phone' => '9876543201',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Guard Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 0,
            'rental_price' => 4500,
            'price_per_day' => 4500,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        return [$organization, $user, $customer, $product];
    }

    private function saleContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Guard Customer',
            'phone' => '9876543202',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Guard Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 1500,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        return [$organization, $user, $customer, $product];
    }

    private function invoiceContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Invoice Guard Customer',
            'phone' => '9876543203',
        ]);

        return [$organization, $user, $customer];
    }
}

