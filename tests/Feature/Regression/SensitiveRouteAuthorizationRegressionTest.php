<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class SensitiveRouteAuthorizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_export_requires_dedicated_export_permission(): void
    {
        $organization = TestData::organization();
        $customerReader = $this->userWithRole($organization, 'Customer Reader', [
            'customers' => ['read'],
        ]);
        $financeUser = $this->userWithRole($organization, 'Finance Reader', [
            'invoices' => ['read'],
            'payments' => ['read'],
        ]);
        $deliveryUser = $this->userWithRole($organization, 'Delivery Reader', [
            'deliveries' => ['read', 'update'],
        ]);
        $customerExporter = $this->userWithRole($organization, 'Customer Exporter', [
            'customers' => ['read'],
            '__special' => ['customers.export'],
        ]);
        $legacyAdmin = TestData::user($organization, [
            'role' => 'admin',
        ]);

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Export Customer',
            'customer_type' => 'Individual',
            'phone' => '9000000001',
        ]);

        $this->actingAs($customerExporter)
            ->get(route('customers.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($legacyAdmin)
            ->get(route('customers.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($customerReader)
            ->get(route('customers.export.csv'))
            ->assertRedirect($customerReader->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($financeUser)
            ->get(route('customers.export.csv'))
            ->assertRedirect($financeUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($deliveryUser)
            ->get(route('customers.export.csv'))
            ->assertRedirect($deliveryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');
    }

    public function test_invoice_and_payment_exports_require_dedicated_finance_permissions(): void
    {
        $organization = TestData::organization();
        $financeUser = $this->userWithRole($organization, 'Finance Reader', [
            'invoices' => ['read'],
            'payments' => ['read'],
            '__special' => ['invoices.export', 'payments.export'],
        ]);
        $financeReaderOnly = $this->userWithRole($organization, 'Finance Read Only', [
            'invoices' => ['read'],
            'payments' => ['read'],
        ]);
        $deliveryUser = $this->userWithRole($organization, 'Delivery Reader', [
            'deliveries' => ['read', 'update'],
        ]);
        $staffUser = $this->userWithRole($organization, 'Normal Staff', []);
        $legacyAdmin = TestData::user($organization, [
            'role' => 'admin',
        ]);

        $this->actingAs($financeUser)
            ->get(route('invoices.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($financeUser)
            ->get(route('payments.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($legacyAdmin)
            ->get(route('invoices.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($legacyAdmin)
            ->get(route('payments.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($financeReaderOnly)
            ->get(route('invoices.export.csv'))
            ->assertRedirect($financeReaderOnly->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($financeReaderOnly)
            ->get(route('payments.export.csv'))
            ->assertRedirect($financeReaderOnly->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($deliveryUser)
            ->get(route('invoices.export.csv'))
            ->assertRedirect($deliveryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($deliveryUser)
            ->get(route('payments.export.csv'))
            ->assertRedirect($deliveryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($staffUser)
            ->get(route('payments.export.csv'))
            ->assertRedirect($staffUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');
    }

    public function test_invoice_print_route_requires_dedicated_print_permission(): void
    {
        $organization = TestData::organization();
        $financeUser = $this->userWithRole($organization, 'Finance Reader', [
            'invoices' => ['read'],
            '__special' => ['invoices.print'],
        ]);
        $invoiceReaderOnly = $this->userWithRole($organization, 'Invoice Read Only', [
            'invoices' => ['read'],
        ]);
        $deliveryUser = $this->userWithRole($organization, 'Delivery Reader', [
            'deliveries' => ['read', 'update'],
        ]);
        $legacyAdmin = TestData::user($organization, [
            'role' => 'admin',
        ]);

        $invoice = $this->invoiceFor($organization);

        config()->set('pdf.browser_path', 'C:/definitely-missing-browser.exe');

        $this->actingAs($financeUser)
            ->get(route('invoices.print', $invoice->id))
            ->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('error');

        $this->actingAs($legacyAdmin)
            ->get(route('invoices.print', $invoice->id))
            ->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('error');

        $this->actingAs($invoiceReaderOnly)
            ->get(route('invoices.print', $invoice->id))
            ->assertRedirect($invoiceReaderOnly->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($deliveryUser)
            ->get(route('invoices.print', $invoice->id))
            ->assertRedirect($deliveryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        Auth::guard()->logout();

        $this->get(route('invoices.print', $invoice->id))
            ->assertRedirect(route('login'));
    }

    public function test_organization_settings_read_and_file_upload_require_settings_permissions(): void
    {
        Storage::fake('public');

        $organization = TestData::organization();
        $settingsUser = $this->userWithRole($organization, 'Settings Manager', [
            'settings' => ['read', 'update'],
        ]);
        $financeUser = $this->userWithRole($organization, 'Finance Reader', [
            'invoices' => ['read'],
            'payments' => ['read'],
        ]);

        $this->actingAs($financeUser)
            ->get(route('organization.settings.edit'))
            ->assertRedirect($financeUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($financeUser)
            ->put(route('organization.settings.update'), [
                'name' => 'Should Not Update',
                'logo' => UploadedFile::fake()->create('blocked-logo.png', 12, 'image/png'),
            ])
            ->assertRedirect($financeUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        Storage::disk('public')->assertMissing('organization-assets/blocked-logo.png');
        $this->assertSame($organization->name, $organization->fresh()->name);

        $this->actingAs($settingsUser)
            ->get(route('organization.settings.edit'))
            ->assertOk();

        $response = $this->actingAs($settingsUser)
            ->put(route('organization.settings.update'), [
                'name' => 'Updated Organization',
                'logo' => UploadedFile::fake()->create('allowed-logo.png', 12, 'image/png'),
            ]);

        $response->assertRedirect(route('organization.settings.edit'));
        $response->assertSessionHas('success', 'Organization invoice settings updated successfully.');

        $organization->refresh();
        $this->assertSame('Updated Organization', $organization->name);
        $this->assertNotEmpty($organization->logo);
        Storage::disk('public')->assertExists($organization->logo);
    }

    public function test_finance_delivery_and_normal_staff_direct_url_access_is_separated(): void
    {
        $organization = TestData::organization();
        $customer = $this->customerWithProof($organization->id);

        $financeUser = $this->userWithRole($organization, 'Finance Reader', [
            'invoices' => ['read'],
            'payments' => ['read'],
        ]);
        $deliveryUser = $this->userWithRole($organization, 'Delivery Reader', [
            'deliveries' => ['read', 'update'],
        ]);
        $staffUser = $this->userWithRole($organization, 'Normal Staff', []);

        $this->actingAs($financeUser)
            ->get(route('customers.id-proof.download', $customer->id))
            ->assertRedirect($financeUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($deliveryUser)
            ->get(route('deliveries.assigned'))
            ->assertOk();

        $this->actingAs($deliveryUser)
            ->get(route('organization.settings.edit'))
            ->assertRedirect($deliveryUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($staffUser)
            ->get(route('invoices.export.csv'))
            ->assertRedirect($staffUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($financeUser)
            ->get(route('invoices.export.csv'))
            ->assertRedirect($financeUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        $this->actingAs($staffUser)
            ->get(route('deliveries.assigned'))
            ->assertRedirect($staffUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');

        Auth::guard()->logout();

        $this->get(route('organization.settings.edit'))
            ->assertRedirect(route('login'));
    }

    private function userWithRole(Organization $organization, string $name, array $permissions)
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => str($name)->slug('_'),
            'description' => $name,
            'permissions' => Role::normalizePermissions($permissions),
            'is_system' => false,
            'is_active' => true,
        ]);

        return TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }

    private function invoiceFor(Organization $organization): Invoice
    {
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Invoice Customer',
            'customer_type' => 'Individual',
            'phone' => '9000000099',
        ]);

        return Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-SEC-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'taxable_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ]);
    }

    private function customerWithProof(int $organizationId): Customer
    {
        Storage::disk('local')->put('customer-id-proofs/direct-access-proof.pdf', 'proof-body');

        return Customer::create([
            'organization_id' => $organizationId,
            'name' => 'Proof Customer',
            'customer_type' => 'Individual',
            'phone' => '9999999999',
            'id_proof_file_path' => 'customer-id-proofs/direct-access-proof.pdf',
            'id_proof_original_name' => 'direct-access-proof.pdf',
        ]);
    }
}
