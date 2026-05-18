<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class TaskBoardSortingRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-18 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_task_order_is_action_first_and_completed_last(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'taskboard-sort-default@example.com',
        ]));

        [$customer, $product] = $this->customerAndProduct($organization->id);

        $overduePending = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'pending', now()->subDay());
        $todayPending = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'pickup', 'pending', now()->addHour());
        $unscheduledPending = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'pending', null);
        $inProgress = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'pickup', 'in_progress', now()->addHours(2));
        $futurePending = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'pending', now()->addDay());
        $completed = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'completed', now()->subHours(2), now()->subHour());

        $otherOrganization = TestData::organization(['name' => 'Other Sorting Org']);
        [$otherCustomer, $otherProduct] = $this->customerAndProduct($otherOrganization->id);
        $this->makeSaleTask($otherOrganization->id, $otherCustomer->id, $otherProduct->id, 'delivery', 'pending', now()->subDays(2));

        $response = $this->get(route('deliveries.index'));

        $response->assertOk();

        $orderedIds = $response->viewData('tasks')->getCollection()->pluck('id')->all();

        $this->assertSame([
            $overduePending->id,
            $todayPending->id,
            $unscheduledPending->id,
            $inProgress->id,
            $futurePending->id,
            $completed->id,
        ], $orderedIds);
        $this->assertSame(6, (int) $response->viewData('taskResultsCount'));
    }

    public function test_sort_by_schedule_date_works(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'taskboard-sort-schedule@example.com',
        ]));

        [$customer, $product] = $this->customerAndProduct($organization->id);

        $yesterday = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'pending', now()->subDay());
        $today = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'pickup', 'pending', now()->addHour());
        $tomorrow = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'pending', now()->addDay());

        $response = $this->get(route('deliveries.index', [
            'status' => 'pending',
            'sort_by' => 'schedule_date',
            'sort_dir' => 'desc',
        ]));

        $response->assertOk();

        $this->assertSame([
            $tomorrow->id,
            $today->id,
            $yesterday->id,
        ], $response->viewData('tasks')->getCollection()->pluck('id')->all());
    }

    public function test_sort_by_status_works(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'taskboard-sort-status@example.com',
        ]));

        [$customer, $product] = $this->customerAndProduct($organization->id);

        $pending = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'pending', now()->addHour());
        $inProgress = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'pickup', 'in_progress', now()->addHours(2));
        $completed = $this->makeSaleTask($organization->id, $customer->id, $product->id, 'delivery', 'completed', now()->subHours(2), now()->subHour());

        $response = $this->get(route('deliveries.index', [
            'sort_by' => 'status',
            'sort_dir' => 'desc',
        ]));

        $response->assertOk();

        $this->assertSame([
            $completed->id,
            $inProgress->id,
            $pending->id,
        ], $response->viewData('tasks')->getCollection()->pluck('id')->all());
    }

    public function test_sort_query_persists_through_pagination(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization, [
            'email' => 'taskboard-sort-pagination@example.com',
        ]));

        [$customer, $product] = $this->customerAndProduct($organization->id);

        for ($index = 0; $index < 21; $index++) {
            $this->makeSaleTask(
                $organization->id,
                $customer->id,
                $product->id,
                $index % 2 === 0 ? 'delivery' : 'pickup',
                'pending',
                now()->addMinutes($index + 1)
            );
        }

        $response = $this->get(route('deliveries.index', [
            'status' => 'pending',
            'sort_by' => 'schedule_date',
            'sort_dir' => 'desc',
        ]));

        $response->assertOk()
            ->assertSee('sort_by=schedule_date', false)
            ->assertSee('sort_dir=desc', false)
            ->assertSee('status=pending', false)
            ->assertSee('page=2', false);
    }

    private function customerAndProduct(int $organizationId): array
    {
        $customer = Customer::create([
            'organization_id' => $organizationId,
            'name' => 'Taskboard Sorting Customer',
            'phone' => '9000000601',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organizationId,
            'name' => 'Taskboard Sorting Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 500,
            'available_quantity' => 50,
            'total_quantity' => 50,
        ]);

        return [$customer, $product];
    }

    private function makeSaleTask(
        int $organizationId,
        int $customerId,
        int $productId,
        string $type,
        string $status,
        ?Carbon $scheduledAt,
        ?Carbon $completedAt = null
    ): Delivery {
        $sale = Sale::create([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => 1,
            'unit_price' => 500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 500,
            'payment_status' => 'pending',
        ]);

        return Delivery::create([
            'organization_id' => $organizationId,
            'sale_id' => $sale->id,
            'type' => $type,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'completed_at' => $completedAt,
            'notes' => 'Taskboard sorting regression task',
        ]);
    }
}
