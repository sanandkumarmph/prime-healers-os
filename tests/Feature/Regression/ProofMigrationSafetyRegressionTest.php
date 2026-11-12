<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\TestData;
use Tests\TestCase;

class ProofMigrationSafetyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_failed_private_write_keeps_original_bytes_and_database_reference(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        $private = Storage::disk('local');
        $mock = Mockery::mock($private)->makePartial();
        $mock->shouldReceive('put')->once()->andReturnFalse();
        Storage::set('local', $mock);
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        $this->assertSame('original-proof-bytes', Storage::disk('public')->get($customer->id_proof_file_path));
        $private->assertMissing($this->target($customer));
        $this->assertStringContainsString('INCOMPLETE', Artisan::output());
    }

    public function test_partial_or_corrupt_private_copy_is_not_activated_or_allowed_to_delete_source(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        $private = Storage::disk('local');
        $mock = Mockery::mock($private)->makePartial();
        $mock->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($private) {
            $private->put($path, 'partial-copy');

            return true;
        });
        Storage::set('local', $mock);
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        $this->assertSame('original-proof-bytes', Storage::disk('public')->get($customer->id_proof_file_path));
        $private->assertMissing($this->target($customer));
    }

    public function test_failed_public_cleanup_leaves_a_verified_private_proof_and_reports_incomplete(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        $public = Storage::disk('public');
        $mock = Mockery::mock($public)->makePartial();
        $mock->shouldReceive('delete')->once()->with($customer->id_proof_file_path)->andReturnFalse();
        Storage::set('public', $mock);
        $this->travel(2)->days();
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $this->assertSame($this->target($customer), $customer->fresh()->id_proof_file_path);
        $this->assertSame('original-proof-bytes', Storage::disk('local')->get($this->target($customer)));
        $this->assertSame('original-proof-bytes', $public->get($before['id_proof_file_path']));
        $this->assertSame($before, array_replace($customer->fresh()->getRawOriginal(), ['id_proof_file_path' => $before['id_proof_file_path']]));
        $this->assertStringContainsString('Incomplete: 1', Artisan::output());
        $manifest = json_decode(Storage::disk('local')->get('.proof-migration/pending-cleanup.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($before['id_proof_file_path'], array_values($manifest['entries'])[0]['public_path']);
        $this->assertSame($customer->id, array_values($manifest['entries'])[0]['copies'][0]['customer_id']);
        $this->assertSame($this->target($customer), array_values($manifest['entries'])[0]['copies'][0]['private_path']);
        $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--summary' => true]));
        $audit = Artisan::output();
        $this->assertStringContainsString('PROOF_AUDIT_PUBLIC_REFERENCED=0', $audit);
        $this->assertStringContainsString('PROOF_AUDIT_PENDING_CLEANUP=1', $audit);
        $this->assertStringContainsString('PROOF_AUDIT_COMPLETE=false', $audit);
        Storage::set('public', $public);
        Storage::disk('public')->put('legacy/unreferenced.pdf', 'do-not-delete');
        $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $public->assertMissing($before['id_proof_file_path']);
        $public->assertExists('legacy/unreferenced.pdf');
        $this->assertSame('original-proof-bytes', Storage::disk('local')->get($this->target($customer)));
        $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--summary' => true]));
        $audit = Artisan::output();
        $this->assertStringContainsString('PROOF_AUDIT_PENDING_CLEANUP=0', $audit);
        $this->assertStringContainsString('PROOF_AUDIT_COMPLETE=true', $audit);
        $this->travelBack();
    }

    public function test_private_retry_journal_is_verified_before_customer_metadata_is_updated(): void
    {
        $customer = $this->customer();
        $observed = false;
        $event = 'eloquent.updating: '.Customer::class;
        app('events')->listen($event, function ($updating) use ($customer, &$observed) {
            if (! $updating->is($customer)) {
                return;
            }
            $this->assertGreaterThan(0, DB::transactionLevel());
            $manifest = json_decode(Storage::disk('local')->get('.proof-migration/pending-cleanup.json'), true, flags: JSON_THROW_ON_ERROR);
            $copy = array_values($manifest['entries'])[0]['copies'][0];
            $this->assertSame($customer->id, $copy['customer_id']);
            $this->assertSame($this->target($customer), $copy['private_path']);
            $this->assertSame(hash('sha256', 'original-proof-bytes'), $copy['hash']);
            Storage::disk('public')->assertExists('legacy/proof.pdf');
            $observed = true;
        });
        try {
            $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
            $this->assertTrue($observed);
        } finally {
            app('events')->forget($event);
        }
    }

    public function test_retry_journal_write_failure_leaves_original_and_metadata_unchanged(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        $private = Storage::disk('local');
        $mock = Mockery::mock($private)->makePartial();
        $mock->shouldReceive('put')->andReturnUsing(function ($path, $contents, $options = []) use ($private) {
            return str_starts_with($path, '.proof-migration/manifest-') ? false : $private->put($path, $contents, $options);
        });
        Storage::set('local', $mock);
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        $this->assertSame('original-proof-bytes', Storage::disk('public')->get($customer->id_proof_file_path));
        $private->assertMissing('.proof-migration/pending-cleanup.json');
    }

    public function test_unverifiable_retry_journal_never_reports_a_fully_private_audit(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        Storage::disk('local')->put('.proof-migration/pending-cleanup.json', '{invalid-json');
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--summary' => true]));
        $audit = Artisan::output();
        $this->assertStringContainsString('PROOF_AUDIT_COMPLETE=false', $audit);
        $this->assertStringContainsString('PROOF_AUDIT_PENDING_CLEANUP=unknown', $audit);
        $this->assertStringNotContainsString($customer->id_proof_file_path, $audit);
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists($customer->id_proof_file_path);
    }

    public function test_conflicting_existing_private_target_is_never_overwritten(): void
    {
        $customer = $this->customer();
        Storage::disk('local')->put($this->target($customer), 'different-private-proof');
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $this->assertSame('legacy/proof.pdf', $customer->fresh()->id_proof_file_path);
        $this->assertSame('different-private-proof', Storage::disk('local')->get($this->target($customer)));
        $this->assertSame('original-proof-bytes', Storage::disk('public')->get('legacy/proof.pdf'));
    }

    public function test_same_path_private_copy_must_match_before_public_duplicate_can_be_removed(): void
    {
        $customer = $this->customer();
        Storage::disk('local')->put($customer->id_proof_file_path, 'different-private-proof');
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        $this->assertSame('original-proof-bytes', Storage::disk('public')->get($customer->id_proof_file_path));
        $this->assertSame('different-private-proof', Storage::disk('local')->get($customer->id_proof_file_path));
    }

    public function test_scoped_success_changes_only_proof_metadata_and_removes_only_verified_referenced_source(): void
    {
        $customer = $this->customer();
        $other = $this->customer('legacy/other.pdf');
        $payment = Payment::create(['organization_id' => $customer->organization_id, 'amount' => 777, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash']);
        $before = $customer->fresh()->getRawOriginal();
        $otherBefore = $other->fresh()->getRawOriginal();
        $paymentBefore = $payment->fresh()->getRawOriginal();
        Storage::disk('public')->put('legacy/unreferenced.pdf', 'leave-this-file-alone');
        $this->travel(2)->days();
        $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true, '--organization' => $customer->organization_id]));
        $this->assertSame($this->target($customer), $customer->fresh()->id_proof_file_path);
        $this->assertSame($before, array_replace($customer->fresh()->getRawOriginal(), ['id_proof_file_path' => $before['id_proof_file_path']]));
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        Storage::disk('public')->assertMissing($before['id_proof_file_path']);
        Storage::disk('public')->assertExists('legacy/other.pdf');
        Storage::disk('public')->assertExists('legacy/unreferenced.pdf');
        $this->assertSame(hash('sha256', 'original-proof-bytes'), hash('sha256', Storage::disk('local')->get($this->target($customer))));
        $this->travelBack();
    }

    public function test_summary_audit_is_counts_only_read_only_and_does_not_log_proof_paths(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        Log::spy();
        $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--summary' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('PROOF_AUDIT_READ_ONLY=true', $output);
        $this->assertStringContainsString('PROOF_AUDIT_PUBLIC_REFERENCED=1', $output);
        $this->assertStringContainsString('PROOF_AUDIT_MIGRATE_PUBLIC_TO_PRIVATE=1', $output);
        foreach ([$customer->name, $customer->id_proof_file_path, $this->target($customer)] as $privateText) {
            $this->assertStringNotContainsString($privateText, $output);
        }
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists($customer->id_proof_file_path);
        Storage::disk('local')->assertMissing($this->target($customer));
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_shared_public_source_is_retained_until_every_customer_has_a_verified_private_copy(): void
    {
        $first = $this->customer('legacy/shared.pdf');
        $second = $this->customer('legacy/shared.pdf');
        $this->assertSame(0, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]));
        foreach ([$first, $second] as $customer) {
            $this->assertSame($this->target($customer), $customer->fresh()->id_proof_file_path);
            $this->assertSame('original-proof-bytes', Storage::disk('local')->get($this->target($customer)));
        }
        Storage::disk('public')->assertMissing('legacy/shared.pdf');
        $this->assertStringContainsString('Removed verified public copies: 1', Artisan::output());
    }

    public function test_scoped_apply_keeps_a_shared_source_referenced_by_another_organization(): void
    {
        $first = $this->customer('legacy/shared.pdf');
        $second = $this->customer('legacy/shared.pdf');
        $secondBefore = $second->fresh()->getRawOriginal();
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true, '--organization' => $first->organization_id]));
        $this->assertSame($this->target($first), $first->fresh()->id_proof_file_path);
        $this->assertSame('original-proof-bytes', Storage::disk('local')->get($this->target($first)));
        $this->assertSame($secondBefore, $second->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists('legacy/shared.pdf');
        $this->assertStringContainsString('INCOMPLETE', Artisan::output());
    }

    public function test_storage_normalized_alias_still_counts_as_an_existing_customer_reference(): void
    {
        $first = $this->customer('legacy/shared.pdf');
        $second = $this->customer('legacy\\shared.pdf');
        $secondBefore = $second->fresh()->getRawOriginal();
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true, '--organization' => $first->organization_id]));
        $this->assertSame($this->target($first), $first->fresh()->id_proof_file_path);
        $this->assertSame($secondBefore, $second->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists('legacy/shared.pdf');
        $this->assertSame('original-proof-bytes', Storage::disk('public')->get($second->id_proof_file_path));
    }

    public function test_summary_and_apply_combination_is_rejected_before_any_mutation(): void
    {
        $customer = $this->customer();
        $before = $customer->fresh()->getRawOriginal();
        $this->assertSame(1, Artisan::call('rentnexis:migrate-proof-files', ['--summary' => true, '--apply' => true]));
        $this->assertSame($before, $customer->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists($customer->id_proof_file_path);
        Storage::disk('local')->assertMissing($this->target($customer));
    }

    private function customer(string $path = 'legacy/proof.pdf'): Customer
    {
        $organization = TestData::organization();
        Storage::disk('public')->put($path, 'original-proof-bytes');

        return Customer::create(['organization_id' => $organization->id, 'name' => 'Synthetic private proof customer', 'phone' => '9876543210', 'id_proof_file_path' => $path, 'id_proof_original_name' => 'proof.pdf']);
    }

    private function target(Customer $customer): string
    {
        return 'customer-id-proofs/customer-'.$customer->id.'-proof.pdf';
    }
}
