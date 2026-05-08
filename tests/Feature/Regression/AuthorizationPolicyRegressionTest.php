<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TestData;
use Tests\TestCase;

class AuthorizationPolicyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_policy_maps_read_export_and_proof_permissions(): void
    {
        $organization = TestData::organization();
        $customer = new Customer(['organization_id' => $organization->id]);

        $reader = $this->userWithRole($organization->id, 'Customer Reader', [
            'customers' => ['read'],
        ]);
        $exporter = $this->userWithRole($organization->id, 'Customer Exporter', [
            'customers' => ['read'],
            '__special' => ['customers.export'],
        ]);
        $proofViewer = $this->userWithRole($organization->id, 'Customer Proof Viewer', [
            'customers' => ['read'],
            '__special' => ['customers.proof.download'],
        ]);

        $this->assertTrue(Gate::forUser($reader)->allows('viewAny', Customer::class));
        $this->assertTrue(Gate::forUser($reader)->allows('view', $customer));
        $this->assertFalse(Gate::forUser($reader)->allows('export', Customer::class));
        $this->assertFalse(Gate::forUser($reader)->allows('downloadProof', $customer));

        $this->assertTrue(Gate::forUser($exporter)->allows('export', Customer::class));
        $this->assertTrue(Gate::forUser($proofViewer)->allows('downloadProof', $customer));
    }

    public function test_rental_and_sale_policies_follow_existing_role_access(): void
    {
        $organization = TestData::organization();
        $rental = new Rental(['organization_id' => $organization->id]);
        $sale = new Sale(['organization_id' => $organization->id]);

        $rentalManager = $this->userWithRole($organization->id, 'Rental Manager', [
            'rentals' => ['read', 'create', 'update', 'delete'],
        ]);
        $salesManager = $this->userWithRole($organization->id, 'Sales Manager', [
            'sales' => ['read', 'create', 'update', 'delete'],
        ]);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->assertTrue(Gate::forUser($rentalManager)->allows('viewAny', Rental::class));
        $this->assertTrue(Gate::forUser($rentalManager)->allows('create', Rental::class));
        $this->assertTrue(Gate::forUser($rentalManager)->allows('view', $rental));
        $this->assertTrue(Gate::forUser($rentalManager)->allows('update', $rental));
        $this->assertTrue(Gate::forUser($rentalManager)->allows('delete', $rental));
        $this->assertTrue(Gate::forUser($rentalManager)->allows('export', Rental::class));

        $this->assertTrue(Gate::forUser($salesManager)->allows('viewAny', Sale::class));
        $this->assertTrue(Gate::forUser($salesManager)->allows('create', Sale::class));
        $this->assertTrue(Gate::forUser($salesManager)->allows('view', $sale));
        $this->assertTrue(Gate::forUser($salesManager)->allows('update', $sale));
        $this->assertTrue(Gate::forUser($salesManager)->allows('delete', $sale));

        $this->assertTrue(Gate::forUser($deliveryUser)->allows('view', $rental));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('delete', $rental));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('create', Sale::class));
    }

    public function test_finance_and_delivery_roles_are_separated_by_invoice_and_payment_policies(): void
    {
        $organization = TestData::organization();
        $invoice = new Invoice(['organization_id' => $organization->id]);
        $payment = new Payment(['organization_id' => $organization->id]);
        $rental = new Rental(['organization_id' => $organization->id]);

        $financeUser = TestData::user($organization, [
            'role' => User::ROLE_FINANCE,
        ]);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $this->assertTrue(Gate::forUser($financeUser)->allows('viewAny', Invoice::class));
        $this->assertTrue(Gate::forUser($financeUser)->allows('view', $invoice));
        $this->assertTrue(Gate::forUser($financeUser)->allows('export', Invoice::class));
        $this->assertTrue(Gate::forUser($financeUser)->allows('printAny', Invoice::class));
        $this->assertTrue(Gate::forUser($financeUser)->allows('print', $invoice));
        $this->assertTrue(Gate::forUser($financeUser)->allows('viewAny', Payment::class));
        $this->assertTrue(Gate::forUser($financeUser)->allows('create', Payment::class));
        $this->assertTrue(Gate::forUser($financeUser)->allows('createForRental', [Payment::class, $rental]));
        $this->assertTrue(Gate::forUser($financeUser)->allows('delete', $payment));
        $this->assertTrue(Gate::forUser($financeUser)->allows('export', Payment::class));

        $this->assertFalse(Gate::forUser($deliveryUser)->allows('export', Invoice::class));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('printAny', Invoice::class));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('print', $invoice));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('createForRental', [Payment::class, $rental]));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('export', Payment::class));
    }

    public function test_policies_do_not_leak_access_across_organizations(): void
    {
        $ownerOrganization = TestData::organization(['name' => 'Owner Org']);
        $otherOrganization = TestData::organization(['name' => 'Other Org']);

        $customer = new Customer(['organization_id' => $ownerOrganization->id]);
        $rental = new Rental(['organization_id' => $ownerOrganization->id]);
        $sale = new Sale(['organization_id' => $ownerOrganization->id]);
        $invoice = new Invoice(['organization_id' => $ownerOrganization->id]);
        $payment = new Payment(['organization_id' => $ownerOrganization->id]);

        $otherUser = TestData::user($otherOrganization, [
            'role' => 'admin',
        ]);
        $otherProofViewer = $this->userWithRole($otherOrganization->id, 'Other Proof Viewer', [
            'customers' => ['read'],
            '__special' => ['customers.proof.download'],
        ]);

        $this->assertFalse(Gate::forUser($otherUser)->allows('view', $customer));
        $this->assertFalse(Gate::forUser($otherUser)->allows('view', $rental));
        $this->assertFalse(Gate::forUser($otherUser)->allows('view', $sale));
        $this->assertFalse(Gate::forUser($otherUser)->allows('view', $invoice));
        $this->assertFalse(Gate::forUser($otherUser)->allows('delete', $payment));
        $this->assertFalse(Gate::forUser($otherProofViewer)->allows('downloadProof', $customer));
        $this->assertFalse(Gate::forUser($otherUser)->allows('print', $invoice));
    }

    public function test_delivery_policy_respects_assignment_and_cross_organization_boundaries(): void
    {
        $organization = TestData::organization();
        $assignedUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $otherUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);

        $ownDelivery = new Delivery([
            'organization_id' => $organization->id,
            'assigned_user_id' => $assignedUser->id,
        ]);
        $otherDelivery = new Delivery([
            'organization_id' => $organization->id,
            'assigned_user_id' => $otherUser->id,
        ]);
        $otherOrgDelivery = new Delivery([
            'organization_id' => TestData::organization(['name' => 'Elsewhere'])->id,
            'assigned_user_id' => $assignedUser->id,
        ]);

        $this->assertTrue(Gate::forUser($assignedUser)->allows('viewAny', Delivery::class));
        $this->assertTrue(Gate::forUser($assignedUser)->allows('view', $ownDelivery));
        $this->assertTrue(Gate::forUser($assignedUser)->allows('update', $ownDelivery));
        $this->assertFalse(Gate::forUser($assignedUser)->allows('view', $otherDelivery));
        $this->assertFalse(Gate::forUser($assignedUser)->allows('update', $otherDelivery));
        $this->assertFalse(Gate::forUser($assignedUser)->allows('view', $otherOrgDelivery));
    }

    public function test_organization_settings_policy_allows_settings_role_and_denies_unrelated_roles(): void
    {
        $organization = TestData::organization();
        $settingsManager = $this->userWithRole($organization->id, 'Settings Manager', [
            'settings' => ['read', 'update'],
        ]);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $otherOrganization = TestData::organization(['name' => 'Other Settings Org']);
        $otherSettingsManager = $this->userWithRole($otherOrganization->id, 'Other Settings Manager', [
            'settings' => ['read', 'update'],
        ]);

        $this->assertTrue(Gate::forUser($settingsManager)->allows('view', $organization));
        $this->assertTrue(Gate::forUser($settingsManager)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($otherSettingsManager)->allows('view', $organization));
        $this->assertFalse(Gate::forUser($otherSettingsManager)->allows('update', $organization));
    }

    public function test_report_and_import_policies_follow_existing_role_boundaries(): void
    {
        $organization = TestData::organization();
        $reportUser = $this->userWithRole($organization->id, 'Report User', [
            'reports' => ['read'],
        ]);
        $deliveryUser = TestData::user($organization, [
            'role' => User::ROLE_DELIVERY,
        ]);
        $superAdmin = TestData::user($organization);
        $admin = TestData::user($organization, [
            'role' => 'admin',
        ]);

        $this->assertTrue(Gate::forUser($reportUser)->allows('viewAny', ReportController::class));
        $this->assertTrue(Gate::forUser($reportUser)->allows('export', ReportController::class));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('viewAny', ReportController::class));
        $this->assertFalse(Gate::forUser($deliveryUser)->allows('export', ReportController::class));

        $this->assertTrue(Gate::forUser($superAdmin)->allows('access', ImportController::class));
        $this->assertFalse(Gate::forUser($admin)->allows('access', ImportController::class));
        $this->assertFalse(Gate::forUser($reportUser)->allows('access', ImportController::class));
    }

    private function userWithRole(int $organizationId, string $name, array $permissions): User
    {
        $role = $this->roleFor($organizationId, $name, $permissions);

        return TestData::user(Organization::query()->findOrFail($organizationId), [
            'organization_id' => $organizationId,
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }

    private function roleFor(int $organizationId, string $name, array $permissions, array $scopeOverrides = []): Role
    {
        $normalizedPermissions = Role::normalizePermissions($permissions);

        if ($scopeOverrides !== []) {
            $normalizedPermissions['__scopes'] = $scopeOverrides;
        }

        return Role::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => str($name)->slug('_'),
            'description' => $name,
            'permissions' => $normalizedPermissions,
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
