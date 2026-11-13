<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry, Quotation, RentalAgreement, User};
use App\Services\Sales\{RentalAgreements, RentalAgreementTerms};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalTermsSharingTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private Enquiry $enquiry;

    protected function setUp(): void
    {
        parent::setUp(); Mail::fake(); config(['app.rental_agreements'=>true]);
        $this->admin=TestData::user();$this->actingAs($this->admin);
        $this->enquiry=Enquiry::create(['organization_id'=>$this->admin->organization_id,'owner_id'=>$this->admin->id,
            'name'=>'Synthetic prospect','phone'=>'+919876543210','subject'=>'Oxygen concentrator rental','intent'=>'rental','stage'=>'quoted','source'=>'incoming_phone']);
    }

    private function quote(array $extra=[]): Quotation
    {
        return Quotation::create($extra+['organization_id'=>$this->admin->organization_id,'enquiry_id'=>$this->enquiry->id,
            'revision'=>1,'kind'=>'rental','status'=>'sent','valid_until'=>now()->addDays(7)->toDateString(),
            'snapshot'=>['recipient'=>['phone'=>'+919876543210'],'timezone'=>'Asia/Kolkata','currency'=>'INR','total'=>3500]]);
    }

    public function test_json_preparation_is_idempotent_private_and_does_not_mark_the_quote_shared(): void
    {
        $quote=$this->quote();
        foreach ([1,2] as $attempt) {
            $response=$this->postJson(route('quotations.rental-agreement.issue',$quote),['destination'=>'whatsapp'])->assertOk();
            $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
            $this->assertSame('no-referrer',$response->headers->get('Referrer-Policy'));
            $url=$response->json('whatsapp_url');$this->assertStringStartsWith('https://wa.me/919876543210?',$url);
            parse_str(parse_url($url,PHP_URL_QUERY),$query);
            $this->assertStringContainsString(RentalAgreementTerms::TEXT,$query['text']);
            $this->assertStringContainsString('/rental-agreement/',$query['text']);
        }
        $this->assertDatabaseCount('rental_agreements',1);
        $this->assertNull($quote->fresh()->shared_at);$this->assertSame('sent',$quote->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_unsigned_copy_matches_signing_text_and_signed_copy_keeps_accepted_snapshot(): void
    {
        $service=app(RentalAgreements::class);
        $agreement=RentalAgreement::create(['organization_id'=>$this->admin->organization_id,'quotation_id'=>$this->quote()->id,'terms'=>'Old unsigned draft']);
        $unsigned=view('sales-workspace.terms-preview',['agreements'=>$service,'agreement'=>$agreement,'previewReference'=>'Quotation Q-test','allowTermsMessage'=>true])->render();
        $this->assertStringContainsString(e(RentalAgreementTerms::TEXT),$unsigned);
        $this->assertStringNotContainsString('Old unsigned draft',$unsigned);
        $agreement->update(['signed_at'=>now(),'terms'=>'Accepted terms <retain this exact snapshot>']);
        $signed=view('sales-workspace.terms-preview',['agreements'=>$service,'agreement'=>$agreement,'previewReference'=>'Quotation Q-test','allowTermsMessage'=>true])->render();
        $this->assertStringContainsString('Accepted terms &lt;retain this exact snapshot&gt;',$signed);
        $this->assertStringNotContainsString(RentalAgreementTerms::TEXT,$signed);
        $this->assertStringContainsString('Copy message with signed-copy link',$signed);
        $this->assertStringContainsString('/signed-copy?',$service->message($agreement,'Quotation Q-test'));
        $readonly=view('sales-workspace.terms-preview',['agreements'=>$service,'agreement'=>$agreement,'allowTermsMessage'=>false])->render();
        $this->assertStringContainsString('Copy rental terms',$readonly);
        $this->assertStringNotContainsString('data-terms-message',$readonly);
        $this->assertStringNotContainsString('/signed-copy?',$readonly);
    }

    public function test_terms_can_be_previewed_and_copied_before_a_quotation_exists_without_creating_agreement(): void
    {
        $this->enquiry->update(['stage'=>'new']);
        $this->get(route('enquiries.show',$this->enquiry))->assertOk()->assertSee('Preview / copy rental terms')->assertSee('Copy rental terms');
        $this->assertDatabaseCount('rental_agreements',0);$this->assertDatabaseCount('quotations',0);
    }

    public function test_json_preparation_preserves_quote_scope_and_issuance_guards(): void
    {
        $quote=$this->quote();$url=route('quotations.rental-agreement.issue',$quote);
        $this->actingAs(TestData::user())->postJson($url)->assertNotFound();
        $this->actingAs(TestData::user($this->admin->organization,['role'=>User::ROLE_DELIVERY]))->postJson($url)->assertForbidden();
        $this->actingAs(TestData::user($this->admin->organization,['role'=>User::ROLE_SALES]))
            ->postJson($url)->assertOk();
        $quote->update(['valid_until'=>now()->subDay()->toDateString()]);
        $this->postJson($url)->assertConflict();$this->assertDatabaseCount('rental_agreements',1);
    }

    public function test_json_preparation_cannot_issue_for_a_draft_or_closed_enquiry(): void
    {
        $quote=$this->quote(['status'=>'draft']);$url=route('quotations.rental-agreement.issue',$quote);
        $this->postJson($url)->assertConflict();
        $quote->update(['status'=>'sent']);$this->enquiry->update(['stage'=>'lost']);
        $this->postJson($url)->assertConflict();$this->assertDatabaseCount('rental_agreements',0);
    }
}
