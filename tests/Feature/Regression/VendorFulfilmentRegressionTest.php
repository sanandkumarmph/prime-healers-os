<?php

namespace Tests\Feature\Regression;

use App\Models\City;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
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
            ->assertRedirect(route('rentals.index'));

        $rental = \App\Models\Rental::query()->latest('id')->first();
        $this->assertNotNull($rental);
        $this->assertTrue($rental->isVendorSupplied());
        $this->assertSame(0, (int) $product->fresh()->available_quantity);
        $this->assertSame($vendor->id, $rental->vendorOrderDetail?->vendor_id);
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
            ->assertRedirect(route('rentals.index'));

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
        $response->assertSee('Vendor supplied rentals use Product Master only as a catalogue. Vendor stock is not reserved in PHOS.');
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
            ->assertRedirect(route('rentals.index'));

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
            ->assertRedirect(route('sales.index'));

        $this->assertSame(5, (int) $vendorProduct->fresh()->available_quantity);

        $this->actingAs($user)
            ->post(route('sales.store'), $this->salePayload($customer->id, $inHouseProduct->id))
            ->assertRedirect(route('sales.index'));

        $this->assertSame(3, (int) $inHouseProduct->fresh()->available_quantity);
    }

    public function test_vendor_costs_are_only_visible_to_authorized_users_and_report_export_filters(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Vendor Report Viewer', [
            'vendors' => ['read'],
            '__special' => ['vendor_reports.view'],
        ]);
        $finance = $this->userWithRole($organization, 'Vendor Finance', [
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

        $exportResponse = $this->actingAs($finance)->get(route('vendor-orders.export.csv', [
            'vendor_id' => $vendor->id,
        ]));
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
            ->assertRedirect(route('rentals.index'));

        $vendorRental = \App\Models\Rental::query()->latest('id')->firstOrFail();
        $this->assertDatabaseMissing('deliveries', [
            'organization_id' => $organization->id,
            'rental_id' => $vendorRental->id,
            'type' => 'delivery',
        ]);

        $internalProduct = $this->makeRentalProduct($organization->id, [
            'name' => 'PH Queue Product',
            'available_quantity' => 2,
            'total_quantity' => 2,
        ]);

        $this->actingAs($user)
            ->post(route('rentals.store'), $this->rentalPayload($customer->id, $internalProduct->id, [
                'delivery_responsibility' => 'ph_internal_delivery',
            ]))
            ->assertRedirect(route('rentals.index'));

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
            ->assertRedirect(route('rentals.index'));

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
        $finance = $this->userWithRole($organization, 'Vendor Finance', [
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
            ->assertRedirect(route('rentals.index'));

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
        $response->assertSee('Step 1 · Fulfilment Source', false);
        $response->assertSee('Step 2 · City', false);
        $response->assertSee('Step 6 · Delivery Assignment', false);
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
            ->assertRedirect(route('rentals.index'));
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
