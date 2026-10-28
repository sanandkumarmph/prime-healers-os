<?php

namespace Tests\Feature\Regression;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\PaymentCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class PaymentCorrectionSafetyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $org = TestData::organization();
        $user = TestData::user($org);
        $this->actingAs($user);
        $invoice = Invoice::create([
            'organization_id' => $org->id, 'invoice_number' => 'COR-'.$org->id,
            'invoice_date' => today(), 'due_date' => today()->addDays(10),
            'total_amount' => 1000, 'paid_amount' => 400, 'balance_amount' => 600,
            'status' => 'partial', 'payment_status' => 'partial',
        ]);
        $payment = Payment::create([
            'organization_id' => $org->id, 'invoice_id' => $invoice->id,
            'payment_date' => today(), 'amount' => 400, 'payment_method' => 'cash',
        ]);
        return [$org, $invoice, $payment];
    }

    private function payload(Payment $payment): array
    {
        return ['reason' => 'Duplicate entry recorded by mistake', 'confirm' => 1,
            'version' => app(PaymentCorrectionService::class)->version($payment)];
    }

    public function test_reversal_preserves_original_updates_balance_and_records_actor_reason(): void
    {
        [$org, $invoice, $payment] = $this->fixture();
        $before = $payment->fresh()->getAttributes();
        $this->get(route('payments.correction', $payment))->assertOk()->assertSee('does not refund');
        $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasNoErrors();
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseHas('payments', ['reversal_of_payment_id' => $payment->id, 'amount' => -400]);
        $this->assertEquals(0, $invoice->fresh()->paid_amount);
        $this->assertEquals(1000, $invoice->fresh()->balance_amount);
        $audit = ActivityLog::where('action', 'payment.reversed')->sole();
        $this->assertEquals(auth()->id(), $audit->user_id);
        $this->assertSame('Duplicate entry recorded by mistake', $audit->properties['reason']);
        $this->assertSame(0.0, (float) Payment::sum('amount'));
    }

    public function test_replay_and_reversing_a_reversal_do_not_create_more_entries(): void
    {
        [, , $payment] = $this->fixture();
        $data = $this->payload($payment);
        $this->post(route('payments.reverse', $payment), $data)->assertSessionHasNoErrors();
        $this->post(route('payments.reverse', $payment), $data)->assertSessionHasErrors('payment');
        $this->get(route('payments.correction', $payment))->assertOk()->assertSee('Already reversed')->assertDontSee('name="confirm"', false);
        $negative = Payment::where('reversal_of_payment_id', $payment->id)->sole();
        $this->post(route('payments.reverse', $negative), $this->payload($negative))->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, ActivityLog::where('action', 'payment.reversed')->count());
    }

    public function test_stale_amount_reason_and_confirmation_are_rejected(): void
    {
        [, , $payment] = $this->fixture();
        $data = $this->payload($payment);
        $payment->update(['amount' => 300]);
        $this->post(route('payments.reverse', $payment), $data)->assertSessionHasErrors('payment');
        $this->post(route('payments.reverse', $payment), ['reason' => ' ', 'version' => $data['version']])->assertSessionHasErrors(['reason', 'confirm']);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_delete_endpoint_redirects_to_correction_without_deleting(): void
    {
        [, $invoice, $payment] = $this->fixture();
        $this->delete(route('payments.destroy', $payment))->assertRedirect(route('payments.correction', $payment));
        $this->assertDatabaseCount('payments', 1);
        $this->assertEquals(400, $invoice->fresh()->paid_amount);
    }

    public function test_cross_tenant_and_delivery_role_cannot_reverse(): void
    {
        [$org, , $payment] = $this->fixture();
        $data = $this->payload($payment);
        $this->actingAs(TestData::user(TestData::organization()));
        $this->post(route('payments.reverse', $payment), $data)->assertNotFound();
        $this->actingAs(TestData::user($org, ['role' => 'delivery']));
        $this->postJson(route('payments.reverse', $payment), $data)->assertForbidden();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_managed_opening_and_automatic_sale_entries_are_not_reversed(): void
    {
        [, , $payment] = $this->fixture();
        foreach (['Opening paid amount from invoice form.', 'Auto-recorded from paid sale #8.'] as $notes) {
            $payment->update(['notes' => $notes]);
            $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasErrors('payment');
        }
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_audit_failure_rolls_back_payment_and_invoice(): void
    {
        [, $invoice, $payment] = $this->fixture();
        ActivityLog::creating(function ($log) {
            if ($log->action === 'payment.reversed') {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        try {
            app(PaymentCorrectionService::class)->reverse($payment, 'Incorrect payment entry', $this->payload($payment)['version']);
            $this->fail('Audit failure must abort reversal');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            ActivityLog::flushEventListeners();
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertEquals(400, $invoice->fresh()->paid_amount);
    }

    public function test_invoice_deletion_keeps_payment_and_audit_history(): void
    {
        [$org, $invoice, $payment] = $this->fixture();
        $log = ActivityLog::create(['organization_id' => $org->id, 'action' => 'invoice.created', 'invoice_id' => $invoice->id]);
        $this->delete(route('invoices.destroy', $invoice))->assertSessionHasErrors('invoice');
        $this->assertNotNull($invoice->fresh());
        $this->assertEquals($invoice->id, $payment->fresh()->invoice_id);
        $this->assertNotNull($log->fresh());
    }

    public function test_bulk_invoice_delete_is_atomic_when_one_has_history(): void
    {
        [$org, $invoice] = $this->fixture();
        $empty = $invoice->replicate();
        $empty->invoice_number = 'EMPTY';
        $empty->save();
        $this->post(route('invoices.bulk.delete'), ['invoice_ids' => [$empty->id, $invoice->id]])->assertSessionHasErrors('invoice');
        $this->assertNotNull($empty->fresh());
        $this->assertNotNull($invoice->fresh());
        $this->assertDatabaseCount('payments', 1);
    }
    public function test_invoice_audit_history_alone_blocks_deletion(): void
    {
        [$org, $invoice, $payment] = $this->fixture();
        $payment->delete(); // Fixture: no recorded payment; only issued-invoice history remains.
        $log = ActivityLog::create(['organization_id' => $org->id, 'action' => 'invoice.created', 'invoice_id' => $invoice->id]);
        $this->delete(route('invoices.destroy', $invoice))->assertSessionHasErrors('invoice');
        $this->assertNotNull($invoice->fresh());
        $this->assertNotNull($log->fresh());
    }
    public function test_import_managed_payments_cannot_be_reversed_or_change_history(): void
    {
        [, $invoice, $payment] = $this->fixture();
        foreach (['Imported payment sync for sale #8.', 'Imported payment sync for rental #9.'] as $notes) {
            $payment->update(['notes' => $notes]);
            $before = $payment->fresh()->getAttributes();
            $invoiceBefore = $invoice->fresh()->getAttributes();
            $this->post(route('payments.reverse', $payment), $this->payload($payment))
                ->assertSessionHasErrors('payment');
            $this->assertSame($before, $payment->fresh()->getAttributes());
            $this->assertSame($invoiceBefore, $invoice->fresh()->getAttributes());
            $this->assertDatabaseCount('payments', 1);
            $this->assertSame(0, Payment::whereNotNull('reversal_of_payment_id')->count());
            $this->assertSame(0, ActivityLog::where('action', 'payment.reversed')->count());
        }
    }
    public function test_full_receipt_correction_and_replacement_survive_invoice_reload_and_net_collections(): void
    {
        [, $invoice, $payment] = $this->fixture();
        $payment->update(['amount' => 1000]);
        $invoice->syncFinancialStatus();
        $before = $payment->fresh()->getAttributes();
        $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasNoErrors();
        $this->assertEquals(1000, $invoice->fresh()->balance_amount);
        $this->post(route('invoices.payments.store', $invoice), [
            'payment_submission_token' => (string) \Illuminate\Support\Str::uuid(),
            'payment_date' => today()->toDateString(), 'amount' => 75, 'payment_method' => 'other',
        ])->assertSessionHasNoErrors();
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Reversed')->assertSee('Correction of receipt');
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('payments', 3);
        $this->assertEquals(75, $invoice->fresh()->paid_amount);
        $this->assertEquals(925, $invoice->fresh()->balance_amount);
        $summary = app(\App\Services\Metrics\CollectionMetricsService::class)->summary(Payment::query());
        $this->assertSame(75.0, $summary['paymentsReceivedToday']);
        $this->assertSame(75.0, $summary['paymentsReceivedThisMonth']);
        $this->delete(route('invoices.destroy', $invoice))->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('payments', 3);
    }

    public function test_cancelled_invoice_correction_preserves_zero_due_and_cancellation(): void
    {
        [, $invoice, $payment] = $this->fixture();
        $invoice->update(['payment_status' => 'cancelled']);
        $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('cancelled', $invoice->fresh()->payment_status);
        $this->assertEquals(0, $invoice->fresh()->balance_amount);
        $this->assertEquals(0, $invoice->fresh()->paid_amount);
        $this->assertEquals(1000, $invoice->fresh()->total_amount);
    }

    public function test_renewal_managed_receipt_is_preserved(): void
    {
        [$org, , $payment] = $this->fixture();
        \Illuminate\Support\Facades\DB::table('rental_renewals')->insert([
            'organization_id' => $org->id, 'rental_id' => 999,
            'renewed_end_date' => today(), 'payment_id' => $payment->id, 'payment_amount' => 400,
        ]);
        $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('rental_renewals', ['payment_id' => $payment->id, 'payment_amount' => 400]);
    }

    public function test_bulk_delete_with_missing_selection_never_deletes_the_remaining_invoice(): void
    {
        [, $invoice, $payment] = $this->fixture();
        $payment->delete();
        $this->postJson(route('invoices.bulk.delete'), ['invoice_ids' => [$invoice->id, 999999]])->assertNotFound();
        $this->assertNotNull($invoice->fresh());
    }

    public function test_json_delete_refuses_to_remove_a_receipt(): void
    {
        [, , $payment] = $this->fixture();
        $this->deleteJson(route('payments.destroy', $payment))->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reason_is_escaped_in_history_and_stays_in_the_audit(): void
    {
        [, $invoice, $payment] = $this->fixture();
        $reason = '<script>incorrect receipt</script>';
        $this->post(route('payments.reverse', $payment), array_merge($this->payload($payment), ['reason' => $reason]))->assertSessionHasNoErrors();
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee($reason)->assertDontSee($reason, false);
        $this->assertSame($reason, ActivityLog::where('action', 'payment.reversed')->sole()->properties['reason']);
    }

    public function test_database_rejects_a_second_reversal_link(): void
    {
        [, , $payment] = $this->fixture();
        $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasNoErrors();
        $copy = Payment::where('reversal_of_payment_id', $payment->id)->sole()->replicate();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $copy->save();
    }

    public function test_sale_status_and_history_reconcile_for_direct_and_legacy_invoice_links(): void
    {
        foreach ([false, true] as $legacy) {
            [$org, $invoice, $payment] = $this->fixture();
            $customer = \App\Models\Customer::create(['organization_id' => $org->id, 'name' => 'Correction sale customer', 'phone' => '9876500001']);
            $product = \App\Models\Product::create(['organization_id' => $org->id, 'name' => 'Correction test product', 'type' => 'sale', 'total_quantity' => 2, 'available_quantity' => 2, 'sale_price' => 1000, 'price_per_day' => 0, 'rental_price' => 0]);
            $sale = \App\Models\Sale::create([
                'organization_id' => $org->id, 'customer_id' => $customer->id, 'product_id' => $product->id,
                'quantity' => 1, 'unit_price' => 1000, 'sale_date' => today(), 'sale_amount' => 1000, 'payment_status' => 'paid',
            ]);
            if ($legacy) {
                $invoice->items()->create(['source_type' => 'sale', 'source_id' => $sale->id, 'description' => 'Test sale', 'quantity' => 1, 'rate' => 1000, 'line_total' => 1000]);
            } else {
                $invoice->update(['sale_id' => $sale->id]);
            }
            $payment->update(['amount' => 1000]);
            $invoice->syncFinancialStatus();
            $this->post(route('payments.reverse', $payment), $this->payload($payment))->assertSessionHasNoErrors();
            $this->assertSame('pending', $sale->fresh()->payment_status);
            $this->get(route('invoices.show', $invoice))->assertOk();
            $this->assertSame(2, $invoice->payments()->count());
            $this->assertEquals(1000, $invoice->fresh()->balance_amount);
        }
    }

    public function test_correction_masks_amount_for_custom_delete_only_role(): void
    {
        [$org, , $payment] = $this->fixture();
        $role = \App\Models\Role::create([
            'organization_id' => $org->id, 'name' => 'Correction only', 'slug' => 'correction-only',
            'permissions' => ['payments' => ['delete']], 'is_active' => true,
        ]);
        $user = TestData::user($org, ['role' => 'staff', 'role_id' => $role->id]);
        $this->actingAs($user);
        $this->assertFalse($user->canViewFinance());
        $this->assertFalse($user->canAccessModule('payments', 'read'));
        $this->get(route('payments.correction', $payment))->assertOk()
            ->assertViewHas('canViewFinance', false)->assertSee('Restricted')
            ->assertDontSee('400.00')->assertSee('name="reason"', false);

        $role->update(['permissions' => ['payments' => ['delete'], '__special' => ['dashboard.finance.full']]]);
        $this->actingAs($user->fresh());
        $this->get(route('payments.correction', $payment))->assertOk()
            ->assertViewHas('canViewFinance', true)->assertSee('400.00')->assertDontSee('Restricted');
    }
}
