<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\RentalReminderLog;
use App\Models\Asset;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RenewalCenterRegressionTest extends TestCase
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

    public function test_renewal_center_page_loads_and_due_today_filter_works(): void
    {
        $dueToday = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->makeDirectCustomerRental([
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->get(route('renewal-center.index', ['tab' => 'due_today']));

        $response->assertOk()
            ->assertSeeText('Renewal Center')
            ->assertSeeText('Due Today')
            ->assertSeeText('Rental #' . $dueToday->id)
            ->assertDontSeeText('day(s) overdue');
    }

    public function test_business_partner_rental_shows_partner_as_reminder_and_actual_client_as_service_location(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeRentalProduct();

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'customer_id' => null,
            'customer_name' => $client->displayName(),
            'phone' => $client->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
            'deposit_amount' => 250,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
        ]);

        $this->get(route('renewal-center.index'))
            ->assertOk()
            ->assertSeeText('Reminder To:')
            ->assertSeeText($partner->displayName())
            ->assertSeeText('Service Location:')
            ->assertSeeText($client->displayName())
            ->assertSeeText($client->address);
    }

    public function test_direct_customer_rental_shows_customer_as_reminder_contact(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->get(route('renewal-center.index'))
            ->assertOk()
            ->assertSeeText($rental->customer->displayName())
            ->assertSeeText($rental->customer->phone);
    }

    public function test_renewal_center_searches_product_model_name_without_querying_legacy_model_column(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $rental->product->update([
            'model_name' => 'Oxymed Mini LP',
        ]);

        $this->get(route('renewal-center.index', [
            'search' => 'Mini LP',
        ]))
            ->assertOk()
            ->assertSeeText('Renewal Center')
            ->assertSeeText('Rental #' . $rental->id);
    }

    public function test_mark_reminder_sent_stores_timestamp_and_log_entry(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.mark-reminder-sent', $rental))
            ->assertRedirect(route('renewal-center.index'));

        $this->assertDatabaseHas('rental_reminder_logs', [
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'reminder_type' => 'renewal',
        ]);

        $this->assertNotNull(RentalReminderLog::query()->where('rental_id', $rental->id)->latest('id')->first()?->sent_at);
    }

    public function test_mark_renewed_from_renewal_center_updates_rental_and_returns_to_center(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
            'rental_amount' => 1000,
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('rentals.renew', $rental), [
                'new_end_date' => now()->addDays(7)->toDateString(),
                'rental_amount_added' => 700,
                'deposit_amount_added' => 0,
                'transport_amount_added' => 0,
                'other_amount_added' => 0,
                'payment_amount' => 0,
                'payment_method' => null,
                'payment_date' => now()->toDateString(),
                'notes' => 'Renewed from Renewal Center',
                'return_to_renewal_center' => 1,
            ])
            ->assertRedirect(route('renewal-center.index'));

        $this->assertSame(now()->addDays(7)->toDateString(), $rental->fresh()->end_date?->toDateString());
    }

    public function test_internal_pickup_creates_assigned_pickup_task_for_allowed_user(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);
        $deliveryUser = User::factory()->create([
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_DELIVERY_EXECUTIVE,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'internal_pickup',
                'pickup_date' => now()->addDay()->toDateString(),
                'pickup_time_slot' => '12:00-15:00',
                'pickup_notes' => 'Collect from front desk',
                'assigned_user_id' => $deliveryUser->id,
            ])
            ->assertRedirect(route('renewal-center.index'));

        $this->assertDatabaseHas('deliveries', [
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'assigned',
            'assignment_type' => 'delivery_team',
            'assigned_user_id' => $deliveryUser->id,
        ]);
    }

    public function test_internal_pickup_rejects_admin_sales_and_finance_users(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);
        $admin = User::factory()->create([
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_SUPER_ADMIN,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'internal_pickup',
                'pickup_date' => now()->addDay()->toDateString(),
                'assigned_user_id' => $admin->id,
            ])
            ->assertSessionHasErrors('assigned_user_id');

        $this->assertDatabaseMissing('deliveries', [
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'assigned_user_id' => $admin->id,
        ]);
    }

    public function test_vendor_pickup_creates_vendor_pickup_task(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
            'fulfilment_source' => 'vendor_supplied',
        ]);
        $vendor = Vendor::create([
            'organization_id' => $this->organizationId,
            'name' => 'Pickup Vendor',
            'contact_person' => 'Vendor Desk',
            'phone' => '9876500000',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'is_active' => true,
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'vendor_pickup',
                'pickup_date' => now()->addDay()->toDateString(),
                'vendor_id' => $vendor->id,
            ])
            ->assertRedirect(route('renewal-center.index'));

        $this->assertDatabaseHas('deliveries', [
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'assignment_type' => 'vendor',
            'third_party_name' => 'Pickup Vendor',
        ]);
    }

    public function test_vendor_pickup_is_rejected_for_in_house_rentals(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
            'fulfilment_source' => 'in_house',
        ]);
        $vendor = Vendor::create([
            'organization_id' => $this->organizationId,
            'name' => 'Hidden Pickup Vendor',
            'is_active' => true,
        ]);

        $this->from(route('rentals.show', $rental))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'vendor_pickup',
                'pickup_date' => now()->addDay()->toDateString(),
                'vendor_id' => $vendor->id,
            ])
            ->assertRedirect(route('rentals.show', $rental))
            ->assertSessionHasErrors('pickup_method');

        $this->assertDatabaseMissing('deliveries', [
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'assignment_type' => 'vendor',
        ]);
    }

    public function test_pickup_modal_hides_vendor_pickup_for_in_house_rentals(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'fulfilment_source' => 'in_house',
        ]);

        $this->get(route('rentals.show', $rental))
            ->assertOk()
            ->assertSeeText('Internal Pickup')
            ->assertSeeText('Third Party Pickup')
            ->assertSeeText('Customer Self Drop')
            ->assertDontSeeText('Vendor Pickup')
            ->assertSee('data-default-pickup-method="internal_pickup"', false)
            ->assertSee('max-height:min(90vh, calc(100dvh - 120px))', false);
    }

    public function test_pickup_modal_shows_vendor_pickup_for_vendor_supplied_rentals(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'fulfilment_source' => 'vendor_supplied',
        ]);

        $this->get(route('rentals.show', $rental))
            ->assertOk()
            ->assertSeeText('Vendor Pickup')
            ->assertSeeText('Third Party Pickup')
            ->assertSeeText('Customer Self Drop')
            ->assertDontSeeText('Internal Pickup')
            ->assertSee('data-default-pickup-method="vendor_pickup"', false);
    }

    public function test_third_party_pickup_creates_third_party_task_with_provider_details(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'third_party_pickup',
                'pickup_date' => now()->addDay()->toDateString(),
                'third_party_provider' => 'Fast Courier',
                'third_party_tracking_number' => 'TRK-123',
                'third_party_contact_person' => 'Raj',
                'third_party_phone' => '9999999999',
                'third_party_pickup_cost' => 250,
            ])
            ->assertRedirect(route('renewal-center.index'));

        $this->assertDatabaseHas('deliveries', [
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'assignment_type' => 'third_party',
            'third_party_name' => 'Fast Courier',
            'third_party_contact' => 'Raj',
            'third_party_phone' => '9999999999',
        ]);

        $this->assertStringContainsString('Tracking: TRK-123', Delivery::query()->where('rental_id', $rental->id)->where('type', 'pickup')->latest('id')->value('notes'));
    }

    public function test_customer_self_drop_skips_pickup_task_and_sends_asset_to_verification(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);
        $asset = $this->attachRentedAssetToRental($rental);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'customer_self_drop',
                'pickup_date' => now()->toDateString(),
                'pickup_notes' => 'Customer dropped at front desk.',
            ])
            ->assertRedirect(route('assets.verify-return', $asset));

        $this->assertDatabaseMissing('deliveries', [
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
        ]);

        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $asset->fresh()->asset_status);
        $this->assertNotNull(RentalAsset::query()->where('rental_id', $rental->id)->where('asset_id', $asset->id)->first()?->returned_at);
    }

    public function test_customer_self_drop_for_vendor_supplied_rental_skips_ph_verification(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
            'fulfilment_source' => 'vendor_supplied',
        ]);
        $asset = $this->attachRentedAssetToRental($rental);

        $this->from(route('rentals.show', $rental))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_method' => 'customer_self_drop',
                'pickup_date' => now()->toDateString(),
                'pickup_notes' => 'Customer dropped directly at vendor.',
            ])
            ->assertRedirect(route('rentals.show', $rental));

        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertSame(Asset::STATUS_RENTED, $asset->fresh()->asset_status);
        $this->assertDatabaseMissing('stock_movements', [
            'asset_id' => $asset->id,
            'movement_type' => 'pickup_return',
        ]);
        $this->assertDatabaseMissing('assets', [
            'id' => $asset->id,
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);
    }

    public function test_completed_vendor_pickup_skips_ph_verification(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
            'fulfilment_source' => 'vendor_supplied',
        ]);
        $asset = $this->attachRentedAssetToRental($rental);
        $vendor = Vendor::create([
            'organization_id' => $this->organizationId,
            'name' => 'Vendor Pickup Team',
            'is_active' => true,
        ]);

        $this->post(route('renewal-center.schedule-pickup', $rental), [
            'pickup_method' => 'vendor_pickup',
            'pickup_date' => now()->toDateString(),
            'vendor_id' => $vendor->id,
        ])->assertRedirect();

        $pickup = Delivery::query()->where('rental_id', $rental->id)->where('type', 'pickup')->firstOrFail();

        app(\App\Services\Deliveries\DeliveryWorkflowService::class)
            ->finalizePickupCompletionForRental($this->organizationId, $rental->fresh(), now()->toDateTimeString(), $pickup->id);

        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertSame(Asset::STATUS_RENTED, $asset->fresh()->asset_status);
        $this->assertDatabaseMissing('stock_movements', [
            'asset_id' => $asset->id,
            'movement_type' => 'pickup_return',
        ]);
    }

    public function test_third_party_pickup_completion_routes_by_fulfilment_source(): void
    {
        $inHouseRental = $this->makeDirectCustomerRental(['fulfilment_source' => 'in_house']);
        $inHouseAsset = $this->attachRentedAssetToRental($inHouseRental);
        $vendorRental = $this->makeDirectCustomerRental(['fulfilment_source' => 'vendor_supplied']);
        $vendorAsset = $this->attachRentedAssetToRental($vendorRental);

        app(\App\Services\Deliveries\DeliveryWorkflowService::class)
            ->finalizePickupCompletionForRental($this->organizationId, $inHouseRental->fresh(), now()->toDateTimeString(), null);
        app(\App\Services\Deliveries\DeliveryWorkflowService::class)
            ->finalizePickupCompletionForRental($this->organizationId, $vendorRental->fresh(), now()->toDateTimeString(), null);

        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $inHouseAsset->fresh()->asset_status);
        $this->assertSame(Asset::STATUS_RENTED, $vendorAsset->fresh()->asset_status);
        $this->assertSame('returned', $vendorRental->fresh()->status);
    }

    public function test_dashboard_shows_renewal_center_summary_cards(): void
    {
        $this->makeDirectCustomerRental(['end_date' => now()->toDateString()]);
        $this->makeDirectCustomerRental(['end_date' => now()->addDays(4)->toDateString()]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Renewal Center')
            ->assertSeeText('Renewals Due')
            ->assertSeeText('Pickups Pending');
    }

    private function makeDirectCustomerRental(array $overrides = []): Rental
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Direct Renewal Customer ' . uniqid(),
            'phone' => '990000' . random_int(1000, 9999),
            'address' => '12 Renewal Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ]);

        $product = $this->makeRentalProduct();

        $rental = Rental::create(array_merge([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
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
            'scheduled_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        return $rental->fresh(['customer', 'product', 'deliveryRecord']);
    }

    private function makeRentalProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Renewal Product ' . uniqid(),
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 350,
            'rental_price' => 350,
            'sale_price' => 0,
            'available_quantity' => 15,
            'total_quantity' => 15,
        ]);
    }

    private function attachRentedAssetToRental(Rental $rental): Asset
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Return Warehouse ' . uniqid(),
            'code' => 'RW-' . random_int(100, 999),
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $rental->product_id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Asset ' . uniqid(),
            'serial_number' => 'RET-' . uniqid(),
            'barcode_value' => 'RET-' . uniqid(),
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_RENTED,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
        ]);

        RentalAsset::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subDays(2),
        ]);

        return $asset;
    }

    private function makeBusinessPartnerContext(): array
    {
        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Portea Tie-up',
            'contact_person' => 'Renewal Desk',
            'phone' => '+919900001010',
            'whatsapp' => '+919900001010',
            'email' => 'renewals@example.test',
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
            'status' => 'active',
            'delivery_notes' => 'Second floor service entry',
        ]);

        return [$partner, $client];
    }
}
