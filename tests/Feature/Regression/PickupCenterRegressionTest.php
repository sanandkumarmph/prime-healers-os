<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class PickupCenterRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization([
            'state' => 'Karnataka',
        ]);

        $this->organizationId = $organization->id;
        $this->actingAs(TestData::user($organization));
    }

    public function test_pickup_center_loads_and_requested_tab_shows_requested_pickups(): void
    {
        $requested = $this->makePickupTask(['pickup_status' => 'requested']);
        $completed = $this->makePickupTask(['status' => 'completed', 'pickup_status' => 'picked_up']);

        $this->get(route('pickup-center.index', ['tab' => 'pickup_requested']))
            ->assertOk()
            ->assertSeeText('Pickup Center')
            ->assertSeeText('Pickup #' . $requested->id)
            ->assertDontSeeText('Pickup #' . $completed->id);
    }

    public function test_business_partner_pickup_shows_actual_client_for_pickup_and_partner_for_reminder(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $rental = $this->makeRental([
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'customer_id' => null,
            'customer_name' => $client->displayName(),
            'phone' => $client->phone,
        ]);

        $pickup = Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'requested',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->get(route('pickup-center.index'))
            ->assertOk()
            ->assertSeeText('Pickup #' . $pickup->id)
            ->assertSeeText('Pickup From')
            ->assertSeeText($client->displayName())
            ->assertSeeText('Reminder / Payment')
            ->assertSeeText($partner->displayName());
    }

    public function test_pickup_center_renders_separate_contact_sections_and_compact_more_actions(): void
    {
        $pickup = $this->makePickupTask([
            'pickup_status' => 'assigned',
        ], [
            'address' => 'Very Long Pickup Address, Block A, 4th Cross, Indiranagar Extension, Bengaluru, Karnataka 560038',
        ]);

        $this->get(route('pickup-center.index'))
            ->assertOk()
            ->assertSeeText('Pickup #' . $pickup->id)
            ->assertSeeText('Pickup From')
            ->assertSeeText('Reminder / Payment')
            ->assertSeeText('Address')
            ->assertSeeText('More')
            ->assertSee('pickup-card-contacts', false)
            ->assertSee('pickup-more-menu', false);
    }

    public function test_assign_staff_updates_pickup_task(): void
    {
        $pickup = $this->makePickupTask();
        $deliveryUser = User::factory()->create([
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_DELIVERY,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->post(route('pickup-center.assign', $pickup), [
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time_slot' => '12:00-15:00',
            'assignment_target' => 'user:' . $deliveryUser->id,
            'notes' => 'Pickup from security gate',
        ])->assertRedirect();

        $pickup->refresh();

        $this->assertSame('assigned', $pickup->pickup_status);
        $this->assertSame($deliveryUser->id, (int) $pickup->assigned_user_id);
        $this->assertSame('12:00-15:00', $pickup->pickup_time_slot);
        $this->assertSame('Pickup from security gate', $pickup->last_pickup_note);
    }

    public function test_failed_attempt_can_store_reason_and_reschedule(): void
    {
        $pickup = $this->makePickupTask([
            'scheduled_at' => now()->toDateTimeString(),
        ]);

        $this->post(route('pickup-center.failed-attempt', $pickup), [
            'failed_attempt_reason' => 'customer_not_available',
            'failed_attempt_note' => 'Customer asked to come tomorrow morning.',
            'reschedule_date' => now()->addDay()->toDateString(),
            'pickup_time_slot' => '09:00-12:00',
        ])->assertRedirect();

        $pickup->refresh();

        $this->assertSame('rescheduled', $pickup->pickup_status);
        $this->assertSame('customer_not_available', $pickup->failed_attempt_reason);
        $this->assertSame('Customer asked to come tomorrow morning.', $pickup->failed_attempt_note);
        $this->assertSame('09:00-12:00', $pickup->pickup_time_slot);
        $this->assertNotNull($pickup->failed_attempt_at);
        $this->assertNotNull($pickup->rescheduled_from_at);
    }

    public function test_reschedule_updates_pickup_schedule(): void
    {
        $pickup = $this->makePickupTask([
            'scheduled_at' => now()->toDateTimeString(),
            'pickup_status' => 'assigned',
        ]);

        $this->post(route('pickup-center.reschedule', $pickup), [
            'pickup_date' => now()->addDays(3)->toDateString(),
            'pickup_time_slot' => '15:00-18:00',
            'reason' => 'Customer requested afternoon slot.',
        ])->assertRedirect();

        $pickup->refresh();

        $this->assertSame('rescheduled', $pickup->pickup_status);
        $this->assertSame('15:00-18:00', $pickup->pickup_time_slot);
        $this->assertSame('Customer requested afternoon slot.', $pickup->last_pickup_note);
        $this->assertNotNull($pickup->rescheduled_from_at);
    }

    public function test_delivery_user_sees_allowed_assigned_pickup_only(): void
    {
        $deliveryUser = User::factory()->create([
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_DELIVERY,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $allowedPickup = $this->makePickupTask([
            'assigned_user_id' => $deliveryUser->id,
            'pickup_status' => 'assigned',
        ]);

        $otherUser = User::factory()->create([
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_DELIVERY,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $blockedPickup = $this->makePickupTask([
            'assigned_user_id' => $otherUser->id,
            'pickup_status' => 'assigned',
        ]);

        $this->actingAs($deliveryUser)
            ->get(route('pickup-center.index'))
            ->assertOk()
            ->assertSeeText('Pickup #' . $allowedPickup->id)
            ->assertDontSeeText('Pickup #' . $blockedPickup->id);
    }

    public function test_dashboard_shows_pickup_center_cards(): void
    {
        $this->makePickupTask([
            'scheduled_at' => now()->toDateTimeString(),
            'pickup_status' => 'assigned',
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $this->makeProduct()->id,
            'warehouse_id' => $this->makeWarehouse()->id,
            'asset_name' => 'Awaiting Verify Unit',
            'serial_number' => 'AV-' . uniqid(),
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Pickup Center')
            ->assertSeeText('Pickups Pending')
            ->assertSeeText('Pickup queue');
    }

    private function makePickupTask(array $deliveryOverrides = [], array $rentalOverrides = []): Delivery
    {
        $rental = $this->makeRental($rentalOverrides);

        return Delivery::create(array_merge([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'requested',
            'scheduled_at' => now()->addDay(),
            'assignment_type' => 'delivery_team',
        ], $deliveryOverrides));
    }

    private function makeRental(array $overrides = []): Rental
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Pickup Customer ' . uniqid(),
            'phone' => '990001' . random_int(1000, 9999),
            'address' => '12 Pickup Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ]);

        $product = $this->makeProduct();

        $rental = Rental::create(array_merge([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 300,
            'transport_amount' => 0,
            'other_amount' => 0,
        ], $overrides));

        Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDays(9),
            'completed_at' => now()->subDays(9),
        ]);

        Invoice::create([
            'organization_id' => $this->organizationId,
            'invoice_number' => 'INV-' . uniqid(),
            'invoice_date' => now()->subDays(5)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'bill_to_name' => $rental->billingContactName(),
            'bill_to_phone' => $rental->billingContactPhone(),
            'bill_to_address' => $rental->billingContactAddress(),
            'bill_to_city' => $rental->billingContactCity(),
            'bill_to_state' => $rental->billingContactState(),
            'bill_to_pincode' => $rental->billingContactPincode(),
            'ship_to_name' => $rental->deliveryContactName(),
            'ship_to_phone' => $rental->deliveryContactPhone(),
            'ship_to_address' => $rental->deliveryContactAddress(),
            'ship_to_city' => $rental->deliveryContactCity(),
            'ship_to_state' => $rental->deliveryContactState(),
            'ship_to_pincode' => $rental->deliveryContactPincode(),
            'place_of_supply_state' => $rental->primaryTaxState(),
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'subtotal' => 1500,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 1500,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'balance_amount' => 1500,
        ]);

        return $rental->fresh(['customer', 'businessPartner', 'partnerClient', 'invoice', 'product']);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Pickup Product ' . uniqid(),
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 350,
            'rental_price' => 350,
            'sale_price' => 0,
            'available_quantity' => 10,
            'total_quantity' => 10,
        ]);
    }

    private function makeBusinessPartnerContext(): array
    {
        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Portea Tie-up',
            'contact_person' => 'Pickup Desk',
            'phone' => '+919900002020',
            'whatsapp' => '+919900002020',
            'email' => 'pickups@example.test',
            'address' => 'Partner Address',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Mr. Ramesh',
            'phone' => '+919988776655',
            'address' => 'Indiranagar, Bangalore',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560038',
            'location' => 'Indiranagar',
            'delivery_notes' => 'Collect from ground floor security desk.',
            'status' => 'active',
        ]);

        return [$partner, $client];
    }

    private function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Main Warehouse ' . uniqid(),
            'code' => 'WH-' . random_int(100, 999),
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
            'is_active' => true,
        ]);
    }
}
