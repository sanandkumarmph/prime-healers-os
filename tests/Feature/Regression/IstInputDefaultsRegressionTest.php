<?php

namespace Tests\Feature\Regression;

use App\Models\Enquiry;
use App\Models\Quotation;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class IstInputDefaultsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_inputs_use_ist_midnight_and_reminder_survives_utc_storage_round_trip(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 00:15:00', 'Asia/Kolkata'));
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $this->actingAs($owner);
        $enquiry = Enquiry::create(['organization_id' => $organization->id, 'owner_id' => $owner->id, 'created_by_user_id' => $owner->id, 'name' => 'IST boundary customer', 'subject' => 'Midnight boundary enquiry', 'stage' => 'new']);
        $response = $this->get(route('enquiries.show', $enquiry))->assertOk();
        $dom = $this->dom($response->getContent());
        $this->assertSame('2026-10-31T10:00', $dom->evaluate('string(//input[@type="hidden" and @name="due_at"]/@value)'));
        $response->assertSee('10:00 · Asia/Kolkata');
        $quote = $this->get(route('quotations.create', $enquiry))->assertOk();
        $this->assertSame('2026-11-06', $this->dom($quote->getContent())->evaluate('string(//input[@name="valid_until"]/@value)'));
        $availability = $this->get(route('availability.index'))->assertOk();
        $availabilityDom = $this->dom($availability->getContent());
        $this->assertSame('2026-10-30', $availabilityDom->evaluate('string(//input[@name="start_date"]/@value)'));
        $this->assertSame('2026-11-05', $availabilityDom->evaluate('string(//input[@name="end_date"]/@value)'));
        $this->post(route('enquiries.follow-up', $enquiry), ['subject' => 'Tomorrow at ten', 'channel' => 'call', 'due_at' => '2026-10-31T10:00'])->assertSessionHasNoErrors();
        $followUp = $enquiry->followUps()->sole();
        $this->assertSame('2026-10-31 04:30:00', $followUp->getRawOriginal('due_at'));
        $this->assertSame('2026-10-31 10:00', $followUp->due_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->get(route('enquiries.show', $enquiry))->assertOk()->assertSee('31 Oct 2026, 10:00');
        $this->travelTo(Carbon::parse('2026-10-31 09:59:59', 'Asia/Kolkata'));
        $beforeDue = $this->get(route('enquiries.index', ['view' => 'followups', 'queue' => 'today']))->assertOk();
        $this->assertSame(1, $beforeDue->viewData('counts')['today']);
        $this->assertSame(0, $beforeDue->viewData('counts')['overdue']);
        $this->travelTo(Carbon::parse('2026-10-31 10:00:01', 'Asia/Kolkata'));
        $afterDue = $this->get(route('enquiries.index', ['view' => 'followups', 'queue' => 'overdue']))->assertOk();
        $this->assertSame(1, $afterDue->viewData('counts')['overdue']);
        $this->assertSame('2026-10-31 04:30:00', $followUp->fresh()->getRawOriginal('due_at'));
        $this->travelBack();
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        return new DOMXPath($document);
    }

    public function test_phos_timezone_is_ist_even_when_deployment_environment_requests_utc(): void
    {
        $before = getenv('APP_TIMEZONE');
        try {
            putenv('APP_TIMEZONE=UTC');
            $configuration = require config_path('app.php');
            $this->assertSame('Asia/Kolkata', $configuration['timezone']);
            $this->assertSame('Asia/Kolkata', date_default_timezone_get());
        } finally {
            putenv($before === false ? 'APP_TIMEZONE' : 'APP_TIMEZONE=' . $before);
        }
    }

    public function test_legacy_quote_is_displayed_in_ist_without_changing_its_dates_or_expiry_instant(): void
    {
        $party = ['name' => 'Synthetic issuer', 'address' => '', 'phone' => '', 'email' => '', 'tax_number' => ''];
        $quote = new Quotation(['enquiry_id' => 1, 'revision' => 1, 'kind' => 'sale', 'status' => 'draft',
            'valid_until' => '2026-10-30', 'created_at' => Carbon::parse('2026-10-30 00:15', 'Asia/Kolkata'),
            'snapshot' => ['timezone' => 'UTC', 'issuer' => $party, 'recipient' => $party, 'lines' => [],
                'tax_label' => 'GST', 'tax_type' => 'igst', 'currency' => 'INR', 'subtotal' => 0,
                'tax' => 0, 'transport' => 0, 'total' => 0, 'terms' => '']]);
        $before = $quote->getAttributes();
        $expiry = $quote->expiresAt()->getTimestamp();
        foreach (['sales-workspace.quote-document', 'sales-workspace.quote-pdf'] as $view) {
            $html = view($view, ['quote' => $quote])->render();
            $this->assertStringContainsString('Issued 30 Oct 2026', $html);
            $this->assertStringContainsString('Valid until 30 Oct 2026', $html);
        }
        $this->assertSame('2026-10-31 05:29:59', $quote->expiresAt()->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'));
        $this->assertSame($expiry, $quote->expiresAt()->getTimestamp());
        $this->assertSame($before, $quote->getAttributes());
        $this->assertSame('UTC', $quote->snapshot['timezone']);
        $this->assertSame('2026-10-30', $quote->valid_until->toDateString());
    }

    public function test_a_new_sale_from_a_legacy_utc_quote_defaults_to_the_current_ist_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 00:15:00', 'Asia/Kolkata'));
        $quote = new Quotation(['kind' => 'sale', 'snapshot' => ['timezone' => 'UTC', 'customer_id' => 1,
            'recipient' => ['name' => 'Synthetic customer', 'phone' => '', 'address' => ''],
            'tax_type' => 'igst', 'transport' => 0, 'lines' => []]]);
        $before = $quote->getAttributes();
        $data = app(\App\Services\Sales\QuotationConversion::class)->prefill($quote);
        $this->assertSame('2026-10-30', $data['sale_date']);
        $this->assertSame($before, $quote->getAttributes());
        $this->travelBack();
    }
}
