<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\Metrics\InvoiceMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class InvoicePaymentPresentationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-27 12:00:00');
        $this->withoutVite();
        $this->organization = TestData::organization();
        $this->actingAs(TestData::user($this->organization));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(?Organization $organization = null): Invoice
    {
        $organization ??= $this->organization;
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic invoice customer',
        ]);

        return Invoice::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'PRESENT-' . Str::random(10),
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

    private function payment(Invoice $invoice, float $amount, string $date, ?int $organizationId = null): void
    {
        Payment::create([
            'organization_id' => $organizationId ?? $invoice->organization_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'payment_date' => $date,
            'amount' => $amount,
            'payment_method' => 'other',
        ]);
    }

    public function test_mark_paid_replay_shows_its_validation_message_and_keeps_one_receipt(): void
    {
        $invoice = $this->invoice();
        $list = route('invoices.index', ['search' => $invoice->invoice_number]);
        $payload = ['payment_submission_token' => (string) Str::uuid()];
        $this->from($list)->post(route('invoices.markPaid', $invoice->id), $payload)
            ->assertRedirect(route('invoices.show', $invoice->id));
        $this->from($list)->post(route('invoices.markPaid', $invoice->id), $payload)
            ->assertRedirect($list)->assertSessionHasErrors('payment_submission_token');

        $this->get($list)->assertOk()->assertSeeText(
            'This payment form has already been submitted. Reload the page before recording another payment.'
        );
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_submissions', 1);
        $this->assertSame(100.0, (float) Payment::sum('amount'));
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_amount);
    }

    public function test_desktop_and_mobile_full_balance_actions_are_labelled_mark_paid(): void
    {
        $invoice = $this->invoice();
        $response = $this->get(route('invoices.index'))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//button[@form="markPaidInvoice' . $invoice->id . '"][@title]');
        $this->assertSame(2, $buttons->length);
        foreach ($buttons as $button) {
            $this->assertSame('Mark Paid', $button->getAttribute('title'));
            $this->assertSame('Mark Paid', $button->getAttribute('aria-label'));
        }
    }

    public function test_mark_paid_receipt_populates_filtered_collection_figures(): void
    {
        $invoice = $this->invoice();
        $other = $this->invoice();
        $this->payment($other, 90, '2026-10-27');
        $this->post(route('invoices.markPaid', $invoice->id), [
            'payment_submission_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $response = $this->get(route('invoices.index', ['search' => $invoice->invoice_number]))->assertOk();
        $stats = $response->viewData('invoiceStats');
        $this->assertSame(1, $stats['totalInvoices']);
        $this->assertSame(100.0, $stats['collectedToday'] ?? null);
        $this->assertSame(100.0, $stats['collectedThisMonth'] ?? null);
    }

    public function test_collection_summary_uses_payment_dates_and_excludes_unmatched_or_cross_organization_receipts(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 10, '2026-10-27');
        $this->payment($invoice, 20.25, '2026-10-02');
        $this->payment($invoice, 30, '2026-09-30');
        $this->payment($invoice, 40, '2026-11-01');
        $this->payment($this->invoice(), 50, '2026-10-27');
        $foreign = TestData::organization(['is_internal' => false]);
        $this->payment($this->invoice($foreign), 60, '2026-10-27');
        $this->payment($invoice, 70, '2026-10-27', $foreign->id);

        $summary = app(InvoiceMetricsService::class)->summary(
            Invoice::query()->forOrganization($this->organization->id)->whereKey($invoice->id)->latest('invoice_date')
        );
        $this->assertSame(10.0, $summary['collectedToday'] ?? null);
        $this->assertSame(30.25, $summary['collectedThisMonth'] ?? null);
    }

    public function test_empty_invoice_filter_returns_zero_collection_figures(): void
    {
        $invoice = $this->invoice();
        $this->payment($invoice, 100, '2026-10-27');
        $response = $this->get(route('invoices.index', ['search' => 'NO-MATCH-FOR-THIS-TEST']))->assertOk();
        $stats = $response->viewData('invoiceStats');
        $this->assertSame(0, $stats['totalInvoices']);
        $this->assertSame(0.0, $stats['collectedToday'] ?? null);
        $this->assertSame(0.0, $stats['collectedThisMonth'] ?? null);
    }
}
