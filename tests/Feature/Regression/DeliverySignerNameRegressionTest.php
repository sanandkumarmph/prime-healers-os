<?php

namespace Tests\Feature\Regression;

use App\Models\{Delivery, DeliveryProof, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Event, Storage};
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class DeliverySignerNameRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['proof.storage_disk' => 'local']);
        $this->operator = TestData::user(null, ['role' => User::ROLE_DELIVERY]);
        $this->actingAs($this->operator);
    }

    private function task(string $type = 'delivery'): Delivery
    {
        return Delivery::create([
            'organization_id' => $this->operator->organization_id,
            'type' => $type,
            'status' => 'in_progress',
            'assigned_user_id' => $this->operator->id,
            'assignment_type' => 'delivery_team',
            'scheduled_at' => now(),
        ]);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(240, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 20, 30, 40);
        imageline($image, 20, 55, 95, 20, $ink);
        imageline($image, 95, 20, 130, 60, $ink);
        imageline($image, 130, 60, 215, 25, $ink);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private function completion(Delivery $task, array $overrides = []): array
    {
        $data = [
            'workflow_capture_form' => 1,
            'workflow_submission_token' => (string) Str::uuid(),
            'location_missing_reason' => 'GPS unavailable inside the building.',
            'completion_confirmed' => 1,
            'signature_data' => $this->signature(),
            'signature_signer_name' => '  Asha Devi  ',
            'proof_notes' => 'Synthetic completion evidence.',
        ];
        if ($task->type === 'pickup') {
            $data['pickup_device_photos'] = [UploadedFile::fake()->image('pickup.jpg', 60, 40)];
            $data['damage_reported'] = 0;
        } else {
            $data['delivery_device_photos'] = [UploadedFile::fake()->image('device.jpg', 60, 40)];
            $data['premises_photo'] = UploadedFile::fake()->image('premises.jpg', 60, 40);
        }

        return array_replace($data, $overrides);
    }

    private function exportFixture(string $filename, string $html): void
    {
        if ($directory = getenv('PHOS_ACCESS_PREVIEW_DIR')) {
            if (! is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $html);
        }
    }

    private function exportHistoryFixture(string $filename, Delivery $task): void
    {
        if (getenv('PHOS_ACCESS_PREVIEW_DIR')) {
            // Consume transient completion feedback before exporting the stable history page.
            $this->get(route('deliveries.show', $task))->assertOk();
            $response = $this->get(route('deliveries.show', $task))->assertOk();
            $this->exportFixture($filename, $response->getContent());
        }
    }

    public function test_signature_capture_form_and_review_preserve_named_acknowledgement_controls(): void
    {
        foreach (['delivery', 'pickup'] as $type) {
            $task = $this->task($type);
            $response = $this->get(route('deliveries.show', $task))->assertOk()
                ->assertSee('name="signature_signer_name"', false)
                ->assertSee('maxlength="120"', false)
                ->assertSee('data-review-signature-description', false)
                ->assertSeeText('Name of person signing')
                ->assertSee('name="signature_unavailable_reason"', false);
            $this->exportFixture($type . '-signature.html', $response->getContent());
        }
    }

    public function test_delivery_and_pickup_save_trimmed_signer_with_the_private_signature_proof(): void
    {
        foreach (['delivery', 'pickup'] as $type) {
            $task = $this->task($type);
            $this->from(route('deliveries.show', $task))
                ->put(route('deliveries.complete', $task), $this->completion($task))
                ->assertSessionHasNoErrors();

            $this->assertSame('completed', $task->fresh()->status);
            $proof = $task->proofs()->where('proof_type', DeliveryProof::TYPE_SIGNATURE)->sole();
            $this->assertSame('Asha Devi', $proof->meta['signer_name']);
            $this->assertSame('Asha Devi', $proof->signer_name);
            $this->assertSame('Synthetic completion evidence.', $proof->meta['proof_notes']);
            $this->assertSame($type, $proof->workflow_stage);
            $this->assertSame($this->operator->id, $proof->created_by_user_id);
            $this->assertStringNotContainsString('Asha', $proof->file_path);
            Storage::disk('local')->assertExists($proof->file_path);
            $response = $this->get(route('deliveries.show', $task))->assertOk()->assertSeeText('Signed by:')->assertSeeText('Asha Devi');
            $this->exportHistoryFixture($type . '-signature-history.html', $task);
        }
    }

    public function test_missing_whitespace_long_and_non_string_signer_are_rejected_without_partial_evidence(): void
    {
        foreach ([null, '   ', str_repeat('a', 121), ['Asha Devi']] as $name) {
            $task = $this->task();
            $data = $this->completion($task, ['signature_signer_name' => $name]);
            $this->put(route('deliveries.complete', $task), $data)->assertSessionHasErrors('signature_signer_name');
            $this->assertSame('in_progress', $task->fresh()->status);
            $this->assertSame(0, $task->proofs()->count());
        }
        $this->assertDatabaseCount('delivery_workflow_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unavailable_signature_reason_does_not_require_or_claim_a_signer_name(): void
    {
        $task = $this->task('pickup');
        $data = $this->completion($task, [
            'signature_data' => '',
            'signature_signer_name' => null,
            'signature_unavailable_reason' => 'Customer could not sign because of limited mobility.',
        ]);
        $this->put(route('deliveries.complete', $task), $data)->assertSessionHasNoErrors();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertFalse($task->proofs()->where('proof_type', DeliveryProof::TYPE_SIGNATURE)->exists());
        $reason = $task->proofs()->where('proof_type', DeliveryProof::TYPE_ACKNOWLEDGEMENT_REASON)->sole();
        $this->assertSame($data['signature_unavailable_reason'], $reason->notes);
        $this->assertArrayNotHasKey('signer_name', $reason->meta);
        $this->exportHistoryFixture('pickup-signature-unavailable-history.html', $task);
    }

    public function test_retry_is_idempotent_and_a_changed_signer_name_cannot_rewrite_saved_evidence(): void
    {
        $task = $this->task();
        $data = $this->completion($task);
        $device = $data['delivery_device_photos'][0]->getContent();
        $premises = $data['premises_photo']->getContent();
        $payload = function (string $name) use ($data, $device, $premises): array {
            return array_replace($data, [
                'signature_signer_name' => $name,
                'delivery_device_photos' => [UploadedFile::fake()->createWithContent('device.jpg', $device)],
                'premises_photo' => UploadedFile::fake()->createWithContent('premises.jpg', $premises),
            ]);
        };
        $this->put(route('deliveries.complete', $task), $payload('Asha Devi'))->assertSessionHasNoErrors();
        $files = Storage::disk('local')->allFiles();
        $proofCount = $task->proofs()->count();
        $this->put(route('deliveries.complete', $task), $payload('Asha Devi'))->assertSessionHasNoErrors();
        $this->put(route('deliveries.complete', $task), $payload('Different Person'))->assertSessionHasErrors('workflow_submission_token');
        $this->assertDatabaseCount('delivery_workflow_submissions', 1);
        $this->assertSame($proofCount, $task->proofs()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame('Asha Devi', $task->proofs()->where('proof_type', DeliveryProof::TYPE_SIGNATURE)->sole()->signer_name);
    }

    public function test_signature_proof_failure_rolls_back_name_status_and_all_new_private_files(): void
    {
        $task = $this->task();
        $event = 'eloquent.creating: ' . DeliveryProof::class;
        Event::listen($event, function (DeliveryProof $proof) {
            if ($proof->proof_type === DeliveryProof::TYPE_SIGNATURE) {
                $this->assertSame('Asha Devi', $proof->meta['signer_name']);
                throw new \RuntimeException('Synthetic proof write failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->put(route('deliveries.complete', $task), $this->completion($task));
            $this->fail('A failed proof write must not complete the task.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic proof write failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertDatabaseCount('delivery_workflow_submissions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_signer_is_escaped_in_authorized_history_and_legacy_unnamed_proofs_still_render(): void
    {
        $task = $this->task();
        $name = 'Asha <script>alert(1)</script>';
        $this->put(route('deliveries.complete', $task), $this->completion($task, ['signature_signer_name' => $name]))->assertSessionHasNoErrors();
        $proof = $task->proofs()->where('proof_type', DeliveryProof::TYPE_SIGNATURE)->sole();
        $this->get(route('deliveries.show', $task))->assertOk()->assertSee(e($name), false)->assertDontSee($name, false);
        $this->exportHistoryFixture('delivery-signature-escaped-history.html', $task);
        $this->get(route('deliveries.proofs.view', [$task, $proof]))->assertOk();

        $proof->update(['meta' => null]);
        $this->assertNull($proof->fresh()->signer_name);
        $this->get(route('deliveries.show', $task))->assertOk()->assertSeeText('Name not recorded (legacy proof)');
        $this->exportHistoryFixture('delivery-signature-legacy-history.html', $task);

        $outsider = TestData::user(null, ['role' => User::ROLE_DELIVERY]);
        $this->actingAs($outsider)->get(route('deliveries.show', $task))->assertForbidden();
        $this->get(route('deliveries.proofs.view', [$task, $proof]))->assertForbidden();
    }

    public function test_named_private_proofs_follow_task_assignment_scope_and_managers_keep_access(): void
    {
        $task = $this->task();
        $this->put(route('deliveries.complete', $task), $this->completion($task))->assertSessionHasNoErrors();
        $proof = $task->proofs()->where('proof_type', DeliveryProof::TYPE_SIGNATURE)->sole();
        $proofUrl = route('deliveries.proofs.view', [$task, $proof]);
        $response = $this->get($proofUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        foreach ([User::ROLE_DELIVERY, User::ROLE_THIRD_PARTY] as $role) {
            $colleague = TestData::user($this->operator->organization, ['role' => $role]);
            $this->actingAs($colleague)->get(route('deliveries.show', $task))
                ->assertOk()->assertDontSeeText('Asha Devi')->assertDontSee($proofUrl, false);
            $this->get($proofUrl)->assertForbidden();
        }

        $manager = TestData::user($this->operator->organization);
        $this->actingAs($manager)->get(route('deliveries.show', $task))->assertOk()->assertSeeText('Asha Devi');
        $this->get($proofUrl)->assertOk();
    }
}
