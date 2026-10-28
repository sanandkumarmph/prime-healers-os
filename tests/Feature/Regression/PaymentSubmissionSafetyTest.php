<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\PaymentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class PaymentSubmissionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Payment safety customer',
            'phone' => '9876500001',
        ]);

        return Invoice::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'PAY-' . Str::random(8),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
            'subtotal' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
        ]);
    }

    private function payload(?string $token = null, float $amount = 100): array
    {
        return [
            'payment_submission_token' => $token ?? (string) Str::uuid(),
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'payment_method' => 'cash',
        ];
    }

    public function test_duplicate_form_records_one_receipt_and_equal_installments_need_distinct_tokens(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload();
        $url = route('invoices.payments.store', $invoice);

        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(900.0, (float) $invoice->fresh()->balance_amount);

        $this->post($url, $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(800.0, (float) $invoice->fresh()->balance_amount);
    }

    public function test_missing_or_malformed_token_never_records_money(): void
    {
        $invoice = $this->invoice();
        foreach ([null, 'not-a-uuid'] as $token) {
            $this->postJson(route('invoices.payments.store', $invoice), array_merge(
                $this->payload(), ['payment_submission_token' => $token]
            ))->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_submissions', 0);
    }

    public function test_failed_amount_validation_rolls_back_the_claim_and_allows_corrected_retry(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload(amount: 1500);
        $this->postJson(route('invoices.payments.store', $invoice), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payment_submissions', 0);
        $data['amount'] = 100;
        $this->post(route('invoices.payments.store', $invoice), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_submissions', 1);
    }

    public function test_cancelled_invoice_cannot_receive_payment_and_token_is_not_consumed(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['status' => 'cancelled', 'payment_status' => 'cancelled']);
        $this->postJson(route('invoices.payments.store', $invoice), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_submissions', 0);
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_mark_paid_replay_is_rejected_even_after_receipt_correction(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload();
        $url = route('invoices.markPaid', $invoice->id);
        $this->post($url, $data)->assertSessionHasNoErrors();
        $payment = Payment::firstOrFail();
        $corrections = app(\App\Services\Finance\PaymentCorrectionService::class);
        $this->post(route('payments.reverse', $payment), [
            'reason' => 'Incorrect test receipt entry', 'confirm' => 1, 'version' => $corrections->version($payment),
        ])->assertSessionHasNoErrors();
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(0.0, (float) $invoice->payments()->sum('amount'));
        $this->assertDatabaseCount('payment_submissions', 1);
    }

    public function test_token_is_shared_across_payment_entry_points(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload();
        $this->post(route('invoices.payments.store', $invoice), $data)->assertSessionHasNoErrors();
        $this->postJson(route('invoices.markPaid', $invoice->id), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_failed_permission_check_rolls_back_claim(): void
    {
        $invoice = $this->invoice();
        $user = auth()->user();
        $user->update(['role' => \App\Models\User::ROLE_DELIVERY]);
        $this->postJson(route('invoices.payments.store', $invoice), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('payment_submissions', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_other_organization_invoice_is_rejected_without_claim(): void
    {
        $invoice = $this->invoice();
        $this->actingAs(TestData::user(TestData::organization(['name' => 'Other organization'])));
        $this->postJson(route('invoices.payments.store', $invoice), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('payment_submissions', 0);
    }

    public function test_uuid_case_cannot_bypass_replay_protection(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload(strtoupper((string) Str::uuid()));
        $this->post(route('invoices.payments.store', $invoice), $data)->assertSessionHasNoErrors();
        $data['payment_submission_token'] = strtolower($data['payment_submission_token']);
        $this->postJson(route('invoices.payments.store', $invoice), $data)->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_service_rejects_cross_organization_invoice_before_writing(): void
    {
        $invoice = $this->invoice();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PaymentSyncService::class)->createInvoicePayment(
            TestData::organization(['name' => 'Other'])->id, $invoice, $this->payload(), null, true
        );
    }

    public function test_token_component_preserves_valid_input_and_replaces_invalid_input(): void
    {
        $invoice = $this->invoice();
        $token = (string) Str::uuid();
        request()->setLaravelSession(app('session')->driver());
        session()->flashInput(['payment_submission_token' => $token]);
        $html = \Illuminate\Support\Facades\Blade::render('<x-payment-submission-token />');
        $this->assertStringContainsString($token, $html);
        session()->flashInput(['payment_submission_token' => 'invalid']);
        $html = \Illuminate\Support\Facades\Blade::render('<x-payment-submission-token />');
        preg_match('/value="([^"]+)"/', $html, $matches);
        $this->assertTrue(Str::isUuid($matches[1]));
    }

    public function test_failure_after_receipt_creation_rolls_back_money_and_claim(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload();
        $this->mock(\App\Services\NotificationCenterService::class, function ($mock) {
            $mock->shouldReceive('organizationFinanceUsers')->andReturn(collect());
            $mock->shouldReceive('notifyPaymentRecorded')->andThrow(
                \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Injected downstream failure'])
            );
        });
        $this->postJson(route('invoices.payments.store', $invoice), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_submissions', 0);
        $this->assertSame(1000.0, (float) $invoice->fresh()->balance_amount);
    }

    public function test_bulk_mark_paid_is_protected_but_bulk_void_keeps_its_contract(): void
    {
        $invoice = $this->invoice();
        $data = ['invoice_ids' => [$invoice->id], 'bulk_action' => 'mark_paid'];
        $url = route('invoices.bulk.action');
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $data['payment_submission_token'] = (string) Str::uuid();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $this->assertDatabaseCount('payments', 1);

        $other = $this->invoice();
        $this->post($url, ['invoice_ids' => [$other->id], 'bulk_action' => 'void'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $other->fresh()->status);
    }

    public function test_failed_html_submission_keeps_the_same_token_usable(): void
    {
        $invoice = $this->invoice();
        $data = $this->payload(amount: 1500);
        $url = route('invoices.payments.store', $invoice);
        $this->from('/invoices/' . $invoice->id)->post($url, $data)
            ->assertSessionHasErrors('amount')->assertSessionMissing('success');
        $this->assertDatabaseCount('payment_submissions', 0);
        $data['amount'] = 100;
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
    }

    public static function paymentRoutes(): array
    {
        return array_map(fn ($route) => [$route], [
            'payments.store', 'invoices.payments.store', 'invoices.markPaid',
            'rentals.markPaid', 'rentals.recordPayment', 'sales.markPaid',
            'sales.recordPayment', 'invoices.bulk.action',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentRoutes')]
    public function test_every_payment_entrypoint_requires_a_token_and_rejects_replay(string $name): void
    {
        $invoice = $this->invoice();
        $product = \App\Models\Product::create([
            'organization_id' => $invoice->organization_id,
            'name' => 'Payment route product',
            'product_type' => \App\Models\Product::TYPE_RENTABLE,
            'stock_mode' => \App\Models\Product::STOCK_MODE_UNTRACKED,
            'rental_price' => 1000, 'price_per_day' => 1000,
            'sale_price' => 1000, 'available_quantity' => 10, 'total_quantity' => 10,
        ]);
        $rental = \App\Models\Rental::create([
            'organization_id' => $invoice->organization_id,
            'customer_id' => $invoice->customer_id, 'customer_name' => 'Payment safety customer',
            'phone' => '9876500001', 'product_id' => $product->id, 'quantity' => 1,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 1000, 'status' => 'active',
        ]);
        $sale = \App\Models\Sale::create([
            'organization_id' => $invoice->organization_id,
            'customer_id' => $invoice->customer_id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 1000, 'sale_amount' => 1000,
            'sale_date' => now()->toDateString(), 'payment_status' => 'pending',
        ]);
        $isSale = str_starts_with($name, 'sales.');
        $invoice->update($isSale ? ['sale_id' => $sale->id] : ['rental_id' => $rental->id]);
        $parameter = match (true) {
            $name === 'invoices.bulk.action' => [],
            $name === 'invoices.payments.store', $name === 'invoices.markPaid' => [$invoice->id],
            $isSale => [$sale->id],
            default => [$rental->id],
        };
        $url = route($name, $parameter);
        $data = $this->payload();
        if ($name === 'invoices.bulk.action') {
            $data += ['invoice_ids' => [$invoice->id], 'bulk_action' => 'mark_paid'];
        }
        $missing = $data;
        unset($missing['payment_submission_token']);
        $this->postJson($url, $missing)->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $before = Payment::count();
        $this->assertGreaterThan(0, $before);
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payment_submission_token');
        $this->assertSame($before, Payment::count());
    }

    public function test_rendered_invoice_list_and_mobile_action_components_supply_form_tokens(): void
    {
        $invoice = $this->invoice();
        $this->withoutVite();
        $response = $this->get(route('invoices.index'))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $forms = $xpath->query('//form[contains(@action, "/mark-paid")]');
        $this->assertGreaterThan(0, $forms->length);
        foreach ($forms as $form) {
            $tokens = $xpath->query('.//input[@name="payment_submission_token"]', $form);
            $this->assertSame(1, $tokens->length);
            $this->assertTrue(Str::isUuid($tokens->item(0)->getAttribute('value')));
        }

        $actions = [[
            'type' => 'form', 'label' => 'Paid', 'method' => 'POST',
            'action' => route('invoices.markPaid', $invoice->id),
            'payment_submission' => true,
        ]];
        foreach (['operational-action-bar', 'quick-actions-dropdown'] as $component) {
            $html = \Illuminate\Support\Facades\Blade::render(
                '<x-' . $component . ' :actions="$actions" />', ['actions' => $actions]
            );
            $this->assertStringContainsString('name="payment_submission_token"', $html);
        }
    }
}
