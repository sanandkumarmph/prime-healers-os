<?php

namespace Tests\Feature\Regression;

use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class DeliveryProofWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'proof.storage_disk' => 'local',
            'proof.image_max_kb' => 100,
            'proof.image_target_kb' => 50,
            'proof.image_max_dimension' => 1024,
        ]);
    }

    public function test_delivery_team_can_start_and_complete_delivery_with_private_proof_capture(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'pending');

        $startResponse = $this->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.in_progress', $delivery), [
                'workflow_capture_form' => '1',
                'location_latitude' => '12.971599',
                'location_longitude' => '77.594566',
                'location_accuracy' => '18.4',
                'location_captured_at' => now()->toIso8601String(),
            ]);

        $startResponse->assertRedirect(route('deliveries.show', $delivery) . '#workflow-proof-section');
        $this->assertSame('in_progress', $delivery->fresh()->status);
        $this->assertDatabaseHas('delivery_proofs', [
            'delivery_id' => $delivery->id,
            'proof_type' => DeliveryProof::TYPE_LOCATION,
            'capture_moment' => DeliveryProof::MOMENT_START,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'created_by_user_id' => $deliveryUser->id,
        ]);

        $completeResponse = $this->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.complete', $delivery), [
                'workflow_capture_form' => '1',
                'delivery_device_photos' => [
                    $this->fakeImageUpload('device-1.jpg', 45),
                    $this->fakeImageUpload('device-2.jpg', 42),
                ],
                'premises_photo' => $this->fakeImageUpload('premises.jpg', 48),
                'signature_data' => $this->signatureDataUrl(),
                'proof_notes' => 'Delivered in working condition.',
                'location_latitude' => '12.971700',
                'location_longitude' => '77.594700',
                'location_accuracy' => '12.0',
                'location_captured_at' => now()->toIso8601String(),
                'completion_confirmed' => '1',
            ]);

        $completeResponse->assertRedirect(route('deliveries.show', $delivery));
        $this->assertSame('completed', $delivery->fresh()->status);

        $this->assertDatabaseHas('delivery_proofs', [
            'delivery_id' => $delivery->id,
            'proof_type' => DeliveryProof::TYPE_SIGNATURE,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
        ]);

        $this->assertGreaterThanOrEqual(5, DeliveryProof::query()->where('delivery_id', $delivery->id)->count());
        $storedFiles = Storage::disk('local')->allFiles('delivery-proofs/org-' . $organization->id . '/delivery-' . $delivery->id);
        $this->assertGreaterThanOrEqual(4, count($storedFiles));
    }

    public function test_delivery_detail_shows_explicit_start_and_complete_ctas_instead_of_checklist_copy(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $pendingDelivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'pending');
        $inProgressPickup = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'pickup', 'in_progress');

        $this->get(route('deliveries.show', $pendingDelivery))
            ->assertOk()
            ->assertSeeText('Start Delivery')
            ->assertDontSeeText('Start Checklist');

        $this->get(route('deliveries.show', $inProgressPickup))
            ->assertOk()
            ->assertSeeText('Complete Pickup')
            ->assertDontSeeText('Completion Checklist');
    }

    public function test_delivery_detail_keeps_unable_to_complete_section_collapsed_by_default(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'pickup', 'pending');

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Unable to complete')
            ->assertSee('id="delivery-cancellation-section"', false)
            ->assertDontSee('id="delivery-cancellation-section" class="proof-history-shell" open', false)
            ->assertDontSeeText('Cancel Task');
    }

    public function test_delivery_detail_shows_location_clear_recapture_controls_and_mobile_compression_guidance(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'in_progress');

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('data-capture-location', false)
            ->assertSee('data-clear-location', false)
            ->assertSee('data-recapture-location', false)
            ->assertSeeText('Clear Location')
            ->assertSeeText('Re-capture Location')
            ->assertSee('Image is still too large. Try taking a closer photo with less background, or use retake/choose another photo.', false);
    }

    public function test_sale_delivery_signature_copy_does_not_mention_returning_the_product(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Delivery Customer',
            'phone' => '9000000611',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Delivery Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 0,
            'rental_price' => 0,
            'sale_price' => 1200,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
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
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'sale_id' => $sale->id,
            'type' => 'delivery',
            'status' => 'in_progress',
            'assigned_user_id' => $deliveryUser->id,
            'scheduled_at' => now(),
            'notes' => 'Sale item delivery signature wording test',
        ]);

        $this->actingAs($deliveryUser)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('I confirm that the product(s) have been received in good condition and working order.')
            ->assertDontSeeText('I agree to return the product(s) in the same condition, subject to normal use.');
    }

    public function test_delivery_team_can_operate_third_party_delivery_without_internal_owner(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'type' => 'delivery',
            'status' => 'pending',
            'assignment_type' => 'third_party',
            'assigned_user_id' => null,
            'third_party_name' => 'External Runner',
            'scheduled_at' => now(),
            'notes' => 'Third-party assignment with no internal owner',
        ]);

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Start Delivery')
            ->assertDontSeeText('only the assigned workflow owner can capture start or completion proof');

        $this->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.in_progress', $delivery), [
                'workflow_capture_form' => '1',
                'location_missing_reason' => 'Third-party handoff location recorded manually.',
            ])
            ->assertRedirect(route('deliveries.show', $delivery) . '#workflow-proof-section');

        $this->assertSame('in_progress', $delivery->fresh()->status);

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Complete Delivery')
            ->assertDontSeeText('only the assigned workflow owner can capture start or completion proof');
    }

    public function test_completed_delivery_detail_shows_view_proof_when_history_exists(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'completed');
        Storage::disk('local')->put('delivery-proofs/completed-proof.jpg', 'proof-bytes');

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_DELIVERED_DEVICE,
            'file_path' => 'delivery-proofs/completed-proof.jpg',
            'original_name' => 'completed-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('proof-bytes'),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('View Proof')
            ->assertSee('id="delivery-proof-history"', false)
            ->assertDontSee('id="delivery-proof-history" class="proof-history-shell" open', false)
            ->assertDontSeeText('Continue Delivery')
            ->assertDontSeeText('Start Checklist')
            ->assertDontSeeText('Completion Checklist');
    }

    public function test_delivery_workflow_review_step_includes_mobile_safe_single_column_layout_hooks(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'in_progress');

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('class="workflow-review-summary-grid"', false)
            ->assertSee('grid-template-columns:minmax(0, 1fr) !important;', false)
            ->assertSee('overflow-wrap:anywhere;', false)
            ->assertSee('word-break:break-word;', false)
            ->assertSee('Review &amp; Complete', false)
            ->assertSeeText('I confirm the above details are correct and proof has been captured.');
    }

    public function test_delivery_proof_history_renders_all_expected_proof_items_and_mobile_more_action_hook(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'completed');

        Storage::disk('local')->put('delivery-proofs/product-proof.jpg', 'product-proof');
        Storage::disk('local')->put('delivery-proofs/site-proof.jpg', 'site-proof');
        Storage::disk('local')->put('delivery-proofs/extra-proof.jpg', 'extra-proof');
        Storage::disk('local')->put('delivery-proofs/signature-proof.png', 'signature-proof');
        Storage::disk('local')->put('delivery-proofs/payment-proof.jpg', 'payment-proof');

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_START,
            'proof_type' => DeliveryProof::TYPE_LOCATION,
            'latitude' => 12.971598,
            'longitude' => 77.594566,
            'accuracy' => 8.4,
            'captured_at' => now()->subMinutes(20),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_DELIVERED_DEVICE,
            'file_path' => 'delivery-proofs/product-proof.jpg',
            'original_name' => 'product-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('product-proof'),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_PREMISES,
            'file_path' => 'delivery-proofs/site-proof.jpg',
            'original_name' => 'site-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('site-proof'),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_DELIVERED_DEVICE,
            'file_path' => 'delivery-proofs/extra-proof.jpg',
            'original_name' => 'extra-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('extra-proof'),
            'meta' => ['is_extra' => true],
            'created_by_user_id' => $deliveryUser->id,
        ]);

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_SIGNATURE,
            'file_path' => 'delivery-proofs/signature-proof.png',
            'original_name' => 'signature-proof.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen('signature-proof'),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_COLLECTION,
            'file_path' => 'delivery-proofs/payment-proof.jpg',
            'original_name' => 'payment-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('payment-proof'),
            'meta' => ['payment_mode' => 'UPI', 'amount_collected' => 1500],
            'created_by_user_id' => $deliveryUser->id,
        ]);

        $response = $this->get(route('deliveries.show', $delivery));

        $response->assertOk()
            ->assertSee(route('deliveries.show', $delivery) . '#delivery-proof-history', false)
            ->assertSee('data-open-proof-history', false)
            ->assertSeeText('6 items')
            ->assertSeeText('Location Proof')
            ->assertSeeText('Product Photo')
            ->assertSeeText('Delivery Photo')
            ->assertSeeText('Extra Photo')
            ->assertSeeText('Customer Signature')
            ->assertSeeText('Payment Proof')
            ->assertSeeText('Latitude:')
            ->assertSeeText('Longitude:')
            ->assertSeeText('Accuracy:')
            ->assertSeeText('Open Map')
            ->assertSeeText('View Full Size');
    }

    public function test_delivery_detail_uses_compact_guided_workflow_copy_for_field_users(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'in_progress');

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Capture Photos')
            ->assertSeeText('Capture GPS')
            ->assertSeeText('Customer Signature')
            ->assertSeeText('Capture GPS or add a reason.')
            ->assertSeeText('Required steps only.')
            ->assertSeeText('Finish each step before completion.');
    }

    public function test_delivery_mobile_workflow_renders_signature_preview_photo_preview_and_single_column_review_hooks(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'in_progress');

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee('data-signature-preview-wrap', false)
            ->assertSee('data-signature-preview-image', false)
            ->assertSee('data-preview-target="delivery-device-preview"', false)
            ->assertSee('data-preview-target="premises-preview"', false)
            ->assertSee('data-review-summary="location"', false)
            ->assertSee('data-review-summary="photos"', false)
            ->assertSee('data-review-summary="signature"', false)
            ->assertSeeText('Review and finish');
    }

    public function test_rental_backed_delivery_detail_shows_completed_item_progress_copy_instead_of_generic_pending_copy(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $deliveredTask = $this->makeRentalBackedTask($organization->id, $deliveryUser->id, 'delivery', [
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
        ]);

        $pickupTask = $this->makeRentalBackedTask($organization->id, $deliveryUser->id, 'pickup', [
            'delivered_quantity' => 1,
            'returned_quantity' => 1,
        ]);

        $this->get(route('deliveries.show', $deliveredTask))
            ->assertOk()
            ->assertSeeText('Delivery completed')
            ->assertDontSeeText('No action pending.')
            ->assertDontSeeText('Delivery Pending');

        $this->get(route('deliveries.show', $pickupTask))
            ->assertOk()
            ->assertSeeText('Pickup completed')
            ->assertDontSeeText('No action pending.')
            ->assertDontSeeText('Pickup Pending');
    }

    public function test_completed_delivery_detail_derives_delivered_progress_for_stale_legacy_rental_items(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeRentalBackedTask($organization->id, $deliveryUser->id, 'delivery', [
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
        ]);

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Delivered')
            ->assertSeeText('1')
            ->assertSeeText('Pending')
            ->assertSeeText('0')
            ->assertSeeText('Delivery completed')
            ->assertDontSeeText('Delivery Pending');
    }

    public function test_completing_a_rental_backed_delivery_syncs_delivered_item_quantities(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Delivered Customer',
            'phone' => '9000000211',
            'address' => '14 Delivery Street',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Delivered Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 100,
            'rental_price' => 100,
            'sale_price' => 0,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'rental_amount' => 100,
            'deposit_amount' => 0,
            'status' => 'active',
        ]);

        $item = RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 100,
            'gst_rate' => 0,
            'gst_mode' => 'exclusive',
            'tax_type' => 'cgst_sgst',
            'taxable_amount' => 100,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'line_total' => 100,
        ]);

        $delivery = Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'in_progress',
            'assigned_user_id' => $deliveryUser->id,
            'scheduled_at' => now(),
            'notes' => 'Complete delivery quantity sync test',
        ]);

        $response = $this->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.complete', $delivery), [
                'workflow_capture_form' => '1',
                'confirm_partial' => '1',
                'delivery_device_photos' => [
                    $this->fakeImageUpload('device-complete.jpg', 45),
                ],
                'premises_photo' => $this->fakeImageUpload('premises-complete.jpg', 48),
                'signature_data' => $this->signatureDataUrl(),
                'proof_notes' => 'Completed in the field.',
                'location_missing_reason' => 'GPS unavailable indoors.',
                'completion_confirmed' => '1',
            ]);

        $response->assertRedirect(route('deliveries.show', $delivery));
        $this->assertSame('completed', $delivery->fresh()->status);
        $this->assertSame(1, $item->fresh()->delivered_quantity);
        $this->assertSame(0, $item->fresh()->pending_delivery_quantity);

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSeeText('Delivery completed')
            ->assertDontSeeText('Delivery Pending');
    }

    public function test_delivery_completion_requires_photos_signature_and_location_or_reason(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'in_progress');

        $response = $this->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.complete', $delivery), [
                'workflow_capture_form' => '1',
            ]);

        $response->assertRedirect(route('deliveries.show', $delivery));
        $response->assertSessionHasErrors([
            'delivery_device_photos',
            'premises_photo',
            'signature_data',
            'location_missing_reason',
        ]);

        $this->followRedirects($response)
            ->assertSeeText('Please complete the required proof fields marked below before continuing this delivery task.');
    }

    public function test_pickup_damage_report_requires_notes_and_photo_and_rejects_premises_photo(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $pickup = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'pickup', 'in_progress');

        $response = $this->from(route('deliveries.show', $pickup))
            ->put(route('deliveries.complete', $pickup), [
                'workflow_capture_form' => '1',
                'pickup_device_photos' => [
                    $this->fakeImageUpload('pickup.jpg', 40),
                ],
                'premises_photo' => $this->fakeImageUpload('not-allowed.jpg', 40),
                'damage_reported' => '1',
                'signature_data' => $this->signatureDataUrl(),
                'location_missing_reason' => 'GPS signal blocked inside the building.',
            ]);

        $response->assertRedirect(route('deliveries.show', $pickup));
        $response->assertSessionHasErrors([
            'damage_notes',
            'damage_photos',
            'premises_photo',
        ]);
    }

    public function test_oversized_images_are_rejected_by_server_validation(): void
    {
        config(['proof.image_max_kb' => 10]);

        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'in_progress');

        $response = $this->from(route('deliveries.show', $delivery))
            ->put(route('deliveries.complete', $delivery), [
                'workflow_capture_form' => '1',
                'delivery_device_photos' => [
                    $this->fakeImageUpload('huge-device.jpg', 25),
                ],
                'premises_photo' => $this->fakeImageUpload('huge-premises.jpg', 25),
                'signature_data' => $this->signatureDataUrl(),
                'location_missing_reason' => 'GPS blocked',
            ]);

        $response->assertRedirect(route('deliveries.show', $delivery));
        $response->assertSessionHasErrors([
            'delivery_device_photos.0',
            'premises_photo',
        ]);
    }

    public function test_admin_can_view_private_proof_and_unauthorized_user_cannot(): void
    {
        $organization = TestData::organization();
        $admin = TestData::user($organization);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $unauthorized = TestData::user($organization, [
            'role' => User::ROLE_SALES,
        ]);

        $delivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'completed');
        Storage::disk('local')->put('delivery-proofs/test-proof.jpg', 'proof-bytes');

        $proof = DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $delivery->id,
            'workflow_stage' => DeliveryProof::STAGE_DELIVERY,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_DELIVERED_DEVICE,
            'file_path' => 'delivery-proofs/test-proof.jpg',
            'original_name' => 'test-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('proof-bytes'),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        $this->actingAs($admin)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertSee(route('deliveries.proofs.view', [$delivery, $proof]), false);

        $this->actingAs($unauthorized)
            ->get(route('deliveries.proofs.view', [$delivery, $proof]))
            ->assertRedirect($unauthorized->defaultRedirectPath());
    }

    public function test_delivery_team_cannot_upload_proof_for_other_organization_task(): void
    {
        $organization = TestData::organization(['name' => 'Org A']);
        $otherOrganization = TestData::organization(['name' => 'Org B']);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $otherDeliveryUser = TestData::user($otherOrganization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $delivery = $this->makeDeliveryTask($otherOrganization->id, $otherDeliveryUser->id, 'delivery', 'in_progress');

        $this->put(route('deliveries.complete', $delivery), [
                'workflow_capture_form' => '1',
                'delivery_device_photos' => [
                    $this->fakeImageUpload('device.jpg', 40),
                ],
                'premises_photo' => $this->fakeImageUpload('premises.jpg', 40),
                'signature_data' => $this->signatureDataUrl(),
                'location_missing_reason' => 'GPS unavailable',
            ])->assertForbidden();
    }

    public function test_taskboard_menu_and_primary_actions_show_only_valid_delivery_team_workflow_options(): void
    {
        $organization = TestData::organization();
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $otherDeliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->actingAs($deliveryUser);

        $ownPendingDelivery = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'delivery', 'pending');
        $otherPendingDelivery = $this->makeDeliveryTask($organization->id, $otherDeliveryUser->id, 'delivery', 'pending');
        $completedPickup = $this->makeDeliveryTask($organization->id, $deliveryUser->id, 'pickup', 'completed');

        Storage::disk('local')->put('delivery-proofs/menu-proof.jpg', 'proof-bytes');
        DeliveryProof::create([
            'organization_id' => $organization->id,
            'delivery_id' => $completedPickup->id,
            'workflow_stage' => DeliveryProof::STAGE_PICKUP,
            'capture_moment' => DeliveryProof::MOMENT_COMPLETE,
            'proof_type' => DeliveryProof::TYPE_PICKED_UP_DEVICE,
            'file_path' => 'delivery-proofs/menu-proof.jpg',
            'original_name' => 'menu-proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen('proof-bytes'),
            'created_by_user_id' => $deliveryUser->id,
        ]);

        $response = $this->get(route('deliveries.index', [
            'tab' => 'completed',
            'status' => 'completed',
        ]));
        $response->assertOk();
        $response->assertSeeText('View Proof');
        $response->assertDontSeeText('Edit Assignment');
        $response->assertDontSeeText('Delete this task record?');
    }

    private function makeDeliveryTask(int $organizationId, int $assignedUserId, string $type = 'delivery', string $status = 'pending'): Delivery
    {
        return Delivery::create([
            'organization_id' => $organizationId,
            'type' => $type,
            'status' => $status,
            'assigned_user_id' => $assignedUserId,
            'scheduled_at' => now(),
            'notes' => 'Proof workflow test task',
        ]);
    }

    private function makeRentalBackedTask(int $organizationId, int $assignedUserId, string $type, array $itemOverrides = []): Delivery
    {
        $customer = Customer::create([
            'organization_id' => $organizationId,
            'name' => ucfirst($type) . ' Customer',
            'phone' => '9000000111',
            'address' => '12 Field Street',
            'city' => 'Bengaluru',
        ]);

        $product = Product::create([
            'organization_id' => $organizationId,
            'name' => ucfirst($type) . ' Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 100,
            'rental_price' => 100,
            'sale_price' => 0,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        $rentalStatus = $type === 'pickup' ? 'returned' : 'active';

        $rental = Rental::create([
            'organization_id' => $organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'rental_amount' => 100,
            'deposit_amount' => 0,
            'status' => $rentalStatus,
        ]);

        RentalItem::create(array_merge([
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 100,
            'gst_rate' => 0,
            'gst_mode' => 'exclusive',
            'tax_type' => 'cgst_sgst',
            'taxable_amount' => 100,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'line_total' => 100,
        ], $itemOverrides));

        return Delivery::create([
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'type' => $type,
            'status' => 'completed',
            'assigned_user_id' => $assignedUserId,
            'scheduled_at' => now(),
            'notes' => ucfirst($type) . ' rental progress test task',
        ]);
    }

    private function signatureDataUrl(): string
    {
        return 'data:image/png;base64,' . base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAECAIAAADJUWIXAAAAGElEQVQImWNgoBpgYGBg+A8jGEmBgYGBAQAAegQF4g1r0cQAAAAASUVORK5CYII=',
            true
        ));
    }

    private function fakeImageUpload(string $name, int $sizeKb): UploadedFile
    {
        $tinyPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO0pJ2sAAAAASUVORK5CYII=',
            true
        );

        $targetBytes = max($sizeKb * 1024, strlen($tinyPng));
        $padding = max($targetBytes - strlen($tinyPng), 0);

        return UploadedFile::fake()->createWithContent($name, $tinyPng . str_repeat(' ', $padding));
    }
}
