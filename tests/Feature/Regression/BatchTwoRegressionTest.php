<?php

namespace Tests\Feature\Regression;

use App\Http\Controllers\RentalController;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\TestData;
use Tests\TestCase;

class BatchTwoRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_voiding_tracked_sale_restores_sale_asset_without_overstating_product_quantity(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Void Customer',
            'phone' => '9000000001',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Tracked Sale Warehouse',
            'code' => 'TSW',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Voidable Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'sale_price' => 1200,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Voidable Unit',
            'serial_number' => 'VOID-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1200,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 1200,
            'payment_status' => 'pending',
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame(Asset::STATUS_SOLD, $asset->fresh()->asset_status);
        $this->assertSame(0, $product->fresh()->available_quantity);
        $this->assertSame(1, $product->fresh()->total_quantity);

        $this->put(route('sales.void', $sale))
            ->assertRedirect(route('sales.show', $sale));

        $product->refresh();

        $this->assertSame(Asset::STATUS_AVAILABLE_FOR_SALE, $asset->fresh()->asset_status);
        $this->assertSame(1, $product->available_quantity);
        $this->assertSame(1, $product->total_quantity);
        $this->assertSame(Product::STOCK_MODE_TRACKED_SALE, $product->stock_mode);
        $this->assertSame('void', $sale->fresh()->payment_status);
    }

    public function test_deleting_tracked_sale_restores_sale_asset_without_overstating_product_quantity(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Delete Customer',
            'phone' => '9000000002',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Delete Warehouse',
            'code' => 'DEL',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Delete Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'sale_price' => 1500,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Delete Unit',
            'serial_number' => 'DELETE-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 1500,
            'payment_status' => 'pending',
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'));

        $product->refresh();

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
        $this->assertSame(Asset::STATUS_AVAILABLE_FOR_SALE, $asset->fresh()->asset_status);
        $this->assertSame(1, $product->available_quantity);
        $this->assertSame(1, $product->total_quantity);
        $this->assertSame(Product::STOCK_MODE_TRACKED_SALE, $product->stock_mode);
    }

    public function test_return_verification_good_outcome_restores_asset_to_available(): void
    {
        $this->assertReturnVerificationOutcome('good', 'good', Asset::STATUS_AVAILABLE);
    }

    public function test_return_verification_repair_outcome_moves_asset_to_maintenance(): void
    {
        $this->assertReturnVerificationOutcome('repair', 'repair', Asset::STATUS_MAINTENANCE);
    }

    public function test_return_verification_scrap_outcome_retires_asset(): void
    {
        $this->assertReturnVerificationOutcome('scrap', 'inactive', Asset::STATUS_RETIRED);
    }

    public function test_quick_customer_create_reuses_existing_customer_for_same_organization_and_phone(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $existing = Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Existing Phone Customer',
            'first_name' => 'Existing',
            'phone' => PhoneNumber::normalize('9111111111', '+91'),
            'email' => 'existing-phone@example.com',
        ]);

        $response = $this->postJson(route('customers.quick-store'), [
            'customer_type' => 'Individual',
            'first_name' => 'Fresh Name',
            'phone_country_code' => '+91',
            'phone' => '9111111111',
            'email' => 'new-email@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('customer.id', $existing->id);

        $this->assertSame(1, Customer::query()->where('organization_id', $organization->id)->count());
    }

    public function test_quick_customer_create_reuses_existing_customer_for_same_organization_and_email_when_phone_missing(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $existing = Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Existing Email Customer',
            'first_name' => 'Existing',
            'phone' => null,
            'email' => 'existing-email@example.com',
        ]);

        $response = $this->postJson(route('customers.quick-store'), [
            'customer_type' => 'Individual',
            'first_name' => 'Another Name',
            'email' => 'existing-email@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('customer.id', $existing->id);

        $this->assertSame(1, Customer::query()->where('organization_id', $organization->id)->count());
    }

    public function test_quick_customer_create_allows_same_phone_or_email_in_different_organizations(): void
    {
        $organizationA = TestData::organization(['name' => 'Org A']);
        $organizationB = TestData::organization(['name' => 'Org B']);

        Customer::create([
            'organization_id' => $organizationA->id,
            'customer_type' => 'Individual',
            'name' => 'Org A Customer',
            'first_name' => 'OrgA',
            'phone' => PhoneNumber::normalize('9222222222', '+91'),
            'email' => 'shared@example.com',
        ]);

        $this->actingAs(TestData::user($organizationB, ['email' => 'orgb-admin@example.com']));

        $response = $this->postJson(route('customers.quick-store'), [
            'customer_type' => 'Individual',
            'first_name' => 'OrgB',
            'phone_country_code' => '+91',
            'phone' => '9222222222',
            'email' => 'shared@example.com',
        ]);

        $response->assertOk();

        $this->assertSame(1, Customer::query()->where('organization_id', $organizationA->id)->count());
        $this->assertSame(1, Customer::query()->where('organization_id', $organizationB->id)->count());
        $this->assertNotSame(
            Customer::query()->where('organization_id', $organizationA->id)->value('id'),
            Customer::query()->where('organization_id', $organizationB->id)->value('id')
        );
    }

    public function test_dashboard_pending_receivables_include_only_open_invoice_balances(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-UNPAID',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 200,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 200,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 200,
            'paid_amount' => 0,
            'balance_amount' => 200,
        ]);

        Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-PARTIAL',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'partial',
            'payment_status' => 'partial',
            'subtotal' => 300,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 300,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 300,
            'paid_amount' => 150,
            'balance_amount' => 150,
        ]);

        Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-PAID',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'paid',
            'payment_status' => 'paid',
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
            'paid_amount' => 500,
            'balance_amount' => 0,
        ]);

        $controller = app(RentalController::class);
        $method = new \ReflectionMethod($controller, 'dashboardPendingReceivablesSnapshot');
        $method->setAccessible(true);

        $snapshot = $method->invoke(
            $controller,
            new Collection(),
            Invoice::query()->where('organization_id', $organization->id),
            Sale::query()->where('organization_id', $organization->id),
            Carbon::now()->startOfDay()
        );

        $this->assertSame(2, $snapshot['pendingReceivableCount']);
        $this->assertSame(350.0, (float) $snapshot['pendingReceivableAmount']);
        $this->assertSame(1, $snapshot['pendingReceivableOverdueCount']);
    }

    public function test_rental_create_dropdown_marks_sale_only_products_as_not_rentally_available(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Warehouse',
            'code' => 'RWH',
            'is_active' => true,
        ]);

        $saleOnlyProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'sale_price' => 1500,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $saleOnlyProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit 1',
            'serial_number' => 'SALE-ONLY-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $saleOnlyProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit 2',
            'serial_number' => 'SALE-ONLY-002',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        $response = $this->get(route('rentals.create'));
        $response->assertOk();

        $product = collect($response->viewData('products'))->firstWhere('id', $saleOnlyProduct->id);

        $this->assertSame('sale_only', $product->rental_availability_status);
        $this->assertSame(0, $product->rental_available_quantity);
        $this->assertSame('Sale only', $product->rental_dropdown_label);
    }

    public function test_rental_create_dropdown_counts_only_rental_assets_for_tracked_both_products(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Both Warehouse',
            'code' => 'BTH',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'sale_price' => 1200,
            'rental_price' => 500,
            'price_per_day' => 500,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit',
            'serial_number' => 'BOTH-SALE-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit A',
            'serial_number' => 'BOTH-RENT-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit B',
            'serial_number' => 'BOTH-RENT-002',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit Rented',
            'serial_number' => 'BOTH-RENT-003',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_RENTED,
        ]);

        $response = $this->get(route('rentals.create'));
        $response->assertOk();

        $viewProduct = collect($response->viewData('products'))->firstWhere('id', $product->id);

        $this->assertSame('rental_available', $viewProduct->rental_availability_status);
        $this->assertSame(2, $viewProduct->rental_available_quantity);
        $this->assertSame('Rental Available 2', $viewProduct->rental_dropdown_label);
    }

    private function assertReturnVerificationOutcome(string $outcome, string $expectedCondition, string $expectedStatus): void
    {
        $organization = TestData::organization(['name' => 'Verification Org ' . $outcome]);
        $this->actingAs(TestData::user($organization, ['email' => $outcome . '@example.com']));

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Verification Warehouse',
            'code' => strtoupper(substr($outcome, 0, 3)),
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Verification Product ' . $outcome,
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'sale_price' => 0,
            'rental_price' => 400,
            'price_per_day' => 400,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Verification Asset',
            'serial_number' => 'VERIFY-' . strtoupper($outcome),
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);

        $this->put(route('assets.verify-return.store', $asset), [
            'serial_number' => $asset->serial_number,
            'verification_outcome' => $outcome,
            'remarks' => 'Regression verification ' . $outcome,
        ])->assertRedirect(route('assets.pending-verification'));

        $asset->refresh();

        $this->assertSame($expectedCondition, $asset->condition_status);
        $this->assertSame($expectedStatus, $asset->asset_status);
    }
}
