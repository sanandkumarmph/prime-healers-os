<?php
namespace Tests\Feature\Regression;

use App\Models\CustomerPaymentAccount;
use App\Models\CustomerPaymentLink;
use App\Models\CustomerPaymentReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerPayments\CustomerPaymentLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestData;
use Tests\TestCase;

class PhosCustomerPaymentLinksTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private $invoice;

    private $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(\App\Services\CustomerPayments\UatPaymentPosting::class)->shouldReceive('enabled')->andReturn(true);
        Http::preventStrayRequests();
        $this->user = TestData::user(TestData::organization());
        $this->invoice = Invoice::create(['organization_id' => $this->user->organization_id, 'invoice_number' => 'INV-1', 'invoice_date' => today(), 'bill_to_name' => 'Asha', 'bill_to_email' => 'asha@example.test', 'total_amount' => 118, 'balance_amount' => 118, 'paid_amount' => 0, 'status' => 'unpaid']);
        $this->account = CustomerPaymentAccount::create(['organization_id' => $this->user->organization_id, 'mode' => 'test', 'webhook_token' => (string) Str::uuid(), 'key_id' => 'rzp_test_fixture', 'key_secret' => 'fixture_key_secret', 'key_fingerprint' => hash('sha256', 'fixture'), 'webhook_secret' => 'fixture_webhook_secret', 'is_active' => true]);
        $this->actingAs($this->user);
        $this->fake(fn ($request) => Http::response($this->entity(CustomerPaymentLink::latest('id')->first(), ['status' => 'created']), 200));
    }

    private function fake(callable $callback): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function create(bool $reminders = false): CustomerPaymentLink
    {
        $this->post(route('customer-payments.create', $this->invoice->id), ['reminders' => $reminders])->assertSessionHasNoErrors();

        return CustomerPaymentLink::latest('id')->firstOrFail();
    }

    private function entity(CustomerPaymentLink $link, array $extra = []): array
    {
        return $extra + ['id' => 'plink_Fixture'.$link->id, 'reference_id' => $link->reference, 'currency' => 'INR', 'amount' => $link->amount_minor, 'amount_paid' => $link->amount_minor, 'status' => 'paid', 'accept_partial' => false, 'order_id' => 'order_Fixture', 'short_url' => 'https://rzp.io/rzp/fixture'];
    }

    private function event(CustomerPaymentLink $link, array $payment = []): array
    {
        return ['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => $this->entity($link)], 'payment' => ['entity' => $payment + ['id' => 'pay_Fixture', 'order_id' => 'order_Fixture', 'currency' => 'INR', 'amount' => $link->amount_minor, 'status' => 'captured', 'captured' => true, 'method' => 'upi', 'created_at' => now()->timestamp, 'amount_refunded' => 0]]]];
    }

    private function webhook(array $event, ?string $signature = null)
    {
        $raw = json_encode($event);

        return $this->call('POST', route('customer-payments.webhook', $this->account->webhook_token), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, $this->account->webhook_secret), 'HTTP_X_RAZORPAY_EVENT_ID' => 'event_fixture'], $raw);
    }

    public function test_default_link_sends_no_notifications_and_duplicate_creation_is_blocked(): void
    {
        $link = $this->create();
        Http::assertSent(fn ($r) => $r['amount'] === 11800 && $r['accept_partial'] === false && ! $r['reminder_enable'] && ! $r['notify']['email'] && ! $r['notify']['sms'] && ! isset($r['customer']));
        $this->assertSame('active', $link->status);
        $this->post(route('customer-payments.create', $this->invoice->id))->assertSessionHasErrors('payment_link');
        Http::assertSentCount(1);
    }

    public function test_notifications_require_opt_in_and_valid_billing_email(): void
    {
        $this->invoice->update(['bill_to_email' => null]);
        $this->post(route('customer-payments.create', $this->invoice->id), ['reminders' => 1])->assertSessionHasErrors('payment_link');
        Http::assertNothingSent();
        $this->invoice->update(['bill_to_email' => 'asha@example.test']);
        $this->create(true);
        Http::assertSent(fn ($r) => $r['reminder_enable'] && $r['notify']['email'] && $r['customer']['email'] === 'asha@example.test');
    }

    public function test_verified_payment_is_recorded_once_even_when_event_id_changes(): void
    {
        $link = $this->create();
        $event = $this->event($link);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('customer_payment_receipts', 1);
        $this->assertSame('test_paid', $link->fresh()->status);
        $this->assertEquals(0, $this->invoice->fresh()->balance_amount);
        $this->assertSame('recorded_test', CustomerPaymentReceipt::sole()->status);
        $this->assertSame('upi', Payment::sole()->payment_method);
    }

    public function test_bad_signature_amount_currency_and_order_are_rejected_without_ledger_changes(): void
    {
        $link = $this->create();
        $this->webhook($this->event($link), str_repeat('0', 64))->assertUnauthorized();
        foreach ([['amount' => 100], ['currency' => 'USD'], ['order_id' => 'order_other'], ['captured' => false]] as $change) {
            $this->webhook($this->event($link, $change))->assertUnprocessable();
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('customer_payment_receipts', 0);
    }

    public function test_tenant_and_read_only_permissions_prevent_collection_and_credentials_access(): void
    {
        $other = TestData::user();
        $this->actingAs($other)->get(route('customer-payments.invoice', $this->invoice->id))->assertNotFound();
        $this->actingAs($other)->post(route('customer-payments.create', $this->invoice->id))->assertNotFound();
        $role = Role::create(['organization_id' => $this->user->organization_id, 'name' => 'Read invoices', 'slug' => 'read_invoices', 'scope_type' => 'all', 'is_active' => true, 'permissions' => ['invoices' => ['read']]]);
        $this->actingAs(TestData::user($this->user->organization, ['role_id' => $role->id]));
        $this->post(route('customer-payments.create', $this->invoice->id))->assertForbidden();
        $this->get(route('customer-payments.settings'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_manual_payment_and_amount_change_blocked_while_live_link_is_open(): void
    {
        $this->create();
        foreach ([fn () => Payment::create(['organization_id' => $this->user->organization_id, 'invoice_id' => $this->invoice->id, 'payment_date' => today(), 'amount' => 10, 'payment_method' => 'cash']), fn () => $this->invoice->update(['total_amount' => 50])] as $action) {
            try {
                $action();
                $this->fail('Expected active link protection.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('payment_link', $e->errors());
            }
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(118, $this->invoice->fresh()->total_amount);
    }

    public function test_late_payment_after_invoice_changed_is_retained_for_review(): void
    {
        $link = $this->create();
        DB::table('invoices')->where('id', $this->invoice->id)->update(['total_amount' => 10, 'balance_amount' => 10]);
        $this->webhook($this->event($link))->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('needs_review', CustomerPaymentReceipt::sole()->status);
        $this->assertSame('needs_review', $link->fresh()->status);
    }

    public function test_network_timeout_preserves_reference_for_recovery_without_retrying_post(): void
    {
        $this->fake(fn () => throw new ConnectionException('fixture timeout'));
        $this->post(route('customer-payments.create', $this->invoice->id))->assertSessionHasErrors('payment_link');
        $link = CustomerPaymentLink::sole();
        $this->assertSame('unknown', $link->status);
        $this->flushSession();
        $this->fake(fn ($r) => Http::response(['payment_links' => [$this->entity($link, ['status' => 'created'])]], 200));
        $this->post(route('customer-payments.action', [$this->invoice->id, $link->id]), ['action' => 'refresh'])->assertSessionHasNoErrors();
        $this->assertSame('active', $link->fresh()->status);
    }

    public function test_cancel_confirmation_unlocks_invoice_and_stale_event_does_not_reopen_it(): void
    {
        $link = $this->create();
        $this->fake(fn ($r) => Http::response($this->entity($link, ['status' => str_ends_with($r->url(), '/cancel') ? 'cancelled' : 'created']), 200));
        $this->post(route('customer-payments.action', [$this->invoice->id, $link->id]), ['action' => 'cancel'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $link->fresh()->status);
        $this->invoice->update(['total_amount' => 100]);
        app(CustomerPaymentLinks::class)->adopt($link->fresh(), $this->entity($link, ['status' => 'created']));
        $this->assertSame('cancelled', $link->fresh()->status);
    }


    public function test_test_receipts_do_not_post_when_actual_uat_identity_guard_is_disabled(): void
    {
        $this->mock(\App\Services\CustomerPayments\UatPaymentPosting::class)->shouldReceive('enabled')->andReturn(false);
        $link = $this->create();
        $this->webhook($this->event($link))->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('test', CustomerPaymentReceipt::sole()->status);
        $this->assertEquals(118, $this->invoice->fresh()->balance_amount);
    }

    public function test_uat_rejects_live_accounts_before_any_gateway_request(): void
    {
        $this->account->update(['mode'=>'live', 'key_id'=>'rzp_live_fixture']);
        $this->post(route('customer-payments.create', $this->invoice->id))->assertSessionHasErrors('payment_link');
        $this->assertDatabaseCount('customer_payment_links', 0);
        Http::assertNothingSent();
    }

    public function test_finance_and_delivery_cannot_change_gateway_credentials_or_collect(): void
    {
        foreach ([User::ROLE_FINANCE, User::ROLE_DELIVERY] as $role) {
            $this->actingAs(TestData::user($this->user->organization, ['role'=>$role]));
            $this->get(route('customer-payments.settings'))->assertForbidden();
            $this->post(route('customer-payments.settings.save'), [])->assertForbidden();
            $this->post(route('customer-payments.create', $this->invoice->id))->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_secrets_are_encrypted_hidden_and_not_flashed_after_invalid_settings(): void
    {
        $this->assertNotSame('fixture_key_secret', $this->account->getRawOriginal('key_secret'));
        $this->assertArrayNotHasKey('key_secret', $this->account->toArray());
        $this->get(route('customer-payments.settings'))->assertRedirect(route('organization.settings.edit').'#payments');
        $this->get(route('organization.settings.edit'))->assertOk()->assertDontSee('fixture_key_secret');
        $this->post(route('customer-payments.settings.save'), ['mode'=>'test', 'key_id'=>'rzp_test_new',
            'key_secret'=>'secret-should-never-be-flashed', 'current_password'=>'incorrect'])->assertSessionHasErrors('current_password');
        $this->assertNull(session()->getOldInput('key_secret'));
        $this->assertNull(session()->getOldInput('key_id'));
        Http::assertNothingSent();
    }

    public function test_settings_accept_test_keys_with_current_password_and_refuse_live_mode(): void
    {
        $this->post(route('customer-payments.settings.save'), ['mode'=>'live','key_id'=>'rzp_live_new',
            'key_secret'=>'fixture-secret-new','current_password'=>'password'])->assertSessionHasErrors('mode');
        $this->post(route('customer-payments.settings.save'), ['mode'=>'test','key_id'=>'rzp_test_new',
            'key_secret'=>'fixture-secret-new','current_password'=>'password'])->assertSessionHasNoErrors();
        $this->assertFalse($this->account->fresh()->is_active);
        $this->assertSame('test', CustomerPaymentAccount::where('is_active',true)->sole()->mode);
        Http::assertNothingSent();
    }

    public function test_razorpay_receipt_cannot_be_deleted_or_reversed_as_manual_cash(): void
    {
        $link = $this->create();
        $this->webhook($this->event($link))->assertOk();
        $payment = Payment::sole();
        foreach ([fn () => $payment->delete(), fn () => Payment::create(['organization_id'=>$payment->organization_id,
            'invoice_id'=>$payment->invoice_id, 'payment_date'=>today(), 'amount'=>-118,
            'reversal_of_payment_id'=>$payment->id, 'payment_method'=>'cash'])] as $attempt) {
            try { $attempt(); $this->fail('Gateway receipt must remain intact.'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('payment_link', $error->errors()); }
        }
        $this->assertDatabaseCount('payments',1);
        $this->assertSame('company', $payment->collection_recipient);
    }

    public function test_refresh_recovers_missed_webhook_and_status_poll_never_calls_gateway(): void
    {
        $link = $this->create();
        $event = $this->event($link);
        $this->fake(fn ($request) => Http::response(str_contains($request->url(), '/payments/')
            ? $event['payload']['payment']['entity'] : $event['payload']['payment_link']['entity']
                + ['payments'=>[['payment_id'=>'pay_Fixture']]], 200));
        $this->post(route('customer-payments.action', [$this->invoice->id,$link->id]), ['action'=>'refresh'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments',1);
        $this->fake(fn () => throw new \RuntimeException('Polling cannot call the gateway.'));
        $this->getJson(route('customer-payments.status', $this->invoice->id))->assertOk()->assertJsonStructure(['version']);
        Http::assertNothingSent();
    }

    public function test_gateway_failure_messages_never_include_the_provider_body_or_credentials(): void
    {
        $this->fake(fn () => Http::response(['error'=>['description'=>'private-provider-details fixture_key_secret']],401));
        $this->post(route('customer-payments.create',$this->invoice->id))->assertSessionHasErrors('payment_link');
        $this->assertSame('failed',CustomerPaymentLink::sole()->status);
        $this->assertStringNotContainsString('private-provider-details', json_encode(session('errors')->getBag('default')->all()));
    }

    public function test_mobile_workspace_exposes_whatsapp_and_exportable_synthetic_browser_fixtures(): void
    {
        $this->withoutExceptionHandling();
        $this->invoice->update(['bill_to_phone'=>'9000000000']);
        $link = $this->create();
        $response = $this->get(route('customer-payments.invoice',$this->invoice->id));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(str_contains($response->getContent(), 'Share on WhatsApp'), 'Missing WhatsApp action.');
        $this->assertTrue(str_contains($response->getContent(), 'No real money is collected'), 'Missing test payment notice.');
        $settings = $this->get(route('organization.settings.edit'))->assertOk();
        if ($dir = getenv('PHOS_PAYMENT_PREVIEW_DIR')) {
            if (!is_dir($dir)) mkdir($dir,0777,true);
            file_put_contents($dir.'/payment-link.html',$response->getContent());
            file_put_contents($dir.'/payment-settings.html',$settings->getContent());
        }
    }

    public function test_company_settings_shows_webhook_and_separate_payment_form_without_a_sidebar_entry(): void
    {
        $response = $this->get(route('organization.settings.edit'))->assertOk();
        $html = $response->getContent();
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//section[@data-org-section="payments"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="webhook-url"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-toggle-secret="webhook-secret"]')->length);
        $this->assertSame($this->account->webhook_secret, $xpath->evaluate('string(//*[@id="webhook-secret"]/@value)'));
        $this->assertSame(0, $xpath->query('//form//form')->length);
        $this->assertSame('customerPaymentAccountForm', $xpath->evaluate('string(//*[@name="key_secret"]/@form)'));
        $this->assertSame(0, $xpath->query('//*[@data-sidebar-group="organization-settings"]//a[@href="'.route('customer-payments.settings').'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="gateway-mode"]/option[@value="live" and @disabled]')->length);
    }

    public function test_company_settings_explains_webhook_availability_before_api_keys_are_saved(): void
    {
        $this->account->delete();
        $html = $this->get(route('organization.settings.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Save your test API keys below to generate your webhook URL and secret.', $html);
        $this->assertStringNotContainsString('id="webhook-secret"', $html);
    }
}
