<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class CancelledInvoiceBalanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;
    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic cancelled invoice customer',
            'phone' => '+12025550100',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic cancelled invoice product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'total_quantity' => 1,
            'available_quantity' => 1,
            'price_per_day' => 100,
        ]);
        $this->rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rental_amount' => 100,
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        $this->invoice = Invoice::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'rental_id' => $this->rental->id,
            'invoice_number' => 'CANCEL-BALANCE-' . Str::random(8),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
        ]);
    }

    public static function cancelledStates(): array
    {
        return [
            'unpaid void' => [0, 'cancelled', 'cancelled'],
            'partial void' => [25, 'cancelled', 'cancelled'],
            'paid void' => [100, 'cancelled', 'cancelled'],
            'status flag only' => [25, 'cancelled', 'partial'],
            'payment status flag only' => [25, 'partial', 'cancelled'],
        ];
    }

    #[DataProvider('cancelledStates')]
    public function test_refresh_keeps_cancelled_invoices_at_zero_due_and_preserves_history(
        float $paid,
        string $status,
        string $paymentStatus
    ): void {
        $this->recordReceipt($paid);
        $this->invoice->update([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'balance_amount' => 100 - $paid,
        ]);
        $history = $this->history();

        $this->invoice->refresh()->syncFinancialStatus();
        $this->invoice->refresh()->syncFinancialStatus();

        $this->assertCancelledFinancials($paid);
        $this->assertSame($history, $this->history());
    }

    public function test_non_persisting_refresh_does_not_write_the_balance_repair(): void
    {
        $this->recordReceipt(25);
        $this->invoice->update(['status' => 'cancelled', 'payment_status' => 'cancelled']);
        $history = $this->history();

        $this->invoice->syncFinancialStatus(false);

        $this->assertSame(0.0, (float) $this->invoice->balance_amount);
        $this->assertSame(25.0, (float) $this->invoice->paid_amount);
        $this->assertSame(75.0, (float) $this->invoice->fresh()->balance_amount);
        $this->assertSame($history, $this->history());
    }

    public static function activeStates(): array
    {
        return [
            'unpaid' => [0, false, 'unpaid', 100],
            'partial' => [25, false, 'partial', 75],
            'paid' => [100, false, 'paid', 0],
            'overdue' => [0, true, 'overdue', 100],
        ];
    }

    #[DataProvider('activeStates')]
    public function test_active_invoice_balances_continue_to_follow_receipts(
        float $paid,
        bool $overdue,
        string $expectedStatus,
        float $expectedBalance
    ): void {
        $this->recordReceipt($paid);
        if ($overdue) {
            $this->invoice->update(['due_date' => now()->subDay()->toDateString()]);
        }
        $history = $this->history();

        $this->invoice->refresh()->syncFinancialStatus();

        $fresh = $this->invoice->fresh();
        $this->assertSame($expectedStatus, $fresh->status);
        $this->assertSame($expectedStatus, $fresh->payment_status);
        $this->assertSame($expectedBalance, (float) $fresh->balance_amount);
        $this->assertSame($paid, (float) $fresh->paid_amount);
        $this->assertSame($history, $this->history());
    }

    public function test_void_redirect_and_repeated_invoice_rental_views_keep_zero_due(): void
    {
        $this->recordReceipt(25);
        $history = $this->history();

        $this->put(route('invoices.void', $this->invoice))
            ->assertRedirect(route('invoices.show', $this->invoice));
        $this->assertCancelledFinancials(25);

        for ($refresh = 0; $refresh < 2; $refresh++) {
            $page = $this->get(route('invoices.show', $this->invoice))->assertOk();
            $this->assertSame(0.0, (float) $page->viewData('invoice')->balance_amount);
            $rentalPage = $this->get(route('rentals.show', $this->rental))->assertOk();
            $this->assertSame(0.0, (float) $rentalPage->viewData('rentalInvoice')->balance_amount);
            $rentalPage->assertSeeText('Cancelled')->assertSeeText('₹0.00 Outstanding');
            $rentalPage->assertDontSee('id="record-payment-panel"', false);
            $this->assertCancelledFinancials(25);
        }

        $this->assertSame($history, $this->history());
    }

    public function test_rental_view_normalizes_a_legacy_cancelled_balance_without_writing_history(): void
    {
        $this->recordReceipt(25);
        $this->invoice->update(['status' => 'cancelled', 'payment_status' => 'cancelled']);
        $history = $this->history();

        $page = $this->get(route('rentals.show', $this->rental))->assertOk();

        $this->assertSame(0.0, (float) $page->viewData('rentalInvoice')->balance_amount);
        $page->assertSeeText('Cancelled')->assertSeeText('₹0.00 Outstanding');
        $page->assertDontSee('id="record-payment-panel"', false);
        $this->assertSame(75.0, (float) $this->invoice->fresh()->balance_amount);
        $this->assertSame($history, $this->history());
    }

    public function test_bulk_void_and_legacy_balance_refresh_both_keep_zero_due(): void
    {
        $this->recordReceipt(25);
        $history = $this->history();
        $this->post(route('invoices.bulk.action'), [
            'invoice_ids' => [$this->invoice->id],
            'bulk_action' => 'void',
        ])->assertRedirect();
        $this->assertCancelledFinancials(25);

        // Reproduce a cancelled invoice whose balance was restored by an older refresh.
        $this->invoice->refresh()->update(['balance_amount' => 75]);
        $this->get(route('invoices.show', $this->invoice))->assertOk();

        $this->assertCancelledFinancials(25);
        $this->assertSame($history, $this->history());
    }

    public function test_stale_payment_form_still_rejects_cancelled_invoice_without_consuming_claim(): void
    {
        $this->recordReceipt(25);
        $this->put(route('invoices.void', $this->invoice))->assertRedirect();
        $this->get(route('invoices.show', $this->invoice))->assertOk();
        $history = $this->history();

        $this->postJson(route('rentals.recordPayment', $this->rental), [
            'payment_submission_token' => (string) Str::uuid(),
            'payment_date' => now()->toDateString(),
            'amount' => 25,
            'payment_method' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertCancelledFinancials(25);
        $this->assertSame($history, $this->history());
    }

    private function recordReceipt(float $amount): void
    {
        if ($amount > 0) {
            $this->post(route('rentals.recordPayment', $this->rental), [
                'payment_submission_token' => (string) Str::uuid(),
                'payment_date' => now()->toDateString(),
                'amount' => $amount,
                'payment_method' => 'other',
                'notes' => 'Synthetic receipt retained after void',
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->invoice->refresh();
    }

    private function assertCancelledFinancials(float $paid): void
    {
        $fresh = $this->invoice->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('cancelled', $fresh->payment_status);
        $this->assertSame(100.0, (float) $fresh->total_amount);
        $this->assertSame($paid, (float) $fresh->paid_amount);
        $this->assertSame(0.0, (float) $fresh->balance_amount);
    }

    private function history(): array
    {
        return [
            'receipts' => $this->invoice->payments()->orderBy('id')->get()->toArray(),
            'claims' => DB::table('payment_submissions')->orderBy('organization_id')->orderBy('token')->get()->toJson(),
            'rental' => $this->rental->fresh()->getRawOriginal(),
        ];
    }
}
