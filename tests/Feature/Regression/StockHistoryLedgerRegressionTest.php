<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Deliveries\DeliveryWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestData;
use Tests\TestCase;

class StockHistoryLedgerRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_stock_history(): void
    {
        $organization = TestData::organization();
        $superAdmin = TestData::user($organization, [
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $product = $this->makeUntrackedProduct($organization->id);
        StockMovement::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 3,
            'to_status' => 'available',
            'performed_by_user_id' => $superAdmin->id,
            'movement_at' => now(),
            'notes' => 'Seed opening stock.',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertSee('Stock History')
            ->assertSee($product->name);
    }

    public function test_user_with_general_stock_history_permission_can_view_export_and_see_sidebar_link(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Inventory Ledger Viewer', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view', 'stock_history.export'],
        ]);

        $product = $this->makeUntrackedProduct($organization->id);
        StockMovement::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 2,
            'to_status' => 'available',
            'performed_by_user_id' => $user->id,
            'movement_at' => now(),
            'notes' => 'Seed ledger row.',
        ]);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('stock-history.index'), false);

        $this->actingAs($user)
            ->get(route('stock-history.index'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee(route('stock-history.export.csv'), false);

        $this->actingAs($user)
            ->get(route('stock-history.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_unassigned_user_cannot_view_or_export_stock_history_and_menu_stays_hidden(): void
    {
        $organization = TestData::organization();
        $user = $this->userWithRole($organization, 'Product Reader', [
            'products' => ['read'],
        ]);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertDontSee(route('stock-history.index'), false);

        $this->actingAs($user)
            ->get(route('stock-history.index'))
            ->assertRedirect($user->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($user)
            ->get(route('stock-history.export.csv'))
            ->assertRedirect($user->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');
    }

    public function test_product_and_asset_scoped_permissions_allow_filtered_history_only(): void
    {
        $organization = TestData::organization();
        $warehouse = $this->makeWarehouse($organization->id);
        $product = $this->makeUntrackedProduct($organization->id);
        $asset = $this->makeRentalAsset($organization->id, $product->id, $warehouse->id, 'ASSET-STOCK-HISTORY');

        StockMovement::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 4,
            'to_status' => 'available',
            'performed_by_user_id' => null,
            'movement_at' => now(),
            'notes' => 'Product history seed.',
        ]);
        StockMovement::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'asset_id' => $asset->id,
            'movement_type' => StockMovement::TYPE_ADD_STOCK,
            'quantity' => 1,
            'to_status' => Asset::STATUS_AVAILABLE,
            'to_warehouse_id' => $warehouse->id,
            'performed_by_user_id' => null,
            'movement_at' => now(),
            'notes' => 'Asset history seed.',
        ]);

        $productHistoryUser = $this->userWithRole($organization, 'Product Stock Viewer', [
            'products' => ['read'],
            '__special' => ['stock_history.product'],
        ]);
        $assetHistoryUser = $this->userWithRole($organization, 'Asset Stock Viewer', [
            'assets' => ['read'],
            '__special' => ['stock_history.asset'],
        ]);

        $this->actingAs($productHistoryUser)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee(route('stock-history.index', ['product_id' => $product->id]), false);

        $this->actingAs($productHistoryUser)
            ->get(route('stock-history.index', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee($product->name);

        $this->actingAs($productHistoryUser)
            ->get(route('stock-history.index'))
            ->assertRedirect($productHistoryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access stock history.');

        $this->actingAs($assetHistoryUser)
            ->get(route('assets.show', $asset))
            ->assertOk()
            ->assertSee(route('stock-history.index', ['asset_id' => $asset->id]), false);

        $this->actingAs($assetHistoryUser)
            ->get(route('stock-history.index', ['asset_id' => $asset->id]))
            ->assertOk()
            ->assertSee($asset->serial_number);

        $this->actingAs($assetHistoryUser)
            ->get(route('stock-history.index'))
            ->assertRedirect($assetHistoryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access stock history.');
    }

    public function test_sale_creation_logs_stock_movement_even_when_actor_cannot_view_stock_history(): void
    {
        $organization = TestData::organization();
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeUntrackedProduct($organization->id, [
            'available_quantity' => 3,
            'total_quantity' => 3,
            'sale_price' => 1500,
        ]);
        $salesUser = $this->userWithRole($organization, 'Sales Creator', [
            'customers' => ['read'],
            'products' => ['read'],
            'sales' => ['read', 'create', 'update'],
            'invoices' => ['read', 'create', 'update'],
            'payments' => ['read'],
        ]);

        $this->assertFalse($salesUser->hasPermission('stock_history.view'));

        $response = $this->actingAs($salesUser)->post(route('sales.store'), [
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
            'notes' => 'Stock history regression sale',
        ]);

        $response->assertRedirect(route('sales.index', ['sort_by' => 'latest']));

        $movement = StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('product_id', $product->id)
            ->where('movement_type', StockMovement::TYPE_SALE)
            ->latest('id')
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame($salesUser->id, $movement->performed_by_user_id);
        $this->assertSame('available', $movement->from_status);
        $this->assertSame('sold', $movement->to_status);
    }

    public function test_asset_transfer_and_return_verification_create_stock_movements(): void
    {
        $organization = TestData::organization();
        $warehouseA = $this->makeWarehouse($organization->id, ['name' => 'Warehouse A', 'code' => 'WA']);
        $warehouseB = $this->makeWarehouse($organization->id, ['name' => 'Warehouse B', 'code' => 'WB']);
        $product = $this->makeTrackedRentalProduct($organization->id);
        $asset = $this->makeRentalAsset($organization->id, $product->id, $warehouseA->id, 'VERIFY-MOVE-001');
        $assetManager = $this->userWithRole($organization, 'Asset Manager', [
            'assets' => ['read', 'update'],
            'products' => ['read'],
            'warehouses' => ['read'],
        ]);

        $this->actingAs($assetManager)
            ->post(route('assets.transfer.store', $asset), [
                'current_warehouse_id' => $warehouseA->id,
                'warehouse_id' => $warehouseB->id,
                'remarks' => 'Move for stock history test',
            ])
            ->assertRedirect(route('assets.show', $asset));

        $transferMovement = StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('asset_id', $asset->id)
            ->where('movement_type', StockMovement::TYPE_WAREHOUSE_TRANSFER)
            ->latest('id')
            ->first();

        $this->assertNotNull($transferMovement);
        $this->assertSame($warehouseA->id, $transferMovement->from_warehouse_id);
        $this->assertSame($warehouseB->id, $transferMovement->to_warehouse_id);
        $this->assertSame($assetManager->id, $transferMovement->performed_by_user_id);

        $asset->refresh();
        $asset->update(['asset_status' => Asset::STATUS_AWAITING_VERIFICATION]);

        $this->actingAs($assetManager)
            ->put(route('assets.verify-return.store', $asset), [
                'serial_number' => $asset->serial_number,
                'verification_outcome' => 'good',
                'remarks' => 'Ready for reuse',
            ])
            ->assertRedirect(route('assets.pending-verification'));

        $verificationMovement = StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('asset_id', $asset->id)
            ->where('movement_type', StockMovement::TYPE_RETURN_VERIFICATION)
            ->latest('id')
            ->first();

        $this->assertNotNull($verificationMovement);
        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $verificationMovement->from_status);
        $this->assertSame(Asset::STATUS_AVAILABLE, $verificationMovement->to_status);
    }

    public function test_rental_delivery_and_pickup_return_create_stock_movements(): void
    {
        $organization = TestData::organization();
        $warehouse = $this->makeWarehouse($organization->id);
        $customer = $this->makeCustomer($organization->id);
        $product = $this->makeTrackedRentalProduct($organization->id);
        $asset = $this->makeRentalAsset($organization->id, $product->id, $warehouse->id, 'RENTAL-LEDGER-001');
        $actor = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $service = app(DeliveryWorkflowService::class);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rental_amount' => 5000,
            'dispatch_warehouse_id' => $warehouse->id,
            'status' => 'active',
        ]);

        $rentalItem = RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 5000,
            'line_total' => 5000,
            'asset_ids' => [$asset->id],
        ]);

        $this->actingAs($actor);
        $service->finalizeDeliveryCompletionForRental($organization->id, $rental->fresh());

        $deliveryMovement = StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('asset_id', $asset->id)
            ->where('movement_type', StockMovement::TYPE_DELIVERY)
            ->latest('id')
            ->first();

        $this->assertNotNull($deliveryMovement);
        $this->assertSame('reserved', $deliveryMovement->from_status);
        $this->assertSame('rented', $deliveryMovement->to_status);
        $this->assertSame($actor->id, $deliveryMovement->performed_by_user_id);

        $rentalItem->refresh();
        $this->assertSame(1, (int) $rentalItem->delivered_quantity);

        $service->finalizePickupCompletionForRental($organization->id, $rental->fresh(), now()->toDateTimeString());

        $pickupMovement = StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('asset_id', $asset->id)
            ->where('movement_type', StockMovement::TYPE_PICKUP_RETURN)
            ->latest('id')
            ->first();

        $this->assertNotNull($pickupMovement);
        $this->assertSame('rented', $pickupMovement->from_status);
        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $pickupMovement->to_status);
        $this->assertSame($actor->id, $pickupMovement->performed_by_user_id);
    }

    public function test_opening_balance_backfill_command_is_idempotent(): void
    {
        $organization = TestData::organization();
        $warehouse = $this->makeWarehouse($organization->id);
        $product = $this->makeUntrackedProduct($organization->id, [
            'available_quantity' => 6,
            'total_quantity' => 6,
        ]);
        $asset = $this->makeRentalAsset($organization->id, $product->id, $warehouse->id, 'OPENING-BALANCE-001');

        Artisan::call('phos:backfill-stock-history-opening-balances');

        $this->assertSame(1, StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('product_id', $product->id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->where('notes', 'Backfilled current untracked stock as opening balance.')
            ->count());
        $this->assertSame(1, StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('asset_id', $asset->id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->where('notes', 'Backfilled current asset position as opening balance.')
            ->count());

        Artisan::call('phos:backfill-stock-history-opening-balances');

        $this->assertSame(1, StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('product_id', $product->id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->where('notes', 'Backfilled current untracked stock as opening balance.')
            ->count());
        $this->assertSame(1, StockMovement::query()
            ->where('organization_id', $organization->id)
            ->where('asset_id', $asset->id)
            ->where('movement_type', StockMovement::TYPE_OPENING)
            ->where('notes', 'Backfilled current asset position as opening balance.')
            ->count());
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

    private function makeWarehouse(int $organizationId, array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ], $attributes));
    }

    private function makeCustomer(int $organizationId): Customer
    {
        return Customer::create([
            'organization_id' => $organizationId,
            'name' => 'Stock History Customer',
            'customer_type' => 'Individual',
            'phone' => '9000000001',
        ]);
    }

    private function makeUntrackedProduct(int $organizationId, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Stock History Product',
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

    private function makeTrackedRentalProduct(int $organizationId, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Tracked Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'is_sellable' => false,
            'is_rentable' => true,
            'available_quantity' => 0,
            'total_quantity' => 0,
            'sale_price' => 0,
            'price_per_day' => 500,
        ], $attributes));
    }

    private function makeRentalAsset(int $organizationId, int $productId, int $warehouseId, string $serialNumber): Asset
    {
        return Asset::create([
            'organization_id' => $organizationId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'asset_name' => 'Stock History Asset',
            'serial_number' => $serialNumber,
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);
    }
}
