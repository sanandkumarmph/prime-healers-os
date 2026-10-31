<?php

namespace Tests\Feature\Regression;

use App\Models\Enquiry;
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
        $this->travelBack();
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        return new DOMXPath($document);
    }
}
