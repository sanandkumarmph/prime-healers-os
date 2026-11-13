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
        $this->assertFalse($salesUser->canViewDues());
        $this->assertTrue($salesUser->canViewRecordFinance());

        $html = $this->actingAs($salesUser)
            ->get(route('invoices.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($invoice->invoice_number, $html);
        $this->assertStringContainsString('&#8377;9,876.54', $html);
        $this->assertStringContainsString('&#8377;8,765.43', $html);
        $this->assertStringNotContainsString('<span>Outstanding</span>', $html);
        $this->assertStringContainsString('Overdue', $html);
        $this->assertStringNotContainsString('Finance Summary', $html);
        $this->assertStringNotContainsString('Largest Invoice', $html);
        $this->assertStringNotContainsString('Average Invoice', $html);
        $this->assertStringNotContainsString('Collected Today', $html);
        $this->assertStringContainsString('Outstanding by Aging', $html);
        $this->assertMatchesRegularExpression('/invoice-aging-donut[^>]*>\s*<strong>&#8377;8,765\.43<\/strong>/', $html);
        $this->assertStringNotContainsString('Collected Month', $html);
        $this->assertStringNotContainsString('markPaidInvoice'.$invoice->id, $html);
        if ($folder = getenv('PHOS_RENTAL_DETAIL_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder . '/invoice-sales-list.html', $html);
        }
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
        $this->assertStringContainsString('<span>Outstanding</span>', $html);
        $this->assertStringContainsString('Collected Month', $html);
        $this->assertStringContainsString('Finance Summary', $html);
    }

    public function test_invoice_aging_respects_filters_dates_and_organization_across_pages(): void
    {
        [$invoice, $organization] = $this->invoiceFixture();
        $sales = $this->userWithRole($organization, User::ROLE_SALES, ['invoices' => ['read']]);
        $this->travelTo(now()->setTime(23, 59));
        $invoice->update(['invoice_number' => 'AGE-BAL-30', 'due_date' => now()->subDays(30)->toDateString(), 'balance_amount' => 10.23]);
        foreach ([31 => 20, 60 => 30, 61 => 40, 90 => 50, 91 => 60, -1 => 5, 0 => 4] as $days => $balance) {
            $copy = $invoice->replicate();
            $copy->forceFill(['invoice_number' => 'AGE-BAL-'.$days, 'due_date' => now()->subDays($days)->toDateString(),
                'balance_amount' => $balance, 'payment_status' => $days > 0 ? 'overdue' : 'unpaid'])->save();
        }
        foreach (['paid', 'cancelled', 'foreign'] as $type) {
            $copy = $invoice->replicate();
            $copy->forceFill(['invoice_number' => 'AGE-BAL-'.$type,
                'organization_id' => $type === 'foreign' ? TestData::organization()->id : $organization->id,
                'payment_status' => $type === 'foreign' ? 'unpaid' : $type])->save();
        }
        $invoice->replicate()->forceFill(['invoice_number' => 'NOT-IN-FILTER', 'balance_amount' => 500])->save();

        $expected = ['current' => 19.23, 'days_31_60' => 50.0, 'days_61_90' => 90.0, 'days_91_plus' => 60.0];
        $this->actingAs($sales);
        foreach ([1, 2] as $page) {
            $response = $this->get(route('invoices.index', ['search' => 'AGE-BAL', 'per_page' => 1, 'page' => $page]))->assertOk();
            $this->assertSame($expected, $response->viewData('invoiceAging'));
            $response->assertSee('&#8377;219.23', false)->assertDontSee('Finance Summary');
        }
    }

    public function test_vendor_cannot_open_invoices_even_with_a_saved_invoice_grant(): void
    {
        [, $organization] = $this->invoiceFixture();
        $vendorUser = $this->userWithRole($organization, User::ROLE_VENDOR, [
            'invoices' => ['read'],
        ]);

        $this->assertFalse($vendorUser->canViewRecordFinance());

        $this->actingAs($vendorUser)->getJson(route('invoices.index'))->assertForbidden();
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
