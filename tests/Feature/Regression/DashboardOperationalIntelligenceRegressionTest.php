<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class DashboardOperationalIntelligenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_surfaces_operational_intelligence_sections_and_quick_actions(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Rohit Gupta',
            'phone' => '9556722014',
            'address' => '#109, HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
            'map_url' => 'https://maps.example.test/customer',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Wheelchair Pro',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 6,
            'price_per_day' => 500,
            'rental_price' => 1500,
            'sale_price' => 0,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 250,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'failed_attempt',
            'failed_attempt_reason' => 'customer_not_available',
            'scheduled_at' => now()->subHour(),
        ]);

        FollowUp::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_RENEWAL,
            'title' => 'Urgent renewal callback',
            'note' => 'Need renewal confirmation today.',
            'due_at' => now(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_URGENT,
            'assigned_user_id' => $user->id,
            'created_by_user_id' => $user->id,
        ]);

        Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-DASH-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'bill_to_address' => $customer->address,
            'bill_to_city' => $customer->city,
            'bill_to_state' => $customer->state,
            'bill_to_pincode' => $customer->pincode,
            'status' => 'unpaid',
            'payment_status' => 'overdue',
            'subtotal' => 1500,
            'taxable_amount' => 1500,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'balance_amount' => 1500,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('PHOS Control Room')
            ->assertSeeText('Cash & Collections Overview')
            ->assertSeeText('Rental Operations Pipeline')
            ->assertSeeText('Operational Risk Board')
            ->assertSeeText('Staff Workload Overview')
            ->assertSeeText('Inventory Availability')
            ->assertSeeText('Business Performance')
            ->assertSeeText('Recent Activity')
            ->assertSeeText('Inventory Intelligence')
            ->assertSeeText('Add Business Partner')
            ->assertSeeText('Schedule Pickup')
            ->assertSeeText('Record Payment');
    }

    public function test_dashboard_renders_compact_insight_cards_with_key_labels_and_links(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Insight Customer',
            'phone' => '9556700001',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Insight Product',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 4,
            'total_quantity' => 6,
            'price_per_day' => 450,
            'rental_price' => 1200,
            'sale_price' => 5000,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'pending',
            'scheduled_at' => now()->addHour(),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'scheduled_at' => now()->addHours(2),
        ]);

        Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-DASH-INSIGHT-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'bill_to_name' => $customer->name,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1200,
            'taxable_amount' => 1200,
            'total_amount' => 1200,
            'paid_amount' => 0,
            'balance_amount' => 1200,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('Cash at Risk')
            ->assertSeeText('Follow-ups Overdue')
            ->assertSeeText('Renewals Overdue')
            ->assertSeeText('Staff Overloaded')
            ->assertSeeText('Operational Priorities')
            ->assertSeeText('Revenue Protection')
            ->assertSeeText('Inventory Readiness')
            ->assertSeeText('Reference KPIs')
            ->assertSeeText('Active Rentals')
            ->assertSeeText('Pending Deliveries')
            ->assertSeeText('Pending Pickups')
            ->assertSeeText('Outstanding Invoices')
            ->assertSeeText('Collections This Month')
            ->assertSeeText('Unbilled Rentals')
            ->assertSeeText('Unbilled Sales')
            ->assertSeeText('Unpaid Renewal Invoices')
            ->assertSeeText('Open Invoices')
            ->assertSeeText('Collections Today')
            ->assertSeeText('Rental Available')
            ->assertSeeText('Sale Stock Available')
            ->assertSeeText('Asset Alerts')
            ->assertSeeText('Returns Expected')
            ->assertSeeText('Total Customers')
            ->assertSeeText('Products')
            ->assertSee(route('customers.index'), false)
            ->assertSee(route('rentals.index', ['status' => 'live']), false);
    }

    public function test_dashboard_empty_state_renders_compact_insight_cards_safely(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('Active Rentals')
            ->assertSeeText('No urgent action')
            ->assertSeeText('Collections')
            ->assertSeeText('Products');
    }

    public function test_dashboard_exposes_follow_up_and_failed_pickup_counts_for_operational_alerts(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Alert Customer',
            'phone' => '9444400011',
            'address' => '12 Pickup Street',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Alert Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 4,
            'total_quantity' => 4,
            'price_per_day' => 300,
            'rental_price' => 900,
            'sale_price' => 0,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'rental_amount' => 900,
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDays(8),
            'completed_at' => now()->subDays(8),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'failed_attempt',
            'scheduled_at' => now()->toDateTimeString(),
        ]);

        FollowUp::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_PICKUP,
            'title' => 'Pickup callback',
            'due_at' => now(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_HIGH,
            'assigned_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertViewHas('followUpsDueTodayCount', 1)
            ->assertViewHas('failedPickupsCount', 1)
            ->assertViewHas('overdueCount', 1);
    }

    public function test_delivery_dashboard_hides_finance_and_partner_intelligence_blocks(): void
    {
        $organization = TestData::organization();
        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Team',
            'slug' => User::ROLE_DELIVERY,
            'permissions' => [
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
            ],
        ]);

        $deliveryUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_DELIVERY,
            'role_id' => $deliveryRole->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($deliveryUser)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('Hi, Delivery Team')
            ->assertSeeText('field work today')
            ->assertSeeText('My Deliveries Today')
            ->assertSeeText('My Pickups Today')
            ->assertSeeText('My Assigned Tasks')
            ->assertSeeText('Failed Attempts')
            ->assertDontSeeText('Active Rentals')
            ->assertDontSeeText('All Overdue Rentals')
            ->assertDontSeeText('Returns Expected')
            ->assertDontSeeText('rental base')
            ->assertDontSeeText('Business Partner Signals')
            ->assertDontSeeText('Staff Workload')
            ->assertDontSeeText('Inventory Intelligence')
            ->assertDontSeeText('Sales Pulse')
            ->assertDontSeeText('Pending Payments')
            ->assertDontSeeText('Finance Summary')
            ->assertSeeText('Today')
            ->assertSeeText('Task Flow');
    }

    public function test_delivery_dashboard_counts_all_open_assigned_tasks_without_collapsing_duplicate_task_records(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));

        $organization = TestData::organization();
        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Team',
            'slug' => User::ROLE_DELIVERY,
            'permissions' => [
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
            ],
        ]);

        $deliveryUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_DELIVERY,
            'role_id' => $deliveryRole->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Field Ops Customer',
            'phone' => '9000000001',
            'address' => 'HSR Layout',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Field Ops Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 20,
            'total_quantity' => 20,
            'price_per_day' => 200,
            'rental_price' => 1200,
            'sale_price' => 0,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
        ]);

        foreach (range(1, 11) as $index) {
            Delivery::create([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'assigned_user_id' => $deliveryUser->id,
                'type' => $index % 2 === 0 ? 'pickup' : 'delivery',
                'status' => $index % 3 === 0 ? 'in_progress' : 'pending',
                'scheduled_at' => now()->addHours($index),
            ]);
        }

        $response = $this->actingAs($deliveryUser)->get(route('dashboard'));

        $response->assertOk()
            ->assertViewHas('dashboardTaskMetricsScope', 'assigned')
            ->assertViewHas('assignedOpenTasksCount', 11)
            ->assertViewHas('myDeliveriesTodayCount', 6)
            ->assertViewHas('myPickupsTodayCount', 5);

        $this->travelBack();
    }

    public function test_delivery_dashboard_kpis_match_default_taskboard_counts_without_mutating_rows(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));

        $organization = TestData::organization();
        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Team',
            'slug' => User::ROLE_DELIVERY,
            'permissions' => [
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
            ],
        ]);

        $deliveryUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_DELIVERY,
            'role_id' => $deliveryRole->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Parity Customer',
            'phone' => '9000000012',
            'address' => 'Koramangala',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Parity Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 30,
            'total_quantity' => 30,
            'price_per_day' => 200,
            'rental_price' => 1200,
            'sale_price' => 0,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
        ]);

        $taskBlueprints = [
            ['type' => 'delivery', 'status' => 'pending', 'scheduled_at' => now()->copy()->addHour()],
            ['type' => 'delivery', 'status' => 'in_progress', 'scheduled_at' => now()->copy()->addHours(2)],
            ['type' => 'delivery', 'status' => 'pending', 'scheduled_at' => now()->copy()->addHours(3)],
            ['type' => 'delivery', 'status' => 'pending', 'scheduled_at' => now()->copy()->subDay()],
            ['type' => 'delivery', 'status' => 'completed', 'scheduled_at' => now()->copy()->subHours(5), 'completed_at' => now()->copy()->subHours(2)],
            ['type' => 'delivery', 'status' => 'cancelled', 'scheduled_at' => now()->copy()->subHours(4)],
            ['type' => 'pickup', 'status' => 'pending', 'scheduled_at' => now()->copy()->addHour()],
            ['type' => 'pickup', 'status' => 'in_progress', 'scheduled_at' => now()->copy()->addHours(4)],
            ['type' => 'pickup', 'status' => 'pending', 'scheduled_at' => now()->copy()->subDay()],
            ['type' => 'pickup', 'status' => 'completed', 'scheduled_at' => now()->copy()->subHours(6), 'completed_at' => now()->copy()->subHour()],
            ['type' => 'pickup', 'status' => 'pending', 'scheduled_at' => now()->copy()->addDays(1)],
            ['type' => 'pickup', 'status' => 'cancelled', 'scheduled_at' => now()->copy()->subHours(3), 'pickup_status' => 'failed_attempt', 'failed_attempt_reason' => 'customer_not_available'],
        ];

        foreach ($taskBlueprints as $task) {
            Delivery::create(array_merge([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'assigned_user_id' => $deliveryUser->id,
            ], $task));
        }

        $beforeIds = Delivery::query()->orderBy('id')->pluck('id')->all();
        $beforeStatuses = Delivery::query()->orderBy('id')->pluck('status', 'id')->all();

        $dashboard = $this->actingAs($deliveryUser)->get(route('dashboard'));
        $taskboard = $this->actingAs($deliveryUser)->get(route('deliveries.index'));

        $dashboard->assertOk()
            ->assertViewHas('dashboardTaskMetricsScope', 'assigned')
            ->assertSee(route('deliveries.index', ['ownership' => 'my']), false)
            ->assertDontSee(route('deliveries.index', ['ownership' => 'my', 'workflow' => 'live']), false);
        $taskboard->assertOk();

        $this->assertSame((int) $taskboard->viewData('activeTasksCount'), (int) $dashboard->viewData('assignedOpenTasksCount'));
        $this->assertSame((int) $taskboard->viewData('todayOpenDeliveryCount'), (int) $dashboard->viewData('myDeliveriesTodayCount'));
        $this->assertSame((int) $taskboard->viewData('todayOpenPickupCount'), (int) $dashboard->viewData('myPickupsTodayCount'));
        $this->assertSame((int) $taskboard->viewData('overdueTasksCount'), (int) ($dashboard->viewData('overdueDeliveryCount') + $dashboard->viewData('overduePickupCount')));
        $this->assertSame((int) $taskboard->viewData('failedTasksCount'), (int) $dashboard->viewData('failedTasksCount'));

        $afterIds = Delivery::query()->orderBy('id')->pluck('id')->all();
        $afterStatuses = Delivery::query()->orderBy('id')->pluck('status', 'id')->all();

        $this->assertSame($beforeIds, $afterIds);
        $this->assertSame($beforeStatuses, $afterStatuses);

        $this->travelBack();
    }

    public function test_sales_dashboard_is_accessible_without_dashboard_main_and_hides_sensitive_management_widgets(): void
    {
        $organization = TestData::organization();
        $salesRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Sales User',
            'slug' => User::ROLE_SALES,
            'permissions' => [
                'customers' => ['read', 'create'],
                'rentals' => ['read', 'create', 'update'],
                'sales' => ['read', 'create'],
                'invoices' => ['read'],
            ],
        ]);

        $salesUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_SALES,
            'role_id' => $salesRole->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($salesUser)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('Add Follow-up')
            ->assertSeeText('Today\'s Renewals')
            ->assertSeeText('Today\'s Follow-ups')
            ->assertDontSeeText('My Active Rentals')
            ->assertDontSeeText('Sales Pulse')
            ->assertDontSeeText('Staff Workload')
            ->assertDontSeeText('Business Partner Signals')
            ->assertDontSeeText('Inventory Intelligence')
            ->assertDontSeeText('No partner pressure signals')
            ->assertDontSeeText('Finance Summary')
            ->assertDontSeeText('Collections This Month');
    }

    public function test_finance_dashboard_can_access_dashboard_and_see_finance_summary(): void
    {
        $organization = TestData::organization();
        $financeRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Finance User',
            'slug' => User::ROLE_FINANCE,
            'permissions' => [
                'invoices' => ['read', 'update'],
                'payments' => ['read', 'create', 'update'],
            ],
        ]);

        $financeUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_FINANCE,
            'role_id' => $financeRole->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($financeUser)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('Finance Summary')
            ->assertSeeText('Record Payment')
            ->assertDontSeeText('Staff Workload')
            ->assertDontSeeText('Business Partner Signals')
            ->assertDontSeeText('Inventory Intelligence')
            ->assertDontSeeText('Sales Pulse');
    }

    public function test_warehouse_style_dashboard_can_access_and_see_inventory_intelligence_without_finance(): void
    {
        $organization = TestData::organization();
        $warehouseRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Warehouse User',
            'slug' => 'warehouse_user',
            'permissions' => [
                'assets' => ['read'],
                'warehouses' => ['read'],
                'products' => ['read'],
            ],
        ]);

        $warehouseUser = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => 'warehouse_user',
            'role_id' => $warehouseRole->id,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($warehouseUser)->get(route('dashboard'));

        $response->assertOk()
            ->assertSeeText('Inventory Intelligence')
            ->assertSeeText('Asset Alerts')
            ->assertDontSeeText('Staff Workload')
            ->assertDontSeeText('Business Partner Signals')
            ->assertDontSeeText('Sales Pulse')
            ->assertDontSeeText('Finance Summary')
            ->assertDontSeeText('Collections This Month');
    }
}
