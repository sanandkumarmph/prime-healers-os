<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Metrics\CollectionMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class IstTimezoneRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_application_defaults_to_ist_timezone(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertSame('Asia/Kolkata', now()->timezone->getName());
    }

    public function test_collections_today_uses_ist_day_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 00:30:00', 'Asia/Kolkata'));

        $organization = TestData::organization();

        Payment::create([
            'organization_id' => $organization->id,
            'payment_date' => '2026-05-16',
            'amount' => 100,
            'payment_method' => 'cash',
        ]);

        Payment::create([
            'organization_id' => $organization->id,
            'payment_date' => '2026-05-15',
            'amount' => 200,
            'payment_method' => 'cash',
        ]);

        $summary = (new CollectionMetricsService())->summary(
            Payment::query()->where('organization_id', $organization->id)
        );

        $this->assertSame(100.0, (float) ($summary['paymentsReceivedToday'] ?? 0));
        $this->assertSame(300.0, (float) ($summary['paymentsReceivedThisMonth'] ?? 0));
    }

    public function test_taskboard_completed_today_uses_ist_day_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 00:30:00', 'Asia/Kolkata'));

        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'ist-taskboard@example.com',
        ]));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'IST Customer',
            'phone' => '9000000501',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'IST Delivery Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 500,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $saleCompletedToday = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => '2026-05-16',
            'sale_amount' => 500,
            'payment_status' => 'pending',
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleCompletedToday->id,
            'type' => 'delivery',
            'scheduled_at' => Carbon::parse('2026-05-16 00:00:00', 'Asia/Kolkata'),
            'status' => 'completed',
            'completed_at' => Carbon::parse('2026-05-16 00:10:00', 'Asia/Kolkata'),
        ]);

        $saleCompletedYesterday = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 550,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => '2026-05-15',
            'sale_amount' => 550,
            'payment_status' => 'pending',
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleCompletedYesterday->id,
            'type' => 'delivery',
            'scheduled_at' => Carbon::parse('2026-05-15 23:00:00', 'Asia/Kolkata'),
            'status' => 'completed',
            'completed_at' => Carbon::parse('2026-05-15 23:50:00', 'Asia/Kolkata'),
        ]);

        $response = $this->get(route('deliveries.index', [
            'tab' => 'completed',
            'status' => 'completed',
            'workflow' => 'completed_today',
        ]));

        $response->assertOk();
        $this->assertSame(1, (int) $response->viewData('completedTodayCount'));
        $this->assertSame(1, (int) $response->viewData('taskResultsCount'));
        $this->assertSame(1, (int) $response->viewData('totalTasksCount'));
    }

    public function test_invoice_due_today_and_overdue_use_ist_date_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-16 00:30:00', 'Asia/Kolkata'));

        $organization = TestData::organization();

        $todayDueInvoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-IST-001',
            'invoice_date' => '2026-05-16',
            'due_date' => '2026-05-16',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ]);

        $overdueInvoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-IST-002',
            'invoice_date' => '2026-05-15',
            'due_date' => '2026-05-15',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'total_amount' => 1200,
            'paid_amount' => 0,
            'balance_amount' => 1200,
        ]);

        $this->assertSame(
            'unpaid',
            Invoice::determineFinancialStatus(1000, 0, Carbon::parse('2026-05-16', 'Asia/Kolkata'), 'unpaid')
        );
        $this->assertSame(
            'overdue',
            Invoice::determineFinancialStatus(1200, 0, Carbon::parse('2026-05-15', 'Asia/Kolkata'), 'unpaid')
        );

        $overdueIds = Invoice::query()
            ->where('organization_id', $organization->id)
            ->overdue()
            ->pluck('id')
            ->all();

        $this->assertContains($overdueInvoice->id, $overdueIds);
        $this->assertNotContains($todayDueInvoice->id, $overdueIds);
    }
}
