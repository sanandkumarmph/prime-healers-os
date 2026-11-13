<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Delivery, Organization, Product, Rental, Role, Staff, User};
use App\Support\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class VendorDispatchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassigning_a_delivery_transfers_task_rental_and_notification_access(): void
    {
        $this->withoutVite();
        $org = TestData::organization();
        $admin = TestData::user($org);
        $original = $this->vendor($org, 'Original Vendor Operator');
        $replacement = $this->vendor($org, 'Replacement Vendor Operator');
        $rental = $this->rental($org, 'Assigned rental customer');
        $unassignedRental = $this->rental($org, 'Unassigned rental customer');
        $vendorStaff = Staff::create(['organization_id' => $org->id, 'name' => 'Vendor company dispatch record',
            'role' => 'vendor', 'assignment_role' => 'vendor', 'is_assignment_enabled' => true, 'status' => 'active']);
        $assignment = $this->assignment($rental, 'delivery') + ['vendor_user_id' => $original->id, 'assigned_staff_id' => $vendorStaff->id];

        $this->actingAs($admin)->get(route('deliveries.create', ['rental_id' => $rental->id]))->assertOk()
            ->assertSee('name="vendor_user_id"', false)->assertSeeText($original->name)->assertSeeText($replacement->name);
        $this->post(route('deliveries.store'), $assignment)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post(route('deliveries.store'), $this->assignment($unassignedRental, 'delivery') + ['assigned_staff_id' => $vendorStaff->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $task = Delivery::where('rental_id', $rental->id)->sole();
        $unassigned = Delivery::where('rental_id', $unassignedRental->id)->sole();
        $this->assertSame($original->id, (int) $task->assigned_user_id);
        $this->assertSame($vendorStaff->id, (int) $task->assigned_staff_id);
        $this->assertNull($unassigned->assigned_user_id);

        $taskUrl = route('deliveries.show', $task);
        $rentalUrl = route('rentals.show', $rental);
        $boardUrl = route('deliveries.index', ['tab' => 'all', 'ownership' => 'all']);
        $this->actingAs($original)->get($boardUrl)->assertOk()->assertSee($taskUrl, false)
            ->assertDontSee('"'.route('deliveries.show', $unassigned).'"', false);
        $this->get($rentalUrl)->assertOk()->assertSee($taskUrl, false);
        $this->get(route('rentals.show', $unassignedRental))->assertForbidden();
        $this->get(route('deliveries.show', $unassigned))->assertForbidden();
        $this->put(route('deliveries.in_progress', $unassigned), $this->startPayload())->assertForbidden();
        $this->actingAs($replacement)->get($taskUrl)->assertForbidden();
        $this->get($rentalUrl)->assertForbidden();

        $this->actingAs($original)->getJson(route('notifications.latest'))->assertOk()
            ->assertJsonPath('notifications.0.action_url', $taskUrl);
        $notificationId = (string) $original->notifications()->firstOrFail()->id;
        $originalSubmission = $this->startPayload();
        $this->put(route('deliveries.in_progress', $task), $originalSubmission)->assertSessionHasNoErrors()
            ->assertRedirect($taskUrl.'#workflow-proof-section');
        $this->assertSame('in_progress', $task->fresh()->status);

        $this->actingAs($admin)->get(route('deliveries.edit', $task))->assertOk()
            ->assertSee('name="vendor_user_id"', false)->assertSeeText($replacement->name);
        $this->put(route('deliveries.update', $task), array_replace($assignment, ['vendor_user_id' => $replacement->id]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($replacement->id, (int) $task->fresh()->assigned_user_id);
        $this->assertSame($vendorStaff->id, (int) $task->fresh()->assigned_staff_id);

        $this->actingAs($original)->get($boardUrl)->assertOk()->assertDontSee($taskUrl, false);
        $this->get(route('rentals.index'))->assertOk()->assertDontSee($rentalUrl, false);
        $this->get($taskUrl)->assertForbidden();
        $this->get($rentalUrl)->assertForbidden();
        $this->put(route('deliveries.in_progress', $task), $originalSubmission)->assertForbidden();
        $this->getJson(route('notifications.latest'))->assertOk()->assertJsonCount(0, 'notifications')->assertJsonPath('unread_count', 0);
        $this->get(route('notifications.index'))->assertOk()->assertDontSeeText($rental->customer_name);
        $this->postJson(route('notifications.read', $notificationId))->assertNotFound();

        $this->actingAs($replacement)->get($taskUrl)->assertOk();
        $this->get($rentalUrl)->assertOk()->assertSee($taskUrl, false);
        $this->getJson(route('notifications.latest'))->assertOk()->assertJsonPath('notifications.0.action_url', $taskUrl);
        $this->put(route('deliveries.in_progress', $task), $this->startPayload())->assertSessionHasNoErrors()
            ->assertRedirect($taskUrl.'#workflow-proof-section');
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_pickup_assignment_and_reassignment_limit_each_task_on_a_shared_rental(): void
    {
        $this->withoutVite();
        $org = TestData::organization();
        $admin = TestData::user($org);
        $deliveryOperator = $this->vendor($org, 'Delivery Vendor Operator');
        $pickupOperator = $this->vendor($org, 'Pickup Vendor Operator');
        $rental = $this->rental($org, 'Shared rental customer', delivered: true);

        $this->actingAs($admin)->post(route('deliveries.store'), array_replace($this->assignment($rental, 'delivery'), [
            'status' => 'completed', 'vendor_user_id' => $deliveryOperator->id,
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->post(route('deliveries.store'), $this->assignment($rental, 'pickup') + ['vendor_user_id' => $pickupOperator->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $delivery = Delivery::where('rental_id', $rental->id)->where('type', 'delivery')->sole();
        $pickup = Delivery::where('rental_id', $rental->id)->where('type', 'pickup')->sole();
        $this->assertSame($pickupOperator->id, (int) $pickup->assigned_user_id);
        $this->assertSame('assigned', $pickup->pickup_status);
        $deliveryUrl = route('deliveries.show', $delivery);
        $pickupUrl = route('deliveries.show', $pickup);
        $rentalUrl = route('rentals.show', $rental);
        ActivityLogger::log('delivery.test', $delivery, [], 'Delivery-only activity marker');
        ActivityLogger::log('pickup.test', $pickup, [], 'Pickup-only activity marker');

        $this->actingAs($pickupOperator)->get($rentalUrl)->assertOk()
            ->assertSee($pickupUrl, false)->assertDontSee($deliveryUrl, false);
        $this->get($pickupUrl)->assertOk()->assertSeeText('Pickup-only activity marker')
            ->assertDontSeeText('Delivery-only activity marker');
        $this->get($deliveryUrl)->assertForbidden();
        $this->actingAs($deliveryOperator)->get($rentalUrl)->assertOk()
            ->assertSee($deliveryUrl, false)->assertDontSee($pickupUrl, false);
        $this->get($deliveryUrl)->assertOk()->assertSeeText('Delivery-only activity marker')
            ->assertDontSeeText('Pickup-only activity marker');
        $this->get($pickupUrl)->assertForbidden();
        $this->put(route('deliveries.in_progress', $pickup), $this->startPayload())->assertForbidden();
        $this->actingAs($pickupOperator)->put(route('deliveries.in_progress', $pickup), $this->startPayload())
            ->assertSessionHasNoErrors()->assertRedirect($pickupUrl.'#workflow-proof-section');
        $this->assertSame('in_progress', $pickup->fresh()->pickup_status);

        $this->actingAs($admin)->get(route('pickup-center.index', ['tab' => 'all']))->assertOk()
            ->assertSee('vendor-user:'.$deliveryOperator->id, false);
        $this->post(route('pickup-center.assign', $pickup), [
            'assignment_target' => 'vendor-user:'.$deliveryOperator->id,
            'pickup_date' => now()->toDateString(), 'pickup_time_slot' => '12:00-15:00',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('vendor', $pickup->fresh()->assignment_type);
        $this->assertSame($deliveryOperator->id, (int) $pickup->fresh()->assigned_user_id);

        $this->actingAs($pickupOperator)->get($pickupUrl)->assertForbidden();
        $this->get($rentalUrl)->assertForbidden();
        $this->put(route('deliveries.in_progress', $pickup), $this->startPayload())->assertForbidden();
        $this->actingAs($deliveryOperator)->get($rentalUrl)->assertOk()->assertSee($pickupUrl, false);
        $this->get($pickupUrl)->assertOk();
        $this->put(route('deliveries.in_progress', $pickup), $this->startPayload())
            ->assertSessionHasNoErrors()->assertRedirect($pickupUrl.'#workflow-proof-section');
        $this->assertSame('in_progress', $pickup->fresh()->pickup_status);
    }

    public function test_dispatch_rejects_inactive_foreign_and_non_vendor_logins(): void
    {
        $this->withoutVite();
        $org = TestData::organization();
        $admin = TestData::user($org);
        $rental = $this->rental($org, 'Validation customer');
        $inactive = $this->vendor($org, 'Inactive Vendor Operator', false);
        $foreign = $this->vendor(TestData::organization(['name' => 'Another organization']), 'Foreign Vendor Operator');
        $driver = TestData::user($org, ['role' => User::ROLE_DELIVERY, 'name' => 'Internal Driver']);

        $this->actingAs($admin)->get(route('deliveries.create', ['rental_id' => $rental->id]))->assertOk()
            ->assertDontSeeText($inactive->name)->assertDontSeeText($foreign->name);
        foreach ([$inactive, $foreign, $driver] as $invalid) {
            $this->postJson(route('deliveries.store'), $this->assignment($rental, 'delivery') + ['vendor_user_id' => $invalid->id])
                ->assertUnprocessable()->assertJsonValidationErrors('vendor_user_id');
        }
        $this->assertDatabaseCount('deliveries', 0);
    }

    private function vendor(Organization $org, string $name, bool $active = true): User
    {
        $role = Role::firstOrCreate(['organization_id' => $org->id, 'slug' => User::ROLE_VENDOR], [
            'name' => 'Vendor', 'is_active' => true, 'permissions' => ['deliveries' => ['read', 'update']],
        ]);

        return TestData::user($org, ['role' => User::ROLE_VENDOR, 'role_id' => $role->id, 'name' => $name, 'is_active' => $active]);
    }

    private function rental(Organization $org, string $name, bool $delivered = false): Rental
    {
        $customer = Customer::create(['organization_id' => $org->id, 'name' => $name, 'phone' => '+12025550123']);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Vendor assignment rental product',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'price_per_day' => 25,
            'total_quantity' => 5, 'available_quantity' => 5]);
        $rental = Rental::create(['organization_id' => $org->id, 'customer_id' => $customer->id, 'product_id' => $product->id,
            'customer_name' => $name, 'phone' => $customer->phone, 'quantity' => 1, 'status' => 'active',
            'start_date' => today()->subDay(), 'end_date' => today()->addDay(), 'rental_amount' => 25]);
        $rental->rentalItems()->create(['organization_id' => $org->id, 'product_id' => $product->id, 'quantity' => 1,
            'ordered_quantity' => 1, 'delivered_quantity' => (int) $delivered, 'returned_quantity' => 0]);

        return $rental;
    }

    private function assignment(Rental $rental, string $type): array
    {
        return ['rental_id' => $rental->id, 'type' => $type, 'scheduled_at' => now()->addHour()->toDateTimeString(),
            'status' => 'pending', 'assignment_type' => 'vendor'];
    }

    private function startPayload(): array
    {
        return ['workflow_capture_form' => '1', 'location_missing_reason' => 'GPS unavailable in the test building',
            'workflow_submission_token' => (string) Str::uuid()];
    }
}
