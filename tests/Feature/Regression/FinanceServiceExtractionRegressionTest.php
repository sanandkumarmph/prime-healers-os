<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalRenewal;
use App\Models\Sale;
use App\Services\Finance\InvoiceLinkResolver;
use App\Services\Finance\PaymentSyncService;
use App\Services\Finance\RenewalFinanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class FinanceServiceExtractionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_link_resolver_prefers_direct_rental_link_before_legacy_fallback(): void
    {
        $organization = TestData::organization(['default_terms' => 'Net due on receipt']);
        [$customer, $product, $rental] = $this->bootRentalContext($organization);

        $directInvoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-DIRECT-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'taxable_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ]);

        $legacyInvoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-LEGACY-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'taxable_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ]);

        $legacyInvoice->items()->create([
            'product_id' => $product->id,
            'source_type' => 'rental',
            'source_id' => $rental->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 1000,
            'taxable_amount' => 1000,
            'line_total' => 1000,
        ]);

        $resolved = app(InvoiceLinkResolver::class)->rentalInvoice(
            $rental,
            $organization->id,
            true
        );

        $this->assertNotNull($resolved);
        $this->assertSame($directInvoice->id, $resolved?->id);
    }

    public function test_invoice_link_resolver_falls_back_to_legacy_sale_item_link(): void
    {
        $organization = TestData::organization();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Legacy Sale Customer',
            'phone' => '9000000099',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Legacy Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 2500,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 2,
            'total_quantity' => 2,
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 2500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 2500,
            'payment_status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-LEGACY-SALE-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 2500,
            'taxable_amount' => 2500,
            'total_amount' => 2500,
            'paid_amount' => 0,
            'balance_amount' => 2500,
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'sale',
            'rate' => 2500,
            'taxable_amount' => 2500,
            'line_total' => 2500,
        ]);

        $resolved = app(InvoiceLinkResolver::class)->saleInvoice(
            $sale,
            $organization->id,
            true
        );

        $this->assertNotNull($resolved);
        $this->assertSame($invoice->id, $resolved?->id);
    }

    public function test_payment_sync_service_creates_invoice_payment_and_syncs_financial_status(): void
    {
        $organization = TestData::organization();
        [$customer, $product, $rental] = $this->bootRentalContext($organization);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-PAY-SYNC-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'taxable_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ]);

        app(PaymentSyncService::class)->createInvoicePayment(
            $organization->id,
            $invoice,
            [
                'payment_date' => now()->toDateString(),
                'amount' => 400,
                'payment_method' => 'cash',
                'notes' => 'First installment',
            ],
            $rental->id,
            true
        );

        $invoice->refresh();

        $this->assertSame('partial', $invoice->payment_status);
        $this->assertSame(400.0, (float) $invoice->paid_amount);
        $this->assertSame(600.0, (float) $invoice->balance_amount);
    }

    public function test_payment_delete_recalculation_updates_invoice_and_sale_status(): void
    {
        $organization = TestData::organization();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Paid Sale Customer',
            'phone' => '9888800001',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Paid Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 1000,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 2,
            'total_quantity' => 2,
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 1000,
            'payment_status' => 'paid',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-SALE-DELETE-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'paid',
            'payment_status' => 'paid',
            'subtotal' => 1000,
            'taxable_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'balance_amount' => 0,
        ]);

        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 1000,
            'payment_method' => 'upi',
        ]);

        Payment::query()->where('invoice_id', $invoice->id)->delete();

        app(PaymentSyncService::class)->refreshInvoiceAfterPaymentDeletion($organization->id, $invoice->fresh());

        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(1000.0, (float) $invoice->fresh()->balance_amount);
        $this->assertSame('pending', $sale->fresh()->payment_status);
    }

    public function test_renewal_finance_service_generates_and_syncs_renewal_invoice_lifecycle(): void
    {
        $organization = TestData::organization(['default_terms' => 'Renewal due on receipt']);
        [$customer, $product, $rental] = $this->bootRentalContext($organization);

        $renewal = RentalRenewal::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'previous_end_date' => Carbon::parse($rental->end_date),
            'renewed_end_date' => Carbon::parse($rental->end_date)->addDays(7),
            'renewal_days' => 7,
            'renewal_type' => 'custom',
            'rental_amount_added' => 700,
            'deposit_amount_added' => 100,
            'transport_amount_added' => 50,
            'other_amount_added' => 25,
            'payment_amount' => 0,
        ]);

        $service = app(RenewalFinanceService::class);
        $invoice = $service->createRenewalInvoice(
            $organization->id,
            $rental->fresh(['customer', 'product']),
            $renewal,
            $organization,
            'INV-REN-001',
            null
        );

        $this->assertSame($invoice->id, $renewal->fresh()->invoice_id);
        $this->assertSame(875.0, (float) $invoice->fresh()->total_amount);
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);

        $payment = $service->syncRenewalManagedPayment(
            $organization->id,
            $rental,
            $renewal->fresh(),
            875.0,
            'cash',
            now(),
            'Captured during renewal'
        );

        $renewal->refresh()->forceFill([
            'payment_id' => $payment?->id,
            'payment_amount' => 875.0,
            'payment_method' => 'cash',
        ])->save();

        $service->syncRenewalInvoicePaymentState($organization->id, $rental->fresh(['customer', 'product']), $renewal->fresh());

        $this->assertNotNull($payment);
        $this->assertSame($invoice->id, $payment->fresh()->invoice_id);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_amount);
    }

    private function bootRentalContext($organization): array
    {
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Finance Service Customer',
            'phone' => '9000000010',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Finance Service Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 0,
            'rental_price' => 4500,
            'price_per_day' => 4500,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'rental_amount' => 4500,
            'deposit_amount' => 500,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ]);

        return [$customer, $product, $rental];
    }
}
