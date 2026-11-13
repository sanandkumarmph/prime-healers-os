<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Cylinder;
use App\Models\CylinderJob;
use App\Models\CylinderRefill;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class VendorAssignedWorkAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_task_and_rental_views_are_limited_to_their_assignments(): void
    {
        $this->withoutVite();
        $org = TestData::organization();
        $owner = TestData::user($org);
        $role = Role::create([
            'organization_id' => $org->id, 'slug' => User::ROLE_VENDOR, 'name' => 'Vendor',
            'is_active' => true, 'permissions' => ['deliveries' => ['read', 'update']],
        ]);
        $vendor = TestData::user($org, ['role' => User::ROLE_VENDOR, 'role_id' => $role->id]);
        $otherVendor = TestData::user($org, ['role' => User::ROLE_VENDOR, 'role_id' => $role->id]);
        $sales = TestData::user($org, ['role' => User::ROLE_SALES]);

        $ownRental = $this->makeRental($org->id, 'Vendor scope assigned customer');
        $otherRental = $this->makeRental($org->id, 'Vendor scope other customer');
        $unassignedRental = $this->makeRental($org->id, 'Vendor scope unassigned customer');
        $cancelledRental = $this->makeRental($org->id, 'Vendor scope cancelled customer');
        $foreignRental = $this->makeRental(TestData::organization(['name' => 'Foreign PHOS'])->id, 'Foreign customer');
        $ownTask = $this->makeTask($ownRental, $vendor, 'pickup');
        $otherTask = $this->makeTask($otherRental, $otherVendor);
        $otherTaskForOwnRental = $this->makeTask($ownRental, $otherVendor);
        $unassignedTask = $this->makeTask($unassignedRental);
        $this->makeTask($cancelledRental, $vendor, 'pickup', 'cancelled');

        $this->actingAs($vendor);
        $this->get(route('deliveries.index', ['tab' => 'all', 'ownership' => 'all']))->assertOk()
            ->assertSee(route('deliveries.show', $ownTask), false)
            ->assertDontSee(route('deliveries.show', $otherTask), false)
            ->assertDontSee(route('deliveries.show', $unassignedTask), false);
        $this->get(route('deliveries.show', $ownTask))->assertOk()
            ->assertDontSee(route('deliveries.edit', $ownTask), false);
        $this->get(route('deliveries.edit', $ownTask))->assertForbidden();
        $this->put(route('deliveries.update', $ownTask))->assertForbidden();
        $this->get(route('deliveries.show', $otherTask))->assertForbidden();
        $this->get(route('deliveries.show', $unassignedTask))->assertForbidden();
        $this->put(route('deliveries.in_progress', $unassignedTask))->assertForbidden();
        $this->get(route('delivery-collections.show', $ownTask))->assertForbidden();
        $this->get(route('delivery-collections.show', $otherTask))->assertForbidden();
        $this->get(route('pickup-center.index'))->assertRedirect(route('deliveries.index', ['board' => 'pickup_workload']));
        $this->post(route('pickup-center.assign', $ownTask))->assertForbidden();
        $this->post(route('pickup-center.reschedule', $ownTask))->assertForbidden();

        $this->get(route('rentals.index'))->assertOk()->assertSeeText($ownRental->customer_name)
            ->assertDontSeeText([$otherRental->customer_name, $unassignedRental->customer_name, $cancelledRental->customer_name]);
        $this->get(route('rentals.show', $ownRental))->assertOk()->assertSeeText($ownRental->customer_name)
            ->assertSee(route('deliveries.show', $ownTask), false)
            ->assertDontSee(route('deliveries.show', $otherTaskForOwnRental), false)
            ->assertDontSeeText(['Record payment', 'View signed agreement', '3,141.59']);
        foreach ([$otherRental, $unassignedRental, $cancelledRental] as $rental) {
            $this->get(route('rentals.show', $rental))->assertForbidden();
        }
        $this->get(route('rentals.show', $foreignRental))->assertNotFound();

        $this->get(route('search.global', ['q' => 'Vendor scope']))->assertOk()
            ->assertSeeText($ownRental->customer_name)->assertDontSeeText($otherRental->customer_name);
        $this->getJson(route('search.suggestions', ['q' => 'Vendor scope', 'type' => 'rental']))
            ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $ownRental->id);
        $this->getJson(route('search.suggestions', ['q' => 'Vendor scope assigned', 'type' => 'pickup']))
            ->assertOk()->assertJsonPath('results.0.id', $ownTask->id);
        $this->getJson(route('search.suggestions', ['q' => 'Vendor scope other', 'type' => 'delivery']))
            ->assertOk()->assertJsonCount(0, 'results');

        foreach (['renewal-center.index', 'availability.index', 'rentals.available-assets', 'dashboard.export.csv',
            'deliveries.assets.create', 'deliveries.route-plan', 'communication-center.index', 'invoices.index', 'payments.export.csv'] as $route) {
            $this->getJson(route($route))->assertForbidden();
        }
        $this->getJson(route('payments.create', $ownRental))->assertForbidden();
        $this->post(route('communication-center.store'))->assertForbidden();

        foreach ([$owner, $sales] as $internal) {
            $this->actingAs($internal)->get(route('rentals.show', $otherRental))->assertOk();
            $this->get(route('deliveries.show', $otherTask))->assertOk();
        }
    }

    public function test_vendor_cylinder_work_follows_current_assignment_and_unlocks_linked_rental(): void
    {
        $this->withoutVite();
        $org = TestData::organization();
        $owner = TestData::user($org);
        $vendor = TestData::user($org, ['role' => User::ROLE_VENDOR]);
        $other = TestData::user($org, ['role' => User::ROLE_DELIVERY]);
        $ownRental = $this->makeRental($org->id, 'Assigned cylinder customer');
        $otherRental = $this->makeRental($org->id, 'Reassigned cylinder customer');
        $refillRental = $this->makeRental($org->id, 'Assigned refill customer');
        $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Synthetic warehouse', 'is_active' => true]);

        $makeJob = function (Rental $rental, User $assignee, User $creator) use ($warehouse): CylinderJob {
            $asset = Asset::create([
                'organization_id' => $rental->organization_id, 'product_id' => $rental->product_id,
                'warehouse_id' => $warehouse->id,
                'serial_number' => (string) Str::uuid(), 'asset_stage' => 'rental_stock',
                'asset_status' => 'rented', 'condition_status' => 'good',
            ]);
            $cylinder = Cylinder::create(['organization_id' => $rental->organization_id, 'asset_id' => $asset->id]);

            return CylinderJob::create([
                'organization_id' => $rental->organization_id, 'rental_id' => $rental->id,
                'incoming_cylinder_id' => $cylinder->id, 'outgoing_cylinder_id' => $cylinder->id,
                'assigned_user_id' => $assignee->id, 'created_by_user_id' => $creator->id,
                'request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('0', 64),
                'mode' => 'same', 'scheduled_at' => now(),
            ]);
        };
        $ownJob = $makeJob($ownRental, $vendor, $owner);
        $otherJob = $makeJob($otherRental, $other, $vendor);
        CylinderRefill::create([
            'organization_id' => $org->id, 'rental_id' => $refillRental->id, 'product_id' => $refillRental->product_id,
            'assigned_user_id' => $vendor->id, 'created_by_user_id' => $owner->id,
            'request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('0', 64),
            'capacity_label' => 'Synthetic cylinder', 'stock_mode' => 'quantity', 'scheduled_at' => now(),
        ]);

        $this->actingAs($vendor)->get(route('cylinder-services.index'))->assertOk()
            ->assertSeeText($ownRental->customer_name)->assertDontSeeText($otherRental->customer_name);
        $this->get(route('cylinder-services.show', $ownJob))->assertOk();
        $this->get(route('cylinder-services.show', $otherJob))->assertNotFound();
        $this->postJson(route('cylinder-services.action', $otherJob), [
            'request_id' => (string) Str::uuid(), 'lock_version' => 0, 'action' => 'cancelled',
        ])->assertNotFound();
        $this->get(route('rentals.show', $ownRental))->assertOk()
            ->assertSee(route('cylinder-services.show', $ownJob), false)
            ->assertDontSee(route('cylinder-refills.popup', $ownRental), false);
        $this->get(route('rentals.show', $refillRental))->assertOk()
            ->assertSee(route('cylinder-refills.popup', $refillRental), false);
        $this->get(route('cylinder-refills.popup', $refillRental))->assertOk()
            ->assertSeeText('Synthetic cylinder');
        $this->get(route('rentals.show', $otherRental))->assertForbidden();
    }

    private function makeRental(int $organizationId, string $customerName): Rental
    {
        $customer = Customer::create(['organization_id' => $organizationId, 'name' => $customerName, 'phone' => '+12025550123']);
        $product = Product::create([
            'organization_id' => $organizationId, 'name' => 'Synthetic rental equipment',
            'product_type' => 'rentable', 'price_per_day' => 10, 'stock_mode' => 'untracked',
            'total_quantity' => 1, 'available_quantity' => 1,
        ]);

        return Rental::create([
            'organization_id' => $organizationId, 'customer_id' => $customer->id, 'product_id' => $product->id,
            'customer_name' => $customerName, 'phone' => $customer->phone, 'quantity' => 1, 'status' => 'active',
            'start_date' => today()->subDays(3), 'end_date' => today()->addDays(10), 'rental_amount' => 3141.59,
        ]);
    }

    private function makeTask(Rental $rental, ?User $assignee = null, string $type = 'delivery', string $status = 'pending'): Delivery
    {
        return Delivery::create([
            'organization_id' => $rental->organization_id, 'rental_id' => $rental->id, 'type' => $type,
            'assigned_user_id' => $assignee?->id, 'assignment_type' => $assignee ? 'vendor' : 'delivery_team',
            'status' => $status, 'scheduled_at' => today()->setTime(10, 0),
        ]);
    }
}
