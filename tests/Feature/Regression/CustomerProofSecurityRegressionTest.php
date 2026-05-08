<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerProofSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_customers_read_only_cannot_download_customer_id_proof(): void
    {
        Storage::fake('local');

        $organization = TestData::organization();
        $customer = $this->customerWithProof($organization->id);
        $role = $this->roleFor($organization->id, 'Customer Reader', [
            'customers' => ['read'],
        ]);
        $user = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);

        $response = $this->actingAs($user)->get(route('customers.id-proof.download', $customer->id));

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('error', 'You are not authorized to access this section.');
    }

    public function test_user_with_customer_proof_download_permission_can_download_customer_id_proof(): void
    {
        Storage::fake('local');

        $organization = TestData::organization();
        $customer = $this->customerWithProof($organization->id);
        $role = $this->roleFor($organization->id, 'Customer Proof Viewer', [
            'customers' => ['read'],
            '__special' => ['customers.proof.download'],
        ]);
        $user = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);

        $response = $this->actingAs($user)->get(route('customers.id-proof.download', $customer->id));

        $response->assertOk();
        $response->assertDownload('customer-proof.pdf');
    }

    public function test_unauthenticated_user_cannot_download_customer_id_proof(): void
    {
        Storage::fake('local');

        $organization = TestData::organization();
        $customer = $this->customerWithProof($organization->id);

        $response = $this->get(route('customers.id-proof.download', $customer->id));

        $response->assertRedirect(route('login'));
    }

    public function test_direct_url_access_is_blocked_across_organizations_even_with_download_permission(): void
    {
        Storage::fake('local');

        $customerOrganization = TestData::organization(['name' => 'Customer Org']);
        $customer = $this->customerWithProof($customerOrganization->id);

        $otherOrganization = TestData::organization(['name' => 'Other Org']);
        $role = $this->roleFor($otherOrganization->id, 'Customer Proof Viewer', [
            'customers' => ['read'],
            '__special' => ['customers.proof.download'],
        ]);
        $user = TestData::user($otherOrganization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);

        $response = $this->actingAs($user)->get(route('customers.id-proof.download', $customer->id));

        $response->assertNotFound();
    }

    public function test_proof_migration_dry_run_does_not_mutate_public_proof_records(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $organization = TestData::organization();
        $customer = $this->customerWithPublicProof($organization->id, 'legacy/customer-proof.pdf');

        $exitCode = Artisan::call('rentnexis:migrate-proof-files');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString((string) $customer->id, $output);
        $this->assertStringContainsString('legacy/customer-proof.pdf', $output);

        Storage::disk('public')->assertExists('legacy/customer-proof.pdf');
        Storage::disk('local')->assertMissing('customer-id-proofs/customer-'.$customer->id.'-proof.pdf');
        $this->assertSame('legacy/customer-proof.pdf', $customer->fresh()->id_proof_file_path);
    }

    public function test_migrated_proof_files_remain_accessible_via_authorized_route_and_public_url_no_longer_works(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $organization = TestData::organization();
        $customer = $this->customerWithPublicProof($organization->id, 'legacy/customer-proof.pdf');
        $role = $this->roleFor($organization->id, 'Customer Proof Viewer', [
            'customers' => ['read'],
            '__special' => ['customers.proof.download'],
        ]);
        $user = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);

        $exitCode = Artisan::call('rentnexis:migrate-proof-files', ['--apply' => true]);
        $this->assertSame(0, $exitCode);

        $customer->refresh();
        $expectedPath = 'customer-id-proofs/customer-'.$customer->id.'-proof.pdf';

        $this->assertSame($expectedPath, $customer->id_proof_file_path);
        Storage::disk('local')->assertExists($expectedPath);
        Storage::disk('public')->assertMissing('legacy/customer-proof.pdf');

        $response = $this->actingAs($user)->get(route('customers.id-proof.download', $customer->id));
        $response->assertOk();
        $response->assertDownload('customer-proof.pdf');

        Auth::guard()->logout();
        $this->get('/storage/legacy/customer-proof.pdf')->assertForbidden();
    }

    private function customerWithProof(int $organizationId): Customer
    {
        $path = 'customer-id-proofs/customer-proof.pdf';
        Storage::disk('local')->put($path, 'proof-body');

        return Customer::create([
            'organization_id' => $organizationId,
            'name' => 'Proof Customer',
            'customer_type' => 'Individual',
            'phone' => '9999999999',
            'id_proof_file_path' => $path,
            'id_proof_original_name' => 'customer-proof.pdf',
        ]);
    }

    private function customerWithPublicProof(int $organizationId, string $path): Customer
    {
        Storage::disk('public')->put($path, 'legacy-public-proof-body');

        return Customer::create([
            'organization_id' => $organizationId,
            'name' => 'Public Proof Customer',
            'customer_type' => 'Individual',
            'phone' => '9999999998',
            'id_proof_file_path' => $path,
            'id_proof_original_name' => 'customer-proof.pdf',
        ]);
    }

    private function roleFor(int $organizationId, string $name, array $permissions): Role
    {
        return Role::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => str($name)->slug('_'),
            'description' => $name,
            'permissions' => Role::normalizePermissions($permissions),
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
