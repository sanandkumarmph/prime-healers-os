<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestData;
use Tests\TestCase;

class RoleScopedKpiConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_and_delivery_team_share_org_level_module_kpis_while_my_tasks_is_explicit(): void
    {
        $organization = TestData::organization();
        $superadmin = TestData::user($organization);
        $deliveryRole = $this->deliveryTeamRole($organization->id);
        $deliveryUser = $this->deliveryTeamUser($organization->id, $deliveryRole->id, 'delivery.agent@example.com');
        $otherDeliveryUser = $this->deliveryTeamUser($organization->id, $deliveryRole->id, 'delivery.other@example.com');

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Org Scope Customer',
            'phone' => '9900000101',
            'city' => 'Bengaluru',
        ]);

        $rentalProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Org Scope Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 400,
            'rental_price' => 400,
            'sale_price' => 0,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $saleProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Org Scope Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 300,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $rentalAssignedToDeliveryUser = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 500,
        ]);

        $rentalAssignedToOtherUser = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, [
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDays(1)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'rental_amount' => 650,
        ]);

        $saleOne = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 300);
        $saleTwo = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 450);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rentalAssignedToDeliveryUser->id,
            'assigned_user_id' => $deliveryUser->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subHours(4),
            'completed_at' => now()->subHours(2),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rentalAssignedToOtherUser->id,
            'assigned_user_id' => $otherDeliveryUser->id,
            'type' => 'delivery',
            'status' => 'pending',
            'scheduled_at' => now()->addHour(),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleOne->id,
            'assigned_user_id' => $deliveryUser->id,
            'type' => 'pickup',
            'status' => 'completed',
            'scheduled_at' => now()->subHours(5),
            'completed_at' => now()->subHours(1),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $saleTwo->id,
            'assigned_user_id' => $otherDeliveryUser->id,
            'type' => 'pickup',
            'status' => 'pending',
            'scheduled_at' => now()->addHours(2),
        ]);

        $this->makeInvoice($organization->id, $customer->id, [
            'invoice_number' => 'INV-ORG-001',
            'rental_id' => $rentalAssignedToDeliveryUser->id,
            'subtotal' => 500,
            'taxable_amount' => 500,
            'total_amount' => 500,
            'paid_amount' => 0,
            'balance_amount' => 500,
        ]);

        $this->makeInvoice($organization->id, $customer->id, [
            'invoice_number' => 'INV-ORG-002',
            'sale_id' => $saleOne->id,
            'subtotal' => 300,
            'taxable_amount' => 300,
            'total_amount' => 300,
            'paid_amount' => 100,
            'balance_amount' => 200,
            'payment_status' => 'partial',
            'status' => 'partial',
        ]);

        $otherOrganization = TestData::organization(['name' => 'Other Org']);
        $otherCustomer = Customer::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Org Customer',
            'phone' => '9900000199',
            'city' => 'Chennai',
        ]);
        $otherProduct = Product::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Org Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 100,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);
        $otherSale = $this->makeSale($otherOrganization->id, $otherCustomer->id, $otherProduct->id, 100);
        Delivery::create([
            'organization_id' => $otherOrganization->id,
            'sale_id' => $otherSale->id,
            'type' => 'delivery',
            'status' => 'pending',
            'scheduled_at' => now(),
        ]);
        $this->makeInvoice($otherOrganization->id, $otherCustomer->id, [
            'invoice_number' => 'INV-OTHER-001',
            'sale_id' => $otherSale->id,
            'subtotal' => 100,
            'taxable_amount' => 100,
            'total_amount' => 100,
            'balance_amount' => 100,
        ]);

        $superadminTaskboard = $this->actingAs($superadmin)->get(route('deliveries.index'));
        $deliveryTaskboard = $this->actingAs($deliveryUser)->get(route('deliveries.index'));
        $myTaskboard = $this->actingAs($deliveryUser)->get(route('deliveries.index', ['ownership' => 'my']));

        $superadminTaskboard->assertOk();
        $deliveryTaskboard->assertOk();
        $myTaskboard->assertOk();

        $this->assertSame((int) $superadminTaskboard->viewData('totalTasksCount'), (int) $deliveryTaskboard->viewData('totalTasksCount'));
        $this->assertSame((int) $superadminTaskboard->viewData('deliveryTasksCount'), (int) $deliveryTaskboard->viewData('deliveryTasksCount'));
        $this->assertSame((int) $superadminTaskboard->viewData('pickupTasksCount'), (int) $deliveryTaskboard->viewData('pickupTasksCount'));
        $this->assertSame((int) $superadminTaskboard->viewData('completedDeliveryCount'), (int) $deliveryTaskboard->viewData('completedDeliveryCount'));
        $this->assertSame((int) $superadminTaskboard->viewData('completedPickupCount'), (int) $deliveryTaskboard->viewData('completedPickupCount'));
        $this->assertSame((int) $superadminTaskboard->viewData('completedTodayCount'), (int) $deliveryTaskboard->viewData('completedTodayCount'));
        $this->assertSame(4, (int) $deliveryTaskboard->viewData('taskResultsCount'));
        $this->assertSame(2, (int) $myTaskboard->viewData('taskResultsCount'));

        $superadminRentals = $this->actingAs($superadmin)->get(route('rentals.index'));
        $deliveryRentals = $this->actingAs($deliveryUser)->get(route('rentals.index'));
        $superadminRentals->assertOk();
        $deliveryRentals->assertOk();
        $this->assertSame((int) $superadminRentals->viewData('totalRentals'), (int) $deliveryRentals->viewData('totalRentals'));
        $this->assertSame((int) $superadminRentals->viewData('activeRentals'), (int) $deliveryRentals->viewData('activeRentals'));

        $superadminSales = $this->actingAs($superadmin)->get(route('sales.index'));
        $deliverySales = $this->actingAs($deliveryUser)->get(route('sales.index'));
        $superadminSales->assertOk();
        $deliverySales->assertOk();
        $this->assertSame((int) $superadminSales->viewData('totalSales'), (int) $deliverySales->viewData('totalSales'));
        $this->assertSame((float) $superadminSales->viewData('totalSalesAmount'), (float) $deliverySales->viewData('totalSalesAmount'));

        $superadminInvoices = $this->actingAs($superadmin)->get(route('invoices.index'));
        $deliveryInvoices = $this->actingAs($deliveryUser)->get(route('invoices.index'));
        $superadminInvoices->assertOk();
        $deliveryInvoices->assertOk();
        $this->assertSame(
            (int) (($superadminInvoices->viewData('invoiceStats')['totalInvoices'] ?? 0)),
            (int) (($deliveryInvoices->viewData('invoiceStats')['totalInvoices'] ?? 0))
        );
        $this->assertSame(
            (float) (($superadminInvoices->viewData('invoiceStats')['outstandingAmount'] ?? 0)),
            (float) (($deliveryInvoices->viewData('invoiceStats')['outstandingAmount'] ?? 0))
        );
    }

    public function test_superadmin_and_sales_user_share_org_level_rental_sales_and_invoice_lists(): void
    {
        $organization = TestData::organization();
        $superadmin = TestData::user($organization);
        $salesRole = $this->salesRole($organization->id);
        $salesUser = $this->salesUser($organization->id, $salesRole->id, 'sales.agent@example.com');

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Scoped Sales Customer',
            'phone' => '9900000201',
            'city' => 'Bengaluru',
        ]);

        $rentalProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Scoped Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 250,
            'rental_price' => 250,
            'sale_price' => 0,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $saleProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Scoped Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 300,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);

        $creatorColumns = [
            'created_by_user_id' => Schema::hasColumn('rentals', 'created_by_user_id') ? $superadmin->id : null,
            'created_by' => Schema::hasColumn('sales', 'created_by') ? $superadmin->id : null,
            'sales_created_by_user_id' => Schema::hasColumn('sales', 'created_by_user_id') ? $superadmin->id : null,
            'invoice_created_by' => Schema::hasColumn('invoices', 'created_by') ? $superadmin->id : null,
        ];

        $rentalOne = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, array_filter([
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'rental_amount' => 500,
            'created_by_user_id' => $creatorColumns['created_by_user_id'],
        ], fn ($value) => $value !== null));

        $rentalTwo = $this->makeRental($organization->id, $customer->id, $rentalProduct->id, array_filter([
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'rental_amount' => 650,
            'created_by_user_id' => $creatorColumns['created_by_user_id'],
        ], fn ($value) => $value !== null));

        $saleOne = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 300, array_filter([
            'created_by' => $creatorColumns['created_by'],
            'created_by_user_id' => $creatorColumns['sales_created_by_user_id'],
        ], fn ($value) => $value !== null));
        $saleTwo = $this->makeSale($organization->id, $customer->id, $saleProduct->id, 450, array_filter([
            'created_by' => $creatorColumns['created_by'],
            'created_by_user_id' => $creatorColumns['sales_created_by_user_id'],
        ], fn ($value) => $value !== null));

        $this->makeInvoice($organization->id, $customer->id, array_filter([
            'invoice_number' => 'INV-SCOPE-001',
            'rental_id' => $rentalOne->id,
            'subtotal' => 500,
            'taxable_amount' => 500,
            'total_amount' => 500,
            'balance_amount' => 500,
            'created_by' => $creatorColumns['invoice_created_by'],
        ], fn ($value) => $value !== null));

        $this->makeInvoice($organization->id, $customer->id, array_filter([
            'invoice_number' => 'INV-SCOPE-002',
            'sale_id' => $saleOne->id,
            'subtotal' => 300,
            'taxable_amount' => 300,
            'total_amount' => 300,
            'paid_amount' => 100,
            'balance_amount' => 200,
            'payment_status' => 'partial',
            'status' => 'partial',
            'created_by' => $creatorColumns['invoice_created_by'],
        ], fn ($value) => $value !== null));

        $otherOrganization = TestData::organization(['name' => 'Other Scope Org']);
        $otherCustomer = Customer::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Scope Customer',
            'phone' => '9900000299',
            'city' => 'Chennai',
        ]);
        $otherProduct = Product::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Scope Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 100,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);
        $this->makeRental($otherOrganization->id, $otherCustomer->id, $otherProduct->id, [
            'customer_name' => $otherCustomer->name,
            'phone' => $otherCustomer->phone,
            'rental_amount' => 999,
        ]);
        $otherSale = $this->makeSale($otherOrganization->id, $otherCustomer->id, $otherProduct->id, 100);
        $this->makeInvoice($otherOrganization->id, $otherCustomer->id, [
            'invoice_number' => 'INV-SCOPE-OTHER',
            'sale_id' => $otherSale->id,
            'subtotal' => 100,
            'taxable_amount' => 100,
            'total_amount' => 100,
            'balance_amount' => 100,
        ]);

        $superadminRentals = $this->actingAs($superadmin)->get(route('rentals.index'));
        $salesUserRentals = $this->actingAs($salesUser)->get(route('rentals.index'));
        $superadminSales = $this->actingAs($superadmin)->get(route('sales.index'));
        $salesUserSales = $this->actingAs($salesUser)->get(route('sales.index'));
        $superadminInvoices = $this->actingAs($superadmin)->get(route('invoices.index'));
        $salesUserInvoices = $this->actingAs($salesUser)->get(route('invoices.index'));

        $superadminRentals->assertOk();
        $salesUserRentals->assertOk();
        $superadminSales->assertOk();
        $salesUserSales->assertOk();
        $superadminInvoices->assertOk();
        $salesUserInvoices->assertOk();

        $this->assertSame(2, (int) $superadminRentals->viewData('rentals')->total());
        $this->assertSame((int) $superadminRentals->viewData('rentals')->total(), (int) $salesUserRentals->viewData('rentals')->total());
        $this->assertSame((int) $superadminRentals->viewData('totalRentals'), (int) $salesUserRentals->viewData('totalRentals'));

        $this->assertSame(2, (int) $superadminSales->viewData('sales')->total());
        $this->assertSame((int) $superadminSales->viewData('sales')->total(), (int) $salesUserSales->viewData('sales')->total());
        $this->assertSame((int) $superadminSales->viewData('totalSales'), (int) $salesUserSales->viewData('totalSales'));

        $this->assertSame(2, (int) $superadminInvoices->viewData('invoices')->total());
        $this->assertSame((int) $superadminInvoices->viewData('invoices')->total(), (int) $salesUserInvoices->viewData('invoices')->total());
        $this->assertSame(
            (int) (($superadminInvoices->viewData('invoiceStats')['totalInvoices'] ?? 0)),
            (int) (($salesUserInvoices->viewData('invoiceStats')['totalInvoices'] ?? 0))
        );
    }

    private function deliveryTeamRole(int $organizationId): Role
    {
        return Role::firstOrCreate([
            'organization_id' => $organizationId,
            'slug' => User::ROLE_DELIVERY,
        ], [
            'name' => 'Delivery Team',
            'description' => 'Delivery Team',
            'permissions' => Role::normalizePermissions([
                'customers' => ['read'],
                'products' => ['read'],
                'assets' => ['read'],
                'rentals' => ['read'],
                'sales' => ['read'],
                'invoices' => ['read'],
                'deliveries' => ['read', 'update'],
            ]),
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function deliveryTeamUser(int $organizationId, int $roleId, string $email): User
    {
        return TestData::user(null, [
            'organization_id' => $organizationId,
            'role' => 'staff',
            'role_id' => $roleId,
            'email' => $email,
        ]);
    }

    private function salesRole(int $organizationId): Role
    {
        return Role::firstOrCreate([
            'organization_id' => $organizationId,
            'slug' => User::ROLE_SALES,
        ], [
            'name' => 'Sales User',
            'description' => 'Sales User',
            'permissions' => Role::normalizePermissions([
                'customers' => ['read'],
                'products' => ['read'],
                'rentals' => ['read'],
                'sales' => ['read'],
                'invoices' => ['read'],
            ]),
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function salesUser(int $organizationId, int $roleId, string $email): User
    {
        return TestData::user(null, [
            'organization_id' => $organizationId,
            'role' => 'staff',
            'role_id' => $roleId,
            'email' => $email,
        ]);
    }

    private function makeRental(int $organizationId, int $customerId, int $productId, array $overrides = []): Rental
    {
        return Rental::create(array_merge([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'customer_name' => 'Org Scope Customer',
            'phone' => '9900000101',
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

    private function makeSale(int $organizationId, int $customerId, int $productId, float $amount, array $overrides = []): Sale
    {
        return Sale::create(array_merge([
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
        ], $overrides));
    }

    private function makeInvoice(int $organizationId, int $customerId, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $organizationId,
            'invoice_number' => 'INV-ROLE-' . fake()->unique()->numerify('####'),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customerId,
            'bill_to_name' => 'Org Scope Customer',
            'bill_to_phone' => '9900000101',
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
}
