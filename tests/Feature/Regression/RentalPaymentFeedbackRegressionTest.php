<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalPaymentFeedbackRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Rental $rental;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic rental payment customer',
            'phone' => '+12025550100',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Synthetic rental payment product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 2,
            'total_quantity' => 2,
            'rental_price' => 100,
            'price_per_day' => 100,
        ]);
        $this->rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
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
            'invoice_number' => 'RENT-FEEDBACK-' . Str::random(8),
            'invoice_date' => now()->toDateString(),
            'subtotal' => 100,
            'total_amount' => 100,
            'paid_amount' => 0,
            'balance_amount' => 100,
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_payment_replay_shows_its_rejection_without_reopening_renewal(): void
    {
        $show = route('rentals.show', $this->rental);
        $page = $this->get($show)->assertOk();
        $token = $this->xpath($page)->query('//details[@id="record-payment-panel"]//input[@name="payment_submission_token"]')->item(0)->getAttribute('value');
        $this->assertTrue(Str::isUuid($token));
        $payload = $this->paymentPayload($token);
        $endDate = $this->rental->end_date->toDateString();

        $this->from($show)->post(route('rentals.recordPayment', $this->rental), $payload)
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->from($show)->post(route('rentals.recordPayment', $this->rental), $payload)
            ->assertRedirect($show)->assertSessionHasErrors('payment_submission_token');

        $response = $this->get($show)->assertOk()->assertSeeText(
            'This payment form has already been submitted. Reload the page before recording another payment.'
        );
        $this->assertFeedbackContext($response, false);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_submissions', 1);
        $this->assertDatabaseCount('rental_renewals', 0);
        $this->assertSame(50.0, (float) $this->invoice->fresh()->balance_amount);
        $this->assertSame($endDate, $this->rental->fresh()->end_date->toDateString());

        // Reload after the rejection to receive a fresh token for an equal instalment.
        $reloaded = $this->get($show)->assertOk();
        $nextToken = $this->xpath($reloaded)->query('//details[@id="record-payment-panel"]//input[@name="payment_submission_token"]')->item(0)->getAttribute('value');
        $this->assertTrue(Str::isUuid($nextToken));
        $this->assertNotSame($token, $nextToken);
        $payload['payment_submission_token'] = $nextToken;
        $this->post(route('rentals.recordPayment', $this->rental), $payload)
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_submissions', 2);
        $this->assertSame(100.0, (float) $this->invoice->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $this->invoice->fresh()->balance_amount);
        $this->assertDatabaseCount('rental_renewals', 0);
    }

    public function test_invalid_amount_shows_feedback_and_corrected_retry_keeps_its_token_usable(): void
    {
        $show = route('rentals.show', $this->rental);
        $payload = $this->paymentPayload((string) Str::uuid());
        $payload['amount'] = 150;
        $this->from($show)->post(route('rentals.recordPayment', $this->rental), $payload)
            ->assertRedirect($show)->assertSessionHasErrors('amount');

        $this->assertFeedbackContext($this->get($show)->assertOk(), false);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_submissions', 0);
        $payload['amount'] = 50;
        $this->post(route('rentals.recordPayment', $this->rental), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_submissions', 1);
    }

    public static function invalidRenewalFields(): array
    {
        return [
            'renewal-specific date' => ['new_end_date', '2000-01-01'],
            'shared payment method' => ['payment_method', str_repeat('x', 51)],
        ];
    }

    #[DataProvider('invalidRenewalFields')]
    public function test_renewal_form_errors_reopen_renewal_and_show_feedback_inside_it(string $field, string $value): void
    {
        $show = route('rentals.show', $this->rental);
        $page = $this->get($show)->assertOk();
        $marker = $this->xpath($page)->query('//form[@id="renewalModalForm"]//input[@name="_rental_form"]')->item(0);
        $this->assertNotNull($marker);
        $payload = [
            '_rental_form' => $marker->getAttribute('value'),
            'new_end_date' => now()->addDays(14)->toDateString(),
            'rental_amount_added' => 100,
            'payment_method' => 'other',
            $field => $value,
        ];
        $this->from($show)->post(route('rentals.renew', $this->rental), $payload)
            ->assertRedirect($show)->assertSessionHasErrors($field);

        $this->assertFeedbackContext($this->get($show)->assertOk(), true);
        $this->assertDatabaseCount('rental_renewals', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_other_validation_feedback_is_escaped_and_does_not_open_renewal(): void
    {
        $message = '<img src=x onerror="alert(123)">';
        $errors = (new ViewErrorBag())->put('default', new MessageBag(['notes' => [$message]]));
        $response = $this->withSession([
            'errors' => $errors,
            '_old_input' => ['notes' => 'Synthetic non-renewal form'],
        ])->get(route('rentals.show', $this->rental))->assertOk();

        $response->assertSee($message)->assertDontSee($message, false);
        $this->assertFeedbackContext($response, false);
        $this->assertDatabaseCount('payments', 0);
    }

    private function paymentPayload(string $token): array
    {
        return [
            'payment_submission_token' => $token,
            'payment_date' => now()->toDateString(),
            'amount' => 50,
            'payment_method' => 'other',
            'notes' => 'Synthetic equal instalment',
        ];
    }

    private function xpath(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        return new \DOMXPath($document);
    }

    private function assertFeedbackContext(TestResponse $response, bool $renewal): void
    {
        $xpath = $this->xpath($response);
        $this->assertSame(1, $xpath->query('//*[@id="rental-validation-errors"][@role="alert"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[@id="rental-validation-errors"]//li')->length);
        $modal = $xpath->query('//*[@id="renewalModal"]')->item(0);
        $this->assertSame($renewal ? 'true' : 'false', $modal->getAttribute('data-reopen-on-validation'));
        $this->assertSame($renewal ? 1 : 0, $xpath->query('//form[@id="renewalModalForm"]//*[@id="rental-validation-errors"]')->length);
    }
}
