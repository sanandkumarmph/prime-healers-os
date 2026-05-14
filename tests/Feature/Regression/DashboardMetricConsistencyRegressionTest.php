<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalRenewal;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class DashboardMetricConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_list_metrics_share_canonical_definitions(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Metrics Customer',
            'phone' => '9876500001',
            'city' => 'Bengaluru',
        ]);

        $rentalProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Metrics Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 500,
            'rental_price' => 500,
            'sale_price' => 0,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $saleProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Metrics Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 300,
            'available_quantity' => 20,
            'total_quantity' => 20,
        ]);

        $currentRental = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 500,
            'status' => 'active',
        ]);

        $overdueRental = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'start_date' => now()->subDays(8)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'rental_amount' => 500,
            'status' => 'active',
        ]);

        $returnedRental = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(4)->toDateString(),
            'rental_amount' => 450,
            'status' => 'returned',
            'returned_at' => now()->subDays(3),
        ]);

        $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'rental_amount' => 400,
            'status' => 'cancelled',
        ]);

        $this->completeDeliveryLifecycle($organization->id, $currentRental->id);
        $this->completeDeliveryLifecycle($organization->id, $overdueRental->id);
        $this->completeDeliveryLifecycle($organization->id, $returnedRental->id);

        $rentalInvoice = $this->makeInvoice($organization->id, $customer->id, [
            'invoice_number' => 'INV-REN-001',
            'rental_id' => $currentRental->id,
            'status' => 'partial',
            'payment_status' => 'partial',
            'subtotal' => 500,
            'taxable_amount' => 500,
            'total_amount' => 500,
            'paid_amount' => 100,
            'balance_amount' => 400,
        ]);
        $rentalInvoice->items()->create([
            'product_id' => $rentalProduct->id,
            'source_type' => 'rental',
            'source_id' => $currentRental->id,
            'description' => $rentalProduct->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 500,
            'taxable_amount' => 500,
            'line_total' => 500,
        ]);

        RentalRenewal::create([
            'organization_id' => $organization->id,
            'rental_id' => $currentRental->id,
            'previous_end_date' => now()->copy(),
            'renewed_end_date' => now()->copy()->addDays(3),
            'renewal_days' => 3,
            'renewal_type' => 'custom',
            'rental_amount_added' => 200,
            'deposit_amount_added' => 0,
            'transport_amount_added' => 0,
            'other_amount_added' => 0,
            'payment_amount' => 0,
        ]);

        $renewalInvoice = $this->makeInvoice($organization->id, $customer->id, [
            'invoice_number' => 'INV-REN-002',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 150,
            'taxable_amount' => 150,
            'total_amount' => 150,
            'paid_amount' => 0,
            'balance_amount' => 150,
        ]);

        RentalRenewal::create([
            'organization_id' => $organization->id,
            'rental_id' => $overdueRental->id,
            'previous_end_date' => now()->copy()->subDay(),
            'renewed_end_date' => now()->copy()->addDays(2),
            'renewal_days' => 3,
            'renewal_type' => 'custom',
            'rental_amount_added' => 150,
            'deposit_amount_added' => 0,
            'transport_amount_added' => 0,
            'other_amount_added' => 0,
            'payment_amount' => 0,
            'invoice_id' => $renewalInvoice->id,
        ]);

        $partialSale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $saleProduct->id,
            'quantity' => 1,
            'unit_price' => 600,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 600,
            'payment_status' => 'partial',
        ]);

        $salesInvoice = $this->makeInvoice($organization->id, $customer->id, [
            'invoice_number' => 'INV-SALE-001',
            'sale_id' => $partialSale->id,
            'status' => 'partial',
            'payment_status' => 'partial',
            'subtotal' => 600,
            'taxable_amount' => 600,
            'total_amount' => 600,
            'paid_amount' => 150,
            'balance_amount' => 450,
        ]);
        $salesInvoice->items()->create([
            'product_id' => $saleProduct->id,
            'source_type' => 'sale',
            'source_id' => $partialSale->id,
            'description' => $saleProduct->name,
            'quantity' => 1,
            'unit' => 'sale',
            'rate' => 600,
            'taxable_amount' => 600,
            'line_total' => 600,
        ]);

        Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $saleProduct->id,
            'quantity' => 1,
            'unit_price' => 150,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 150,
            'payment_status' => 'pending',
        ]);

        $paidSale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $saleProduct->id,
            'quantity' => 1,
            'unit_price' => 300,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 300,
            'payment_status' => 'paid',
        ]);

        $paidSaleInvoice = $this->makeInvoice($organization->id, $customer->id, [
            'invoice_number' => 'INV-SALE-002',
            'sale_id' => $paidSale->id,
            'status' => 'paid',
            'payment_status' => 'paid',
            'subtotal' => 300,
            'taxable_amount' => 300,
            'total_amount' => 300,
            'paid_amount' => 300,
            'balance_amount' => 0,
        ]);
        $paidSaleInvoice->items()->create([
            'product_id' => $saleProduct->id,
            'source_type' => 'sale',
            'source_id' => $paidSale->id,
            'description' => $saleProduct->name,
            'quantity' => 1,
            'unit' => 'sale',
            'rate' => 300,
            'taxable_amount' => 300,
            'line_total' => 300,
        ]);

        $dashboard = $this->get(route('dashboard'));
        $dashboard->assertOk();

        $rentalsPage = $this->get(route('rentals.index'));
        $rentalsPage->assertOk();

        $salesPage = $this->get(route('sales.index'));
        $salesPage->assertOk();

        $invoicePage = $this->get(route('invoices.index'));
        $invoicePage->assertOk();

        $this->assertSame(2, (int) $dashboard->viewData('activeRentals'));
        $this->assertSame(2, (int) $rentalsPage->viewData('activeRentals'));
        $this->assertSame(1, (int) $rentalsPage->viewData('currentRentals'));
        $this->assertSame(1, (int) $rentalsPage->viewData('overdueCount'));
        $this->assertSame(1, (int) $rentalsPage->viewData('returnedRentals'));
        $this->assertSame(3, (int) $rentalsPage->viewData('totalRentals'));
        $this->assertSame(
            (int) $rentalsPage->viewData('activeRentals'),
            (int) $rentalsPage->viewData('currentRentals') + (int) $rentalsPage->viewData('overdueCount')
        );

        $this->assertSame(1, (int) $dashboard->viewData('lifecycleActiveCount'));
        $this->assertSame(1, (int) $dashboard->viewData('lifecycleOverdueCount'));
        $this->assertSame(1, (int) $dashboard->viewData('returnedRentals'));
        $this->assertSame(1, (int) $dashboard->viewData('unbilledRenewalCount'));
        $this->assertSame(1, (int) $dashboard->viewData('unpaidRenewalCount'));
        $this->assertSame(200.0, round((float) $dashboard->viewData('unbilledRenewalAmount'), 2));
        $this->assertSame(150.0, round((float) $dashboard->viewData('unpaidRenewalAmount'), 2));

        $this->assertSame(1050.0, round((float) $salesPage->viewData('totalSalesAmount'), 2));
        $this->assertSame(450.0, round((float) $salesPage->viewData('paidSalesAmount'), 2));
        $this->assertSame(600.0, round((float) $salesPage->viewData('pendingSalesAmount'), 2));
        $this->assertSame(450.0, round((float) $salesPage->viewData('outstandingInvoiceAmount'), 2));
        $this->assertSame(150.0, round((float) $salesPage->viewData('unbilledSalesAmount'), 2));
        $this->assertSame(600.0, round((float) $salesPage->viewData('totalPendingSalesAmount'), 2));
        $this->assertSame(
            round((float) $salesPage->viewData('totalSalesAmount'), 2),
            round((float) $salesPage->viewData('paidSalesAmount') + (float) $salesPage->viewData('pendingSalesAmount'), 2)
        );
        $this->assertSame(
            round((float) $salesPage->viewData('pendingSalesAmount'), 2),
            round((float) $salesPage->viewData('outstandingInvoiceAmount') + (float) $salesPage->viewData('unbilledSalesAmount'), 2)
        );

        $invoiceStats = $invoicePage->viewData('invoiceStats');
        $this->assertSame(4, (int) ($invoiceStats['totalInvoices'] ?? 0));
        $this->assertSame(1, (int) ($invoiceStats['paidInvoices'] ?? 0));
        $this->assertSame(3, (int) ($invoiceStats['openInvoices'] ?? 0));
        $this->assertSame(1000.0, round((float) ($invoiceStats['outstandingAmount'] ?? 0), 2));
        $this->assertSame(450.0, round((float) ($invoiceStats['salesOutstandingAmount'] ?? 0), 2));
        $this->assertSame(550.0, round((float) ($invoiceStats['rentalOutstandingAmount'] ?? 0), 2));
        $this->assertSame(
            round((float) ($invoiceStats['outstandingAmount'] ?? 0), 2),
            round((float) ($invoiceStats['salesOutstandingAmount'] ?? 0) + (float) ($invoiceStats['rentalOutstandingAmount'] ?? 0), 2)
        );

        $this->assertSame((int) $dashboard->viewData('openInvoiceCount'), (int) ($invoiceStats['openInvoices'] ?? 0));
        $this->assertSame(
            round((float) $dashboard->viewData('outstandingDueAmount'), 2),
            round((float) ($invoiceStats['outstandingAmount'] ?? 0), 2)
        );
        $this->assertSame(
            round((float) $dashboard->viewData('salesOutstandingInvoiceAmount'), 2),
            round((float) $salesPage->viewData('outstandingInvoiceAmount'), 2)
        );
        $this->assertSame(
            round((float) $dashboard->viewData('salesUnbilledAmount'), 2),
            round((float) $salesPage->viewData('unbilledSalesAmount'), 2)
        );
        $this->assertSame(
            round((float) $dashboard->viewData('salesTotalPendingAmount'), 2),
            round((float) $salesPage->viewData('totalPendingSalesAmount'), 2)
        );
    }

    private function makeRental(int $organizationId, int $customerId, int $productId, array $overrides = []): Rental
    {
        return Rental::create(array_merge([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'customer_name' => 'Metrics Customer',
            'phone' => '9876500001',
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'rental_amount' => 500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function makeInvoice(int $organizationId, int $customerId, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $organizationId,
            'invoice_number' => 'INV-METRICS-' . fake()->unique()->numerify('####'),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'bill_to_name' => 'Metrics Customer',
            'bill_to_phone' => '9876500001',
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 0,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'balance_amount' => 0,
        ], $overrides));
    }

    private function completeDeliveryLifecycle(int $organizationId, int $rentalId): void
    {
        Delivery::create([
            'organization_id' => $organizationId,
            'rental_id' => $rentalId,
            'type' => 'delivery',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);
    }
}
