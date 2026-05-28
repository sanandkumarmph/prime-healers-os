<?php

namespace Tests\Feature\Regression;

use App\Models\Product;
use App\Models\Organization;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TestData;
use Tests\TestCase;

class StockHistoryImmutabilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movement_policy_forbids_update_and_delete(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Stock Viewer', [
            'products' => ['read'],
            '__special' => ['stock_history.view'],
        ]);

        $movement = $this->seedMovement($organization->id, $viewer->id);

        $this->assertFalse(Gate::forUser($viewer)->allows('create', StockMovement::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $movement));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $movement));
    }

    public function test_stock_movement_model_is_immutable_even_if_update_or_delete_is_attempted(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization, [
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $movement = $this->seedMovement($organization->id, $user->id);

        $this->expectException(\LogicException::class);
        $movement->update(['notes' => 'Tamper attempt']);
    }

    public function test_stock_movement_delete_attempt_is_also_blocked(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization, [
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $movement = $this->seedMovement($organization->id, $user->id);

        $this->expectException(\LogicException::class);
        $movement->delete();
    }

    public function test_superadmin_only_correction_command_creates_compensating_entry_without_mutating_original(): void
    {
        $organization = TestData::organization();
        $superAdmin = TestData::user($organization, [
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $staff = TestData::user($organization, [
            'role' => 'staff',
        ]);

        $movement = $this->seedMovement($organization->id, $superAdmin->id, [
            'from_status' => 'available',
            'to_status' => 'sold',
            'notes' => 'Original sale movement.',
        ]);

        Artisan::call('phos:stock-history-correct', [
            'movement_id' => $movement->id,
            '--performed-by-user' => $staff->id,
            '--correction-type' => StockMovement::TYPE_CORRECTION_ADD,
            '--reason' => 'Unauthorized correction attempt.',
            '--remarks' => 'Unauthorized actor test.',
        ]);

        $this->assertStringContainsString('Only a super admin can create emergency stock history corrections.', Artisan::output());
        $this->assertSame(1, StockMovement::query()->count());

        Artisan::call('phos:stock-history-correct', [
            'movement_id' => $movement->id,
            '--performed-by-user' => $superAdmin->id,
            '--correction-type' => StockMovement::TYPE_CORRECTION_ADD,
            '--reason' => 'Stock was reopened after mistaken completion.',
            '--remarks' => 'Approved compensating correction.',
        ]);

        $this->assertSame(2, StockMovement::query()->count());

        $movement->refresh();
        $this->assertSame('Original sale movement.', $movement->notes);

        $correction = StockMovement::query()
            ->where('id', '!=', $movement->id)
            ->sole();

        $this->assertSame(StockMovement::TYPE_CORRECTION_ADD, $correction->movement_type);
        $this->assertSame($movement->quantity, $correction->quantity);
        $this->assertSame($movement->to_status, $correction->from_status);
        $this->assertSame($movement->from_status, $correction->to_status);
        $this->assertSame($superAdmin->id, $correction->performed_by_user_id);
        $this->assertStringContainsString((string) $movement->id, $correction->notes ?? '');
        $this->assertStringContainsString('Reason: Stock was reopened after mistaken completion.', $correction->notes ?? '');
    }

    public function test_stock_movement_records_receive_checksum_on_create(): void
    {
        $organization = TestData::organization();
        $superAdmin = TestData::user($organization, [
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $movement = $this->seedMovement($organization->id, $superAdmin->id);

        $this->assertNotEmpty($movement->checksum);
        $this->assertSame(64, strlen((string) $movement->checksum));
    }

    public function test_correction_requires_valid_type_reason_and_remarks(): void
    {
        $organization = TestData::organization();
        $superAdmin = TestData::user($organization, [
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $movement = $this->seedMovement($organization->id, $superAdmin->id);

        Artisan::call('phos:stock-history-correct', [
            'movement_id' => $movement->id,
            '--performed-by-user' => $superAdmin->id,
            '--correction-type' => 'invalid_type',
            '--reason' => 'Bad type test.',
            '--remarks' => 'Bad type remarks.',
        ]);

        $this->assertStringContainsString('A valid --correction-type is required', Artisan::output());
        $this->assertSame(1, StockMovement::query()->count());

        Artisan::call('phos:stock-history-correct', [
            'movement_id' => $movement->id,
            '--performed-by-user' => $superAdmin->id,
            '--correction-type' => StockMovement::TYPE_CORRECTION_REMOVE,
        ]);

        $this->assertStringContainsString('A correction reason is required via --reason.', Artisan::output());
        $this->assertSame(1, StockMovement::query()->count());

        Artisan::call('phos:stock-history-correct', [
            'movement_id' => $movement->id,
            '--performed-by-user' => $superAdmin->id,
            '--correction-type' => StockMovement::TYPE_CORRECTION_REMOVE,
            '--reason' => 'Missing remarks test.',
        ]);

        $this->assertStringContainsString('Correction remarks are required via --remarks.', Artisan::output());
        $this->assertSame(1, StockMovement::query()->count());
    }

    private function seedMovement(int $organizationId, ?int $performedByUserId, array $overrides = []): StockMovement
    {
        $product = Product::create([
            'organization_id' => $organizationId,
            'name' => 'Immutable Ledger Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'is_sellable' => true,
            'is_rentable' => false,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'sale_price' => 1500,
            'price_per_day' => 0,
        ]);

        return StockMovement::create(array_merge([
            'organization_id' => $organizationId,
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 2,
            'from_status' => null,
            'to_status' => 'available',
            'performed_by_user_id' => $performedByUserId,
            'movement_at' => now(),
            'notes' => 'Immutable seed movement.',
            'checksum' => StockMovement::checksumFor([
                'organization_id' => $organizationId,
                'product_id' => $product->id,
                'movement_type' => StockMovement::TYPE_OPENING,
                'quantity' => 2,
                'from_status' => null,
                'to_status' => 'available',
                'performed_by_user_id' => $performedByUserId,
                'movement_at' => now(),
                'notes' => 'Immutable seed movement.',
            ]),
        ], $overrides));
    }

    private function userWithRole(Organization $organization, string $name, array $permissions): User
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
}
