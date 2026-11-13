<?php

namespace Tests\Feature\Regression;

use App\Models\City;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
use App\Models\Warehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class VendorFulfilmentRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_supplied_rental_requires_vendor_and_skips_ph_stock_checks(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);
        $vendor = $this->makeVendor($organization->id);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            ]))
            ->assertSessionHasErrors('vendor_id');

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_responsibility' => 'vendor_delivery',
                'pickup_responsibility' => 'vendor_pickup',
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = \App\Models\Rental::query()->latest('id')->first();
        $this->assertNotNull($rental);
        $this->assertTrue($rental->isVendorSupplied());
        $this->assertSame(0, (int) $product->fresh()->available_quantity);
        $this->assertSame($vendor->id, $rental->vendorOrderDetail?->vendor_id);
    }

    public function test_sales_can_assign_order_collections_and_book_vendor_or_third_party_delivery(): void
    {
        $organization = TestData::organization();
        $actor = TestData::user($organization);
        $salesRole = Role::create([
            'organization_id' => $organization->id, 'name' => 'Sales', 'slug' => 'sales', 'is_active' => true,
            'permissions' => ['sales' => ['read', 'create'], 'deliveries' => ['read'], 'invoices' => ['read'], 'payments' => ['read']],
        ]);
        $collector = TestData::user($organization, ['role_id' => $salesRole->id]);
        $driver = TestData::user($organization, ['role' => User::ROLE_DELIVERY]);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id);
        $vendor = $this->makeVendor($organization->id);

        $this->actingAs($collector)->get(route('enquiries.index'))->assertOk()
            ->assertSee('Sales workspace')->assertSee('Route planner');
        $this->get(route('enquiries.index', ['view' => 'quotations']))->assertOk();
        $this->get(route('enquiries.create'))->assertOk();
        $this->assertFalse($collector->canAccessModule('quotations', 'delete'));

        $this->actingAs($actor)->get(route('rentals.create'))->assertOk()
            ->assertSee('Vendor Delivery')->assertSee('Third Party Logistics')->assertSee('Assign collection to Sales');
        $rentalData = $this->rentalPayload($customer->id, $product->id, [
            'delivery_assignment_type' => 'vendor',
            'delivery_vendor_id' => $vendor->id,
            'collection_mode' => 'pay_on_delivery',
        ]);
        $this->post(route('rentals.store'), $rentalData + ['collection_assignee_id' => $driver->id])
            ->assertSessionHasErrors('collection_assignee_id');
        $this->post(route('rentals.store'), $rentalData + ['collection_assignee_id' => $collector->id])
            ->assertSessionHasNoErrors();
        $rental = \App\Models\Rental::sole();
        $this->assertFalse($rental->isVendorSupplied());
        $this->assertSame(3, (int) $product->fresh()->available_quantity);
        $this->assertSame($vendor->id, $rental->vendorOrderDetail->vendor_id);
        $this->assertSame('vendor_delivery', $rental->vendorOrderDetail->delivery_responsibility);
        $this->assertDatabaseHas('deliveries', [
            'rental_id' => $rental->id, 'assignment_type' => 'vendor', 'assigned_user_id' => null,
        ]);
        $this->assertDatabaseHas('follow_ups', [
            'assigned_user_id' => $collector->id, 'rental_id' => $rental->id,
            'invoice_id' => \App\Models\Invoice::where('rental_id', $rental->id)->sole()->id,
            'source' => 'order_collection:pay_on_delivery',
        ]);

        $this->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
            'delivery_assignment_type' => 'third_party', 'third_party_name' => 'One-time courier',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deliveries', ['assignment_type' => 'third_party', 'third_party_name' => 'One-time courier']);

        $sellable = $this->makeSaleProduct($organization->id);
        $this->get(route('sales.create'))->assertOk()->assertSee('Assign collection to Sales');
        $this->post(route('sales.store'), $this->salePayload($customer->id, $sellable->id, [
            'collection_mode' => 'cash', 'collection_assignee_id' => $collector->id,
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('follow_ups', [
            'assigned_user_id' => $collector->id, 'sale_id' => Sale::sole()->id, 'source' => 'order_collection:cash',
        ]);
        $this->assertDatabaseCount('payments', 0);
        $this->actingAs($collector)->get(route('communication-center.index'))
            ->assertOk()->assertSee('Payment on delivery for')->assertSee('Cash collection for');
    }

    public function test_rental_form_can_assign_replace_and_remove_vendor_delivery_login(): void
    {
        $organization = TestData::organization();
        $admin = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN]);
        $first = TestData::user($organization, ['role' => User::ROLE_VENDOR, 'name' => 'First Rental Vendor Login']);
        $second = TestData::user($organization, ['role' => User::ROLE_VENDOR, 'name' => 'Second Rental Vendor Login']);
        $foreign = TestData::user(null, ['role' => User::ROLE_VENDOR, 'name' => 'Foreign Rental Vendor Login']);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id);
        $vendor = $this->makeVendor($organization->id);
        $payload = $this->rentalPayload($customer->id, $product->id, [
            'delivery_assignment_type' => 'vendor',
            'delivery_vendor_id' => $vendor->id,
            'vendor_user_id' => $first->id,
        ]);

        $this->actingAs($admin)->get(route('rentals.create'))->assertOk()
            ->assertSee('name="vendor_user_id"', false)->assertSeeText($first->name)->assertDontSeeText($foreign->name);
        $this->postJson(route('rentals.store'), array_replace($payload, ['vendor_user_id' => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('vendor_user_id');
        $this->assertDatabaseCount('rentals', 0);

        $this->post(route('rentals.store'), $payload)->assertSessionHasNoErrors();
        $rental = Rental::sole();
        $delivery = $rental->deliveries()->where('type', 'delivery')->sole();
        $this->assertSame('vendor', $delivery->assignment_type);
        $this->assertSame($first->id, $delivery->assigned_user_id);
        $this->assertSame($vendor->id, $rental->vendorOrderDetail->vendor_id);
        $this->get(route('rentals.edit', $rental))->assertOk()
            ->assertSee('value="'.$first->id.'" selected>'.$first->name, false);
        $this->actingAs($first)->get(route('deliveries.show', $delivery))->assertOk();
        $this->get(route('rentals.show', $rental))->assertOk();
        $this->actingAs($second)->get(route('deliveries.show', $delivery))->assertForbidden();

        $this->actingAs($admin)->put(route('rentals.update', $rental), array_replace($payload, ['vendor_user_id' => $second->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($second->id, $delivery->fresh()->assigned_user_id);
        $this->actingAs($first)->get(route('deliveries.show', $delivery))->assertForbidden();
        $this->get(route('rentals.show', $rental))->assertForbidden();
        $this->actingAs($second)->get(route('deliveries.show', $delivery))->assertOk();
        $this->get(route('rentals.show', $rental))->assertOk();

        $this->actingAs($admin)->put(route('rentals.update', $rental), array_replace($payload, ['vendor_user_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($delivery->fresh()->assigned_user_id);
        $this->assertSame('vendor', $delivery->fresh()->assignment_type);
        $this->assertSame($vendor->id, $rental->fresh()->vendorOrderDetail->vendor_id);
        $this->actingAs($second)->get(route('rentals.show', $rental))->assertForbidden();
    }

    public function test_rental_edit_preserves_a_scheduled_in_house_vendor_pickup(): void
    {
        $organization = TestData::organization();
        $admin = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN]);
        $login = TestData::user($organization, ['role' => User::ROLE_VENDOR]);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id);
        $vendor = $this->makeVendor($organization->id);
        $payload = $this->rentalPayload($customer->id, $product->id);

        $this->actingAs($admin)->post(route('rentals.store'), $payload)->assertSessionHasNoErrors();
        $rental = Rental::sole();
        $this->post(route('renewal-center.schedule-pickup', $rental), [
            'pickup_method' => 'vendor_pickup',
            'pickup_date' => now()->addDay()->toDateString(),
            'vendor_id' => $vendor->id,
            'vendor_user_id' => $login->id,
        ])->assertSessionHasNoErrors();
        $pickup = $rental->deliveries()->where('type', 'pickup')->sole();

        unset($payload['pickup_responsibility']);
        $this->put(route('rentals.update', $rental), $payload + ['notes' => 'Updated rental note'])
            ->assertSessionHasNoErrors();
        $this->assertSame('vendor_pickup', $rental->fresh()->pickup_responsibility);
        $this->assertSame('vendor', $pickup->fresh()->assignment_type);
        $this->assertSame($login->id, $pickup->fresh()->assigned_user_id);
        $this->assertSame($vendor->name, $pickup->fresh()->third_party_name);
        $this->actingAs($login)->get(route('deliveries.show', $pickup))->assertOk();
        $this->get(route('rentals.show', $rental))->assertOk();
    }

    public function test_order_collection_creates_only_one_assigned_task_and_no_receipt(): void
    {
        $organization = TestData::organization();
        $admin = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN]);
        $driver = TestData::user($organization, ['role' => User::ROLE_DELIVERY]);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id);
        $this->actingAs($admin);

        $assignment = [
            'collection_enabled' => 1, 'collection_amount' => '100.00', 'collection_methods' => 'cash',
            'collection_instructions' => 'Ask for exact change.',
            'collection_submission_token' => (string) \Illuminate\Support\Str::uuid(),
        ];
        $rental = $this->rentalPayload($customer->id, $product->id, $assignment + [
            'delivery_assignment_type' => 'ph_internal', 'delivery_staff_id' => 'user:'.$driver->id,
        ]);
        $this->get(route('rentals.create'))->assertOk()->assertSee('Collect payment on delivery');
        $this->post(route('rentals.store'), array_replace($rental, ['collection_amount' => '99999.00']))
            ->assertSessionHasErrors('collection_amount');
        $this->assertDatabaseCount('rentals', 0);
        $this->assertDatabaseCount('order_collection_submissions', 0);
        $this->post(route('rentals.store'), $rental)->assertSessionHasNoErrors();
        $this->postJson(route('rentals.store'), $rental)->assertConflict()->assertJsonValidationErrors('collection_enabled');
        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseHas('deliveries', [
            'rental_id' => \App\Models\Rental::sole()->id, 'assigned_user_id' => $driver->id,
            'collection_required' => true, 'collection_allowed_methods' => 'cash',
            'collection_instructions' => 'Ask for exact change.', 'collection_amount_to_collect' => 100,
        ]);

        $productForSale = $this->makeSaleProduct($organization->id);
        $sale = $this->salePayload($customer->id, $productForSale->id, array_replace($assignment, [
            'delivery_operator_id' => $driver->id, 'collection_methods' => 'both',
            'collection_submission_token' => (string) \Illuminate\Support\Str::uuid(),
        ]));
        $this->get(route('sales.create'))->assertOk()->assertSee('Collect payment on delivery')->assertSee($driver->name);
        $this->post(route('sales.store'), array_replace($sale, ['delivery_operator_id' => $admin->id]))
            ->assertSessionHasErrors('collection_enabled');
        $this->assertDatabaseCount('sales', 0);
        $this->post(route('sales.store'), $sale)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deliveries', [
            'sale_id' => Sale::sole()->id, 'type' => 'delivery', 'assigned_user_id' => $driver->id,
            'assignment_type' => 'delivery_team', 'collection_required' => true, 'collection_allowed_methods' => 'both',
        ]);
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_vendor_supplied_rental_accepts_dual_purpose_product_without_ph_asset_requirement(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $vendor = $this->makeVendor($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'name' => 'Dual Purpose Vendor Rental',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'is_sellable' => true,
            'is_rentable' => true,
            'available_quantity' => 0,
            'total_quantity' => 0,
            'sale_price' => 1200,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_responsibility' => 'vendor_delivery',
                'pickup_responsibility' => 'vendor_pickup',
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $this->assertDatabaseHas('rentals', [
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
        ]);
    }

    public function test_vendor_supplied_flow_exposes_catalogue_only_helper_without_using_ph_asset_warning_copy(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $this->makeRentalProduct($organization->id, [
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee('Vendor supplied.');
        $response->assertSee('data-vendor-option-label=', false);
        $response->assertSee('Vendor supplied rentals do not reserve PH stock or rental assets.');
    }

    public function test_in_house_rental_keeps_existing_stock_behavior(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'quantity' => 2,
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $this->assertSame(3, (int) $product->fresh()->available_quantity);
    }

    public function test_in_house_flow_still_renders_ph_asset_availability_warnings(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $this->makeRentalProduct($organization->id, [
            'name' => 'No Asset Rental Product',
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));
        $response->assertOk();
        $response->assertSee('No rental assets available');
    }

    public function test_vendor_supplied_sale_skips_sale_unit_reduction_but_in_house_sale_does_not(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Sales Creator', [
            'customers' => ['read'],
            'sales' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $vendor = $this->makeVendor($organization->id);
        $vendorProduct = $this->makeSaleProduct($organization->id, [
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);
        $inHouseProduct = $this->makeSaleProduct($organization->id, [
            'name' => 'In-house Sale Product',
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($customer->id, $vendorProduct->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_responsibility' => 'vendor_delivery',
            ]))
            ->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

        $this->assertSame(5, (int) $vendorProduct->fresh()->available_quantity);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($customer->id, $inHouseProduct->id))
            ->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

        $this->assertSame(3, (int) $inHouseProduct->fresh()->available_quantity);
    }

    public function test_vendor_costs_are_only_visible_to_authorized_users_and_report_export_filters(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Admin', [
            'vendors' => ['read'],
            '__special' => ['vendor_reports.view'],
        ]);
        $finance = $this->userWithRole($organization, 'Admin Operations', [
            'vendors' => ['read'],
            '__special' => ['vendor_reports.view', 'vendor_reports.export', 'vendor_costs.view', 'vendor_costs.update'],
        ]);
        $vendor = $this->makeVendor($organization->id);
        $customer = $this->makeCustomer($organization->id, [
            'city' => 'Bengaluru',
        ]);
        $product = $this->makeSaleProduct($organization->id, [
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
            'product_id' => $product->id,
            'vendor_id' => $vendor->id,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'quantity' => 3,
            'unit_price' => 1500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => '2026-05-20',
            'sale_amount' => 4500,
            'payment_status' => 'pending',
        ]);

        VendorOrderDetail::create([
            'organization_id' => $organization->id,
            'vendor_id' => $vendor->id,
            'sale_id' => $sale->id,
            'order_type' => VendorOrderDetail::ORDER_TYPE_SALE,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'vendor_order_status' => 'confirmed',
            'procurement_cost' => 2200,
            'vendor_delivery_cost' => 200,
            'vendor_payment_status' => 'pending',
        ]);

        $beforeCount = VendorOrderDetail::count();

        $viewerResponse = $this->actingAs($viewer)->get(route('vendor-orders.index'));
        $viewerResponse->assertOk();
        $viewerResponse->assertDontSee('Gross Margin');
        $viewerResponse->assertSee('Cost Visibility');

        $financeResponse = $this->actingAs($finance)->get(route('vendor-orders.index', [
            'vendor_id' => $vendor->id,
            'from_date' => '2026-05-01',
            'to_date' => '2026-05-31',
        ]));
        $financeResponse->assertOk();
        $financeResponse->assertSee('Gross Margin');
        $financeResponse->assertSee($vendor->name);

        $exportUrl = route('vendor-orders.export.csv', ['vendor_id' => $vendor->id]);
        $this->actingAs($finance)->getJson($exportUrl)->assertForbidden();
        $exportResponse = $this->actingAs(TestData::user($organization))->get($exportUrl);
        $exportResponse->assertOk();
        $this->assertStringContainsString($vendor->name, $exportResponse->streamedContent());
        $this->assertSame($beforeCount, VendorOrderDetail::count());
    }

    public function test_delivery_taskboard_shows_vendor_responsibility_for_vendor_supplied_orders(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Delivery Reader', [
            'deliveries' => ['read'],
            'rentals' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);
        $vendor = $this->makeVendor($organization->id);
        $rental = \App\Models\Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'vendor_id' => $vendor->id,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'pickup_responsibility' => 'vendor_pickup',
            'quantity' => 1,
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-20',
            'rental_amount' => 1200,
            'status' => 'active',
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get(route('deliveries.index'))
            ->assertOk()
            ->assertSee('Vendor Delivery');
    }

    public function test_vendor_supplied_delivery_does_not_create_ph_delivery_task_but_internal_delivery_does(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $vendor = $this->makeVendor($organization->id);

        $vendorProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Vendor Queue Product',
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $vendorProduct->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_responsibility' => 'vendor_delivery',
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $vendorRental = \App\Models\Rental::query()->latest('id')->firstOrFail();
        $this->assertDatabaseMissing('deliveries', [
            'organization_id' => $organization->id,
            'rental_id' => $vendorRental->id,
            'type' => 'delivery',
        ]);

        $admin = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->get(route('rentals.show', $vendorRental))
            ->assertOk()->assertSee('Who received the customer')->assertSee($vendor->name);
        $this->post(route('rentals.recordPayment', $vendorRental), [
            'payment_date' => today()->toDateString(), 'amount' => 100, 'payment_method' => 'cash',
            'collection_recipient' => 'vendor',
            'payment_submission_token' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('rentals.show', $vendorRental));
        $this->assertDatabaseHas('payments', [
            'rental_id' => $vendorRental->id, 'amount' => 100,
            'collection_recipient' => 'vendor', 'collection_vendor_key' => 'vendor:'.$vendor->id,
        ]);
        $this->get(route('reports.vendor-collections'))->assertOk()
            ->assertSee($vendor->name)->assertSee('₹100.00');

        $internalProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'PH Queue Product',
            'available_quantity' => 2,
            'total_quantity' => 2,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $internalProduct->id, [
                'delivery_responsibility' => 'ph_internal_delivery',
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $internalRental = \App\Models\Rental::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('deliveries', [
            'organization_id' => $organization->id,
            'rental_id' => $internalRental->id,
            'type' => 'delivery',
            'status' => 'pending',
        ]);
    }

    public function test_customer_pickup_bypasses_ph_delivery_task(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $vendor = $this->makeVendor($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_responsibility' => 'customer_pickup',
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = \App\Models\Rental::query()->latest('id')->firstOrFail();
        $this->assertDatabaseMissing('deliveries', [
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
        ]);
    }

    public function test_vendor_report_detects_negative_margin_and_vendor_payment_reconciliation(): void
    {
        $organization = TestData::organization();
        $finance = $this->userWithRole($organization, 'Admin Operations', [
            'vendors' => ['read'],
            '__special' => ['vendor_reports.view', 'vendor_reports.export', 'vendor_costs.view', 'vendor_costs.update'],
        ]);
        $vendor = $this->makeVendor($organization->id);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeSaleProduct($organization->id);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
            'product_id' => $product->id,
            'vendor_id' => $vendor->id,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'quantity' => 1,
            'unit_price' => 1500,
            'sale_date' => '2026-05-21',
            'sale_amount' => 1000,
            'payment_status' => 'paid',
        ]);

        VendorOrderDetail::create([
            'organization_id' => $organization->id,
            'vendor_id' => $vendor->id,
            'sale_id' => $sale->id,
            'order_type' => VendorOrderDetail::ORDER_TYPE_SALE,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'vendor_order_status' => 'completed',
            'procurement_cost' => 900,
            'vendor_delivery_cost' => 250,
            'vendor_payment_status' => 'pending',
        ]);

        $response = $this->actingAs($finance)->get(route('vendor-orders.index', [
            'derived_state' => 'negative_margin',
        ]));

        $response->assertOk();
        $response->assertSee('Negative Margin');
        $response->assertSee('Unpaid Vendor');
        $response->assertSee('Vendor Balance Payable');
        $response->assertSee('Customer Paid Vendor Unpaid');
        $response->assertSee('Vendor Invoice Missing');
    }

    public function test_fulfilment_source_change_after_operational_start_requires_superadmin(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Editor', [
            'customers' => ['read'],
            'rentals' => ['read', 'create', 'update'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $customer = $this->makeCustomer($organization->id);
        $vendor = $this->makeVendor($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 2,
            'total_quantity' => 2,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = \App\Models\Rental::query()->latest('id')->firstOrFail();

        $this->actingAs($user)
            ->put(route('rentals.update', $rental), $this->rentalPayload($customer->id, $product->id, [
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_responsibility' => 'vendor_delivery',
            ]))
            ->assertSessionHasErrors('fulfilment_source');
    }

    public function test_rental_form_uses_city_first_delivery_assignment_flow(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
        ]);

        $this->makeCity($organization->id, 'Bengaluru');

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<label for="city_id">Service city</label>',
            '<select name="city_id" id="city_id">',
            '<label for="fulfilment_source">Fulfilment source</label>',
            '<select name="fulfilment_source" id="fulfilment_source">',
            '<label for="delivery_assignment_type">Delivery method</label>',
            '<select name="delivery_assignment_type" id="delivery_assignment_type">',
        ], false);
        $response->assertDontSee('Pickup Responsibility', false);
    }

    public function test_in_house_rental_form_bridges_searchable_product_selection_into_asset_loading_and_available_assets_endpoint_supports_both_products(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
            'assets' => ['read'],
        ]);
        $city = $this->makeCity($organization->id, 'Bengaluru');
        $warehouse = $this->makeWarehouse($organization->id, $city->id, 'Bengaluru Main');
        $product = $this->makeRentalProduct($organization->id, [
            'name' => 'Dual Mode Oxygen Cylinder',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        $asset = \App\Models\Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Oxygen Cylinder 10 LPM',
            'serial_number' => 'O2-10L-001',
            'asset_stage' => \App\Models\Asset::STAGE_RENTAL_STOCK,
            'asset_status' => \App\Models\Asset::STATUS_AVAILABLE,
            'condition_status' => 'good',
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee("productSelect.addEventListener('searchable-select:changed', handlePrimaryProductChange);", false);
        $response->assertSee("source: 'fulfilment-refresh'", false);

        $assetsResponse = $this->actingAs($user)->getJson(route('rentals.available-assets', [
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
        ]));

        $assetsResponse->assertOk();
        $this->assertSame([$asset->id], collect($assetsResponse->json('data'))->pluck('id')->all());
    }

    public function test_additional_rental_product_assets_use_same_in_house_picker_flow(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
            'assets' => ['read'],
        ]);
        $city = $this->makeCity($organization->id, 'Bengaluru');
        $warehouse = $this->makeWarehouse($organization->id, $city->id, 'Bengaluru Main');
        $customer = $this->makeCustomer($organization->id);
        $primaryProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Primary Rental Product',
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);
        $additionalProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Additional Tracked Rental Product',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        $asset = \App\Models\Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $additionalProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Additional Rental Asset',
            'serial_number' => 'ADD-TRACKED-001',
            'asset_stage' => \App\Models\Asset::STAGE_RENTAL_STOCK,
            'asset_status' => \App\Models\Asset::STATUS_AVAILABLE,
            'condition_status' => 'good',
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee('data-rental-asset-autoselect="${index}"', false);
        $response->assertSee('productSelectEl.addEventListener(\'searchable-select:changed\', handleRentalItemProductChange);', false);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $primaryProduct->id, [
                'city_id' => $city->id,
                'dispatch_warehouse_id' => $warehouse->id,
                'quantity' => 1,
                'rental_amount' => 1200,
                'rental_items' => [
                    [
                        'product_id' => $additionalProduct->id,
                        'quantity' => 1,
                        'unit_rental_amount' => 450,
                        'asset_ids' => [$asset->id],
                    ],
                ],
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $this->assertDatabaseHas('rental_items', [
            'organization_id' => $organization->id,
            'product_id' => $additionalProduct->id,
        ]);
    }

    public function test_vendor_supplied_additional_rental_product_does_not_require_ph_assets(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $city = $this->makeCity($organization->id, 'Bengaluru');
        $customer = $this->makeCustomer($organization->id);
        $vendor = $this->makeVendor($organization->id, [
            'city_id' => $city->id,
            'city' => 'Bengaluru',
        ]);
        $primaryProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Vendor Supplied Primary Product',
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);
        $additionalProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Vendor Supplied Additional Product',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee('PH asset selection is not required for vendor supplied lines.', false);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $primaryProduct->id, [
                'city_id' => $city->id,
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $vendor->id,
                'delivery_assignment_type' => 'vendor',
                'delivery_responsibility' => 'vendor_delivery',
                'pickup_responsibility' => 'vendor_pickup',
                'quantity' => 1,
                'rental_items' => [
                    [
                        'product_id' => $additionalProduct->id,
                        'quantity' => 1,
                        'unit_rental_amount' => 300,
                    ],
                ],
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
    }

    public function test_additional_tracked_rental_quantity_two_requires_two_assets(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
            'assets' => ['read'],
        ]);
        $city = $this->makeCity($organization->id, 'Bengaluru');
        $warehouse = $this->makeWarehouse($organization->id, $city->id, 'Bengaluru Main');
        $customer = $this->makeCustomer($organization->id);
        $primaryProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Primary Rental Product Two',
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);
        $additionalProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'Additional Qty Two Product',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        $asset = \App\Models\Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $additionalProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Additional Qty Two Asset',
            'serial_number' => 'ADD-TRACKED-ONLYONE',
            'asset_stage' => \App\Models\Asset::STAGE_RENTAL_STOCK,
            'asset_status' => \App\Models\Asset::STATUS_AVAILABLE,
            'condition_status' => 'good',
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $primaryProduct->id, [
                'city_id' => $city->id,
                'dispatch_warehouse_id' => $warehouse->id,
                'quantity' => 1,
                'rental_items' => [
                    [
                        'product_id' => $additionalProduct->id,
                        'quantity' => 2,
                        'unit_rental_amount' => 900,
                        'asset_ids' => [$asset->id],
                    ],
                ],
            ]))
            ->assertSessionHasErrors('rental_items.0.asset_ids');
    }

    public function test_ph_internal_delivery_dropdown_includes_effective_delivery_users(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $bengaluru = $this->makeCity($organization->id, 'Bengaluru');
        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Executive',
            'slug' => User::ROLE_DELIVERY_EXECUTIVE,
            'description' => 'Delivery Executive',
            'permissions' => Role::normalizePermissions([
                'deliveries' => ['read', 'update'],
                'rentals' => ['read'],
            ]),
            'is_system' => false,
            'is_active' => true,
        ]);
        $deliveryUser = TestData::user($organization, [
            'name' => 'deliverybng',
            'email' => 'deliverybng@primehealers.com',
            'role' => 'staff',
            'role_id' => $deliveryRole->id,
            'city_id' => $bengaluru->id,
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee('deliverybng');
        $response->assertSee('user:' . $deliveryUser->id, false);
    }

    public function test_ph_internal_delivery_dropdown_includes_delivery_staff_slug_variants(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $this->makeCity($organization->id, 'Bengaluru');
        $deliveryStaffRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Staff',
            'slug' => 'delivery_staff',
            'description' => 'Delivery Staff',
            'permissions' => [],
            'is_system' => false,
            'is_active' => true,
        ]);
        $deliveryUser = TestData::user($organization, [
            'name' => 'Deliverybng',
            'email' => 'deliverybng@primehealers.com',
            'role' => 'staff',
            'role_id' => $deliveryStaffRole->id,
        ]);

        $response = $this->actingAs($user)->get(route('rentals.create'));

        $response->assertOk();
        $response->assertSee('Deliverybng');
        $response->assertSee('user:' . $deliveryUser->id, false);
    }

    public function test_vendor_from_another_city_cannot_be_submitted_but_customer_pickup_requires_no_partner(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $bengaluru = $this->makeCity($organization->id, 'Bengaluru');
        $mysuru = $this->makeCity($organization->id, 'Mysuru');
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);
        $mysuruVendor = $this->makeVendor($organization->id, [
            'city_id' => $mysuru->id,
            'city' => 'Mysuru',
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'city_id' => $bengaluru->id,
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $mysuruVendor->id,
                'delivery_assignment_type' => 'vendor',
            ]))
            ->assertSessionHasErrors('vendor_id');

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'city_id' => $bengaluru->id,
                'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
                'vendor_id' => $this->makeVendor($organization->id, [
                    'city_id' => $bengaluru->id,
                    'city' => 'Bengaluru',
                ])->id,
                'delivery_assignment_type' => 'customer_pickup',
                'delivery_staff_id' => '',
            ]))
            ->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
    }

    public function test_warehouse_and_delivery_user_from_another_city_are_rejected(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Rental Creator', [
            'customers' => ['read'],
            'rentals' => ['read', 'create'],
            'invoices' => ['read', 'create'],
            'products' => ['read'],
        ]);
        $bengaluru = $this->makeCity($organization->id, 'Bengaluru');
        $mysuru = $this->makeCity($organization->id, 'Mysuru');
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeRentalProduct($organization->id, [
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);
        $mysuruWarehouse = $this->makeWarehouse($organization->id, $mysuru->id, 'Mysuru Warehouse');
        $bengaluruWarehouse = $this->makeWarehouse($organization->id, $bengaluru->id, 'Bengaluru Warehouse');
        $mysuruDeliveryUser = TestData::user($organization, [
            'role' => 'delivery',
            'city_id' => $mysuru->id,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'city_id' => $bengaluru->id,
                'dispatch_warehouse_id' => $mysuruWarehouse->id,
                'delivery_assignment_type' => 'customer_pickup',
            ]))
            ->assertSessionHasErrors('dispatch_warehouse_id');

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $product->id, [
                'city_id' => $bengaluru->id,
                'dispatch_warehouse_id' => $bengaluruWarehouse->id,
                'delivery_assignment_type' => 'ph_internal',
                'delivery_staff_id' => 'user:' . $mysuruDeliveryUser->id,
            ]))
            ->assertSessionHasErrors('delivery_staff_id');
    }

    private function userWithRole($organization, string $name, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => str($name)->slug('_'),
            'description' => $name,
            'permissions' => Role::normalizePermissions($permissions),
            'is_system' => false,
            'is_active' => true,
        ]);

        return TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }

    private function makeCustomer(int $organizationId, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Vendor Fulfilment Customer',
            'customer_type' => 'Individual',
            'phone' => '9000000011',
            'city' => 'Bengaluru',
        ], $attributes));
    }

    private function makeVendor(int $organizationId, array $attributes = []): Vendor
    {
        return Vendor::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Vendor Alpha',
            'contact_person' => 'Vendor Ops',
            'phone' => '9888888888',
            'vendor_type' => 'Procurement Partner',
            'is_active' => true,
        ], $attributes));
    }

    private function makeRentalProduct(int $organizationId, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Vendor Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'is_sellable' => false,
            'is_rentable' => true,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'sale_price' => 0,
            'price_per_day' => 250,
        ], $attributes));
    }

    private function makeSaleProduct(int $organizationId, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Vendor Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'is_sellable' => true,
            'is_rentable' => false,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'sale_price' => 1500,
            'price_per_day' => 0,
        ], $attributes));
    }

    private function rentalPayload(int $customerId, int $productId, array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'direct_customer',
            'customer_id' => $customerId,
            'customer_name' => 'Vendor Fulfilment Customer',
            'phone' => '9000000011',
            'product_id' => $productId,
            'quantity' => 2,
            'start_date' => '2026-05-20',
            'end_date' => '2026-05-30',
            'rental_amount' => 2000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            'delivery_responsibility' => 'ph_internal_delivery',
            'pickup_responsibility' => 'ph_internal_pickup',
        ], $overrides);
    }

    private function makeCity(int $organizationId, string $name): City
    {
        return City::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);
    }

    private function makeWarehouse(int $organizationId, int $cityId, string $name): Warehouse
    {
        return Warehouse::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'code' => str($name)->slug('-'),
            'city_id' => $cityId,
            'city' => City::query()->findOrFail($cityId)->name,
            'state' => 'Karnataka',
            'is_active' => true,
        ]);
    }

    private function salePayload(int $customerId, int $productId, array $overrides = []): array
    {
        return array_merge([
            'customer_type' => 'direct_customer',
            'customer_id' => $customerId,
            'sale_date' => '2026-05-20',
            'payment_status' => 'pending',
            'shipping_charges' => 0,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            'delivery_responsibility' => 'ph_internal_delivery',
            'sale_items' => [
                [
                    'product_id' => $productId,
                    'quantity' => 2,
                    'unit_price' => 1500,
                    'discount_amount' => 0,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                    'tax_type' => 'cgst_sgst',
                    'notes' => null,
                ],
            ],
        ], $overrides);
    }
}
