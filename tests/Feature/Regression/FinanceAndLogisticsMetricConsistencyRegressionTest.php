<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class FinanceAndLogisticsMetricConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(now()->startOfDay()->addHours(12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_finance_summary_exposes_reconciliation_gap_instead_of_hiding_it(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Finance Metrics Customer',
            'phone' => '9000000101',
            'city' => 'Bengaluru',
        ]);

        $rentalProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Finance Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 500,
            'rental_price' => 500,
            'sale_price' => 0,
            'available_quantity' => 20,
            'total_quantity' => 20,
        ]);

        $saleProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Finance Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 750,
            'available_quantity' => 20,
            'total_quantity' => 20,
        ]);

        $invoicedRental = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'rental_amount' => 500,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);
        $this->completeRentalDelivery($organization->id, $invoicedRental->id);

        $unbilledRental = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 600,
            'deposit_amount' => 0,
            'transport_amount' => 200,
            'other_amount' => 0,
        ]);
        $this->completeRentalDelivery($organization->id, $unbilledRental->id);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $saleProduct->id,
            'quantity' => 1,
            'unit_price' => 750,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 750,
            'payment_status' => 'pending',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-FIN-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'rental_id' => $invoicedRental->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 500,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 500,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 0,
            'balance_amount' => 500,
        ]);

        $invoice->items()->create([
            'product_id' => $rentalProduct->id,
            'source_type' => 'rental',
            'source_id' => $invoicedRental->id,
            'description' => $rentalProduct->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 500,
            'taxable_amount' => 500,
            'line_total' => 500,
        ]);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(2050.0, round((float) $response->viewData('grossBilledAmount'), 2));
        $this->assertSame(500.0, round((float) $response->viewData('totalBilledAmount'), 2));
        $this->assertSame(1550.0, round((float) $response->viewData('reconciliationGapAmount'), 2));
        $this->assertSame(1550.0, round((float) $response->viewData('knownUnbilledGapAmount'), 2));
        $this->assertSame(0.0, round((float) $response->viewData('adjustmentGapAmount'), 2));

        $financeSummary = $response->viewData('financeSummary');
        $this->assertSame(2050.0, round((float) ($financeSummary['grossOrderComponents'] ?? 0), 2));
        $this->assertSame(500.0, round((float) ($financeSummary['netBilledAmount'] ?? 0), 2));
        $this->assertSame(1550.0, round((float) ($financeSummary['reconciliationGapAmount'] ?? 0), 2));
        $this->assertSame(1550.0, round((float) ($financeSummary['knownUnbilledGapAmount'] ?? 0), 2));
    }

    public function test_dashboard_and_taskboard_share_canonical_logistics_metrics(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Logistics Metrics Customer',
            'phone' => '9000000102',
            'city' => 'Bengaluru',
        ]);

        $rentalProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Logistics Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 400,
            'rental_price' => 400,
            'sale_price' => 0,
            'available_quantity' => 20,
            'total_quantity' => 20,
        ]);

        $saleProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Logistics Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 300,
            'available_quantity' => 20,
            'total_quantity' => 20,
        ]);

        $pendingDeliveryRental = $this->makeRental($organization->id, $customer->id, $rentalProduct->id);
        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $pendingDeliveryRental->id,
            'product_id' => $rentalProduct->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 400,
            'line_total' => 400,
        ]);
        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $pendingDeliveryRental->id,
            'type' => 'delivery',
            'scheduled_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        $saleOverdueDelivery = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 310);
        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleOverdueDelivery->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subDay(),
            'status' => 'pending',
        ]);

        $saleOutForDelivery = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 325);
        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleOutForDelivery->id,
            'type' => 'delivery',
            'scheduled_at' => now()->addHours(2),
            'status' => 'in_progress',
        ]);

        $saleDeliveredToday = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 350);
        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleDeliveredToday->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subHours(3),
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);

        $scheduledDeliverySale = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 360);
        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $scheduledDeliverySale->id,
            'type' => 'delivery',
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $pendingPickupRental = $this->makeRentalWithPickupProgress($organization->id, $customer->id, $rentalProduct->id, [
            'scheduled_at' => now()->addHour(),
            'status' => 'pending',
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
        ]);

        $scheduledPickupRental = $this->makeRentalWithPickupProgress($organization->id, $customer->id, $rentalProduct->id, [
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
        ]);

        $outForPickupRental = $this->makeRentalWithPickupProgress($organization->id, $customer->id, $rentalProduct->id, [
            'scheduled_at' => now()->addHours(4),
            'status' => 'in_progress',
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
        ]);

        $completedPickupRental = $this->makeRentalWithPickupProgress($organization->id, $customer->id, $rentalProduct->id, [
            'scheduled_at' => now()->subHours(4),
            'status' => 'completed',
            'completed_at' => now()->subMinutes(30),
            'delivered_quantity' => 1,
            'returned_quantity' => 1,
        ]);

        $dashboard = $this->get(route('dashboard'));
        $dashboard->assertOk();

        $taskboard = $this->get(route('deliveries.index'));
        $taskboard->assertOk();

        $this->assertSame((int) $taskboard->viewData('taskResultsCount'), (int) $taskboard->viewData('totalTasksCount'));
        $this->assertSame(9, (int) $taskboard->viewData('totalTasksCount'));
        $this->assertSame(4, (int) $taskboard->viewData('deliveryTasksCount'));
        $this->assertSame(3, (int) $taskboard->viewData('pickupTasksCount'));
        $this->assertSame(1, (int) $taskboard->viewData('completedDeliveryCount'));
        $this->assertSame(1, (int) $taskboard->viewData('completedPickupCount'));
        $this->assertSame(1, (int) $taskboard->viewData('overdueTasksCount'));
        $this->assertSame(2, (int) $taskboard->viewData('completedTodayCount'));
        $this->assertSame(
            (int) $taskboard->viewData('totalTasksCount'),
            (int) $taskboard->viewData('deliveryTasksCount')
                + (int) $taskboard->viewData('pickupTasksCount')
                + (int) $taskboard->viewData('completedDeliveryCount')
                + (int) $taskboard->viewData('completedPickupCount')
        );

        $this->assertSame(1, (int) $taskboard->viewData('pendingDeliveryCount'));
        $this->assertSame(1, (int) $taskboard->viewData('scheduledDeliveryCount'));
        $this->assertSame(1, (int) $taskboard->viewData('outForDeliveryCount'));
        $this->assertSame(1, (int) $taskboard->viewData('overdueDeliveryCount'));
        $this->assertSame(1, (int) $taskboard->viewData('deliveredTodayCount'));
        $this->assertSame(1, (int) $taskboard->viewData('pendingPickupCount'));
        $this->assertSame(1, (int) $taskboard->viewData('scheduledPickupCount'));
        $this->assertSame(1, (int) $taskboard->viewData('outForPickupCount'));
        $this->assertSame(0, (int) $taskboard->viewData('overduePickupCount'));
        $this->assertSame(1, (int) $taskboard->viewData('completedPickupCount'));
        $this->assertSame(
            (int) $taskboard->viewData('deliveryTasksCount'),
            (int) $taskboard->viewData('pendingDeliveryCount')
                + (int) $taskboard->viewData('scheduledDeliveryCount')
                + (int) $taskboard->viewData('outForDeliveryCount')
                + (int) $taskboard->viewData('overdueDeliveryCount')
        );
        $this->assertSame(
            (int) $taskboard->viewData('pickupTasksCount'),
            (int) $taskboard->viewData('pendingPickupCount')
                + (int) $taskboard->viewData('scheduledPickupCount')
                + (int) $taskboard->viewData('outForPickupCount')
                + (int) $taskboard->viewData('overduePickupCount')
        );

        $this->assertSame((int) $dashboard->viewData('pendingDeliveryCount'), (int) $taskboard->viewData('pendingDeliveryCount'));
        $this->assertSame((int) $dashboard->viewData('scheduledDeliveryCount'), (int) $taskboard->viewData('scheduledDeliveryCount'));
        $this->assertSame((int) $dashboard->viewData('outForDeliveryCount'), (int) $taskboard->viewData('outForDeliveryCount'));
        $this->assertSame((int) $dashboard->viewData('overdueDeliveryCount'), (int) $taskboard->viewData('overdueDeliveryCount'));
        $this->assertSame((int) $dashboard->viewData('deliveredTodayCount'), (int) $taskboard->viewData('deliveredTodayCount'));
        $this->assertSame((int) $dashboard->viewData('pendingPickupCount'), (int) $taskboard->viewData('pendingPickupCount'));
        $this->assertSame((int) $dashboard->viewData('scheduledPickupCount'), (int) $taskboard->viewData('scheduledPickupCount'));
        $this->assertSame((int) $dashboard->viewData('outForPickupCount'), (int) $taskboard->viewData('outForPickupCount'));
        $this->assertSame((int) $dashboard->viewData('overduePickupCount'), (int) $taskboard->viewData('overduePickupCount'));
        $this->assertSame((int) $dashboard->viewData('completedPickupCount'), (int) $taskboard->viewData('completedPickupCount'));
        $this->assertSame((int) $dashboard->viewData('totalTasksCount'), (int) $taskboard->viewData('totalTasksCount'));
        $this->assertSame((int) $dashboard->viewData('deliveryTasksCount'), (int) $taskboard->viewData('deliveryTasksCount'));
        $this->assertSame((int) $dashboard->viewData('pickupTasksCount'), (int) $taskboard->viewData('pickupTasksCount'));
        $this->assertSame((int) $dashboard->viewData('completedTodayCount'), (int) $taskboard->viewData('completedTodayCount'));
        $this->assertSame((int) $dashboard->viewData('completedDeliveryCount'), (int) $taskboard->viewData('completedDeliveryCount'));

        $dashboard->assertSee(route('deliveries.index', ['board' => 'delivery_workload']), false);
        $dashboard->assertSee(route('deliveries.index', ['board' => 'pickup_workload']), false);
        $dashboard->assertSee(route('deliveries.index', ['board' => 'completed_today']), false);
        $dashboard->assertSee(route('deliveries.index'), false);

        $pendingPickupWidget = collect($taskboard->viewData('pendingCollections'));
        $this->assertCount(1, $pendingPickupWidget);
        $this->assertSame((int) $pendingPickupRental->id, (int) optional($pendingPickupWidget->first())->rental_id);
    }

    public function test_taskboard_card_filters_match_visible_task_counts(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'taskboard-filters@example.com',
        ]));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Taskboard Filter Customer',
            'phone' => '9000000301',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Taskboard Filter Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 300,
            'rental_price' => 300,
            'sale_price' => 0,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $openDelivery = $this->makeRental($organization->id, $customer->id, $product->id);
        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $openDelivery->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 300,
            'line_total' => 300,
        ]);
        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $openDelivery->id,
            'type' => 'delivery',
            'scheduled_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        $completedDeliverySale = $this->makeSale($organization->id, $customer->id, $product->id, 325);
        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $completedDeliverySale->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subHours(3),
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);

        $pendingResponse = $this->get(route('deliveries.index', [
            'tab' => 'deliveries',
            'task_type' => 'delivery',
            'workflow' => 'delivery_workload',
        ]));

        $pendingResponse->assertOk();
        $this->assertSame((int) $pendingResponse->viewData('taskResultsCount'), (int) $pendingResponse->viewData('totalTasksCount'));
        $this->assertSame((int) $pendingResponse->viewData('deliveryTasksCount'), (int) $pendingResponse->viewData('totalTasksCount'));

        $completedResponse = $this->get(route('deliveries.index', [
            'tab' => 'completed',
            'task_type' => 'delivery',
            'status' => 'completed',
            'workflow' => 'completed_delivery',
        ]));

        $completedResponse->assertOk();
        $this->assertSame((int) $completedResponse->viewData('taskResultsCount'), (int) $completedResponse->viewData('totalTasksCount'));
        $this->assertSame((int) $completedResponse->viewData('completedDeliveryCount'), (int) $completedResponse->viewData('totalTasksCount'));

        $todayResponse = $this->get(route('deliveries.index', [
            'tab' => 'completed',
            'status' => 'completed',
            'workflow' => 'completed_today',
        ]));

        $todayResponse->assertOk();
        $this->assertSame((int) $todayResponse->viewData('taskResultsCount'), (int) $todayResponse->viewData('totalTasksCount'));
        $this->assertSame((int) $todayResponse->viewData('completedTodayCount'), (int) $todayResponse->viewData('totalTasksCount'));
    }

    public function test_sale_stock_available_uses_product_master_quantity_while_asset_register_keeps_serialized_units_separate(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'inventory-metrics@example.com',
        ]));

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Quantity Based Sale Stock',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'sale_price' => 125,
            'rental_price' => 0,
            'available_quantity' => 154,
            'total_quantity' => 154,
        ]);

        $dashboard = $this->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(154, (int) $dashboard->viewData('availableSaleUnits'));

        $productMaster = $this->get(route('products.index'));
        $productMaster->assertOk();
        $this->assertSame(154, (int) data_get($productMaster->viewData('catalogTotals'), 'sale_stock'));

        $assetRegister = $this->get(route('assets.index'));
        $assetRegister->assertOk();
        $this->assertSame(0, (int) data_get($assetRegister->viewData('summary'), 'sale_stock'));
        $assetRegister->assertSee('Serialized Sale Units');

        $inventoryDashboard = $this->get(route('inventory.dashboard'));
        $inventoryDashboard->assertOk()
            ->assertViewHas('dashboard', function (array $summary) {
                return $summary['sale_stock'] === 154
                    && $summary['serialized_sale_units'] === 0;
            })
            ->assertSee('Sale Stock Available')
            ->assertSee('Serialized Sale Units');
    }

    private function makeRental(int $organizationId, int $customerId, int $productId, array $overrides = []): Rental
    {
        return Rental::create(array_merge([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'customer_name' => 'Metrics Customer',
            'phone' => '9000000100',
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 400,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ], $overrides));
    }

    private function makeSale(int $organizationId, int $customerId, int $productId, float $amount): Sale
    {
        return Sale::create([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => 1,
            'unit_price' => $amount,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => $amount,
            'payment_status' => 'pending',
        ]);
    }

    private function completeRentalDelivery(int $organizationId, int $rentalId): void
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

    private function makeRentalWithPickupProgress(int $organizationId, int $customerId, int $productId, array $pickupAttributes): Rental
    {
        $rental = $this->makeRental($organizationId, $customerId, $productId, [
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        RentalItem::create([
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'product_id' => $productId,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => (int) ($pickupAttributes['delivered_quantity'] ?? 1),
            'returned_quantity' => (int) ($pickupAttributes['returned_quantity'] ?? 0),
            'unit_rental_amount' => 400,
            'line_total' => 400,
        ]);

        Delivery::create([
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => $pickupAttributes['scheduled_at'],
            'status' => $pickupAttributes['status'],
            'completed_at' => $pickupAttributes['completed_at'] ?? null,
        ]);

        return $rental;
    }
}
