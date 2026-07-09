<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class InvoiceRecordFinanceVisibilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_user_can_see_invoice_row_values_but_not_dashboard_collection_totals(): void
    {
        [$invoice, $organization] = $this->invoiceFixture();
        $salesUser = $this->userWithRole($organization, User::ROLE_SALES, [
            'invoices' => ['read'],
            'payments' => ['read'],
        ]);

        $this->assertFalse($salesUser->canViewFinance());
        $this->assertTrue($salesUser->canViewRecordFinance());

        $html = $this->actingAs($salesUser)
            ->get(route('invoices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('&#8377;9,876.54', $html);
        $this->assertStringContainsString('&#8377;8,765.43', $html);
        $this->assertStringContainsString('Outstanding', $html);
        $this->assertStringContainsString('Overdue', $html);
        $this->assertStringContainsString('Largest Invoice', $html);
        $this->assertStringContainsString('Average Invoice', $html);
        $this->assertMatchesRegularExpression('/Collected Today<\/span><strong class="success">\s*Restricted\s*<\/strong>/s', $html);
        $this->assertMatchesRegularExpression('/Collected Month<\/span><strong class="success">\s*Restricted\s*<\/strong>/s', $html);
        $this->assertStringNotContainsString('markPaidInvoice'.$invoice->id, $html);
    }

    public function test_admin_operations_user_can_see_invoice_row_amount_and_balance(): void
    {
        [$invoice, $organization] = $this->invoiceFixture();
        $adminOperations = $this->userWithRole($organization, User::ROLE_ADMIN_OPERATIONS, [
            'invoices' => ['read'],
            'payments' => ['read'],
        ]);

        $this->assertTrue($adminOperations->canViewRecordFinance());

        $html = $this->actingAs($adminOperations)
            ->get(route('invoices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('&#8377;9,876.54', $html);
        $this->assertStringContainsString('&#8377;8,765.43', $html);
    }

    public function test_vendor_invoice_reader_cannot_see_invoice_financial_values(): void
    {
        [$invoice, $organization] = $this->invoiceFixture();
        $vendorUser = $this->userWithRole($organization, User::ROLE_VENDOR, [
            'invoices' => ['read'],
        ]);

        $this->assertFalse($vendorUser->canViewRecordFinance());

        $html = $this->actingAs($vendorUser)
            ->get(route('invoices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('Restricted', $html);
        $this->assertStringNotContainsString('&#8377;9,876.54', $html);
        $this->assertStringNotContainsString('&#8377;8,765.43', $html);
        $this->assertStringNotContainsString('CGST &#8377;111.11', $html);
    }

    public function test_payment_action_requires_payment_create_permission_even_when_record_finance_is_visible(): void
    {
        [$invoice, $organization] = $this->invoiceFixture();
        $salesPaymentUser = $this->userWithRole($organization, User::ROLE_SALES, [
            'invoices' => ['read'],
            'payments' => ['read', 'create'],
        ]);

        $html = $this->actingAs($salesPaymentUser)
            ->get(route('invoices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('markPaidInvoice'.$invoice->id, $html);
        $this->assertStringContainsString('&#8377;9,876.54', $html);
    }

    private function invoiceFixture(): array
    {
        $organization = TestData::organization();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Invoice Visibility Customer',
            'phone' => '9000000501',
            'city' => 'Bengaluru',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-VIS-987',
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays(3)->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'bill_to_city' => 'Bengaluru',
            'status' => 'sent',
            'payment_status' => 'pending',
            'subtotal' => 9654.32,
            'cgst_amount' => 111.11,
            'sgst_amount' => 111.11,
            'igst_amount' => 0,
            'total_tax_amount' => 222.22,
            'total_amount' => 9876.54,
            'paid_amount' => 1111.11,
            'balance_amount' => 8765.43,
        ]);

        return [$invoice, $organization];
    }

    private function userWithRole($organization, string $slug, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => str($slug)->replace('_', ' ')->title()->toString(),
            'slug' => $slug,
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        return TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }
}
