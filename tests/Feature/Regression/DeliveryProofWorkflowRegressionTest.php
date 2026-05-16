<?php

namespace Tests\Feature\Regression;

use App\Models\Delivery;
use App\Models\DeliveryProof;
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

        $startResponse->assertRedirect(route('deliveries.show', $delivery));
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

        $filePaths = DeliveryProof::query()
            ->where('delivery_id', $delivery->id)
            ->whereNotNull('file_path')
            ->pluck('file_path');

        foreach ($filePaths as $path) {
            $this->assertTrue(Storage::disk('local')->exists($path));
        }
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
            ->assertDontSeeText('Start Checklist')
            ->assertDontSeeText('Completion Checklist');
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
            ->get(route('deliveries.proofs.view', [$delivery, $proof]))
            ->assertOk();

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

        $response = $this->get(route('deliveries.index'));
        $editHref = route('deliveries.edit', $ownPendingDelivery, false);
        $deleteHref = route('deliveries.destroy', $ownPendingDelivery, false);

        $response->assertOk();
        $response->assertSeeText('View Proof');
        $response->assertDontSee($editHref, false);
        $response->assertDontSee($deleteHref, false);
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
