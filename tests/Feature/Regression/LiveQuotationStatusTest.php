<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry, EnquiryActivity, Product, Quotation, RentalAgreement, Role, User};
use App\Services\Sales\{RentalAgreements, SalesWorkspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class LiveQuotationStatusTest extends TestCase
{
    use RefreshDatabase;
    private User $sales;
    private Enquiry $enquiry;
    private Quotation $quote;

    protected function setUp(): void
    {
        parent::setUp();Mail::fake();config(['app.rental_agreements'=>true]);
        $this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(11,0));
        $org=TestData::organization(['currency_code'=>'INR','country_code'=>'IN','state'=>'Karnataka']);
        $role=Role::create(['organization_id'=>$org->id,'name'=>'Sales','slug'=>'sales','is_active'=>true,'is_system'=>true,
            'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $this->sales=TestData::user($org,['role'=>User::ROLE_SALES,'role_id'=>$role->id]);$this->actingAs($this->sales);
        $this->post(route('enquiries.store'),['name'=>'Private Person','phone'=>'+919876543210','email'=>'private@example.test',
            'subject'=>'Synthetic live quotation','owner_id'=>$this->sales->id,'intent'=>'rental','source'=>'incoming_phone'])->assertSessionHasNoErrors();
        $this->enquiry=Enquiry::sole();
        $product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic rental unit','product_type'=>'both','stock_mode'=>'untracked',
            'is_rentable'=>true,'is_sellable'=>true,'total_quantity'=>20,'available_quantity'=>20,'price_per_day'=>100,'rental_price'=>3500,'sale_price'=>35000]);
        $this->post(route('quotations.store',$this->enquiry),['kind'=>'rental','valid_until'=>'2026-11-20','start_date'=>'2026-11-09','end_date'=>'2026-12-08',
            'transport'=>600,'deposit'=>2000,'tax_type'=>'cgst_sgst','customer_id'=>null,'enquiry_lock_version'=>$this->enquiry->fresh()->lock_version,
            'lines'=>[['product_id'=>$product->id,'quantity'=>1,'rate'=>3500,'tax_rate'=>5,'tax_mode'=>'inclusive']]])->assertSessionHasNoErrors();
        $this->quote=Quotation::sole();
        $this->post(route('quotations.action',$this->quote),['action'=>'send','lock_version'=>$this->quote->lock_version])->assertSessionHasNoErrors();
        $this->quote->refresh();
    }

    private function pollStatus(): \Illuminate\Testing\TestResponse { return $this->getJson(route('quotations.status',$this->quote))->assertOk(); }
    private function saveFixture(string $name,string $content): void
    {
        if($folder=getenv('PHOS_LIVE_QUOTE_RENDER_DIR')) { if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$name,$content); }
    }
    private function sign(): void
    {
        $image=imagecreatetruecolor(760,280);imagefill($image,0,0,0xffffff);imageline($image,30,160,600,70,0x172b4d);
        ob_start();imagepng($image);$signature=base64_encode(ob_get_clean());imagedestroy($image);
        app(RentalAgreements::class)->sign(RentalAgreement::sole(),['name'=>'Private Signer','id_reference'=>'0123','signature'=>$signature],$this->quote->fresh());
    }

    public function test_live_status_is_scoped_private_and_reads_without_mutating_records(): void
    {
        $before=[Enquiry::sole()->getRawOriginal(),Quotation::sole()->getRawOriginal(),RentalAgreement::sole()->getRawOriginal(),EnquiryActivity::count()];
        $response=$this->pollStatus()->assertJsonPath('agreement_state','pending')->assertJsonPath('can_record_response',true)->assertJsonPath('order_action',null);
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer',$response->headers->get('Referrer-Policy'));
        foreach(['Private Person','9876543210','private@example.test','proof','signature=','signed-copy?'] as $private) $this->assertStringNotContainsString($private,$response->getContent());
        $after=[Enquiry::sole()->getRawOriginal(),Quotation::sole()->getRawOriginal(),RentalAgreement::sole()->getRawOriginal(),EnquiryActivity::count()];
        $this->assertSame($before,$after);Mail::assertNothingSent();
        $this->actingAs(TestData::user($this->sales->organization,['role'=>User::ROLE_SALES]))->getJson(route('quotations.status',$this->quote))->assertNotFound();
        $this->actingAs(TestData::user())->getJson(route('quotations.status',$this->quote))->assertNotFound();
        $this->actingAs(TestData::user($this->sales->organization,['role'=>User::ROLE_DELIVERY]))->getJson(route('quotations.status',$this->quote))->assertForbidden();
    }

    public function test_customer_acceptance_and_signature_are_visible_on_the_next_poll_without_reloading(): void
    {
        $pending=$this->pollStatus();$version=$pending->json('version');$this->saveFixture('pending.json',$pending->getContent());
        $this->saveFixture('quotation.html',$this->get(route('quotations.show',$this->quote))->assertOk()->getContent());
        $this->saveFixture('enquiry.html',$this->get(route('enquiries.show',$this->enquiry))->assertOk()->getContent());
        $url=app(SalesWorkspace::class)->shareUrl($this->quote);
        $this->post($url,['decision'=>'accepted','contact_name'=>'Private Person','note'=>'Confirmed on customer link','acknowledge'=>1])->assertSessionHasNoErrors();
        $accepted=$this->pollStatus()->assertJsonPath('quotation_status','accepted')->assertJsonPath('agreement_state','pending')->assertJsonPath('order_action',null);
        $this->assertNotSame($version,$accepted->json('version'));$this->saveFixture('accepted.json',$accepted->getContent());
        $this->sign();
        $signed=$this->pollStatus()->assertJsonPath('agreement_state','signed')->assertJsonPath('agreement_ready',true)->assertJsonPath('order_action.label','Create Rental');
        $this->assertStringContainsString('/rentals/create?quotation_id=',$signed->json('order_action.url'));
        $this->assertStringNotContainsString('signature=',$signed->getContent());$this->assertStringNotContainsString('Private Signer',$signed->getContent());
        $this->saveFixture('signed.json',$signed->getContent());
    }

    public function test_staff_response_reuses_recipient_but_still_requires_confirmation_evidence(): void
    {
        $url=route('quotations.action',$this->quote);
        $this->post($url,['action'=>'accepted','lock_version'=>$this->quote->lock_version])->assertSessionHasErrors('note')->assertSessionDoesntHaveErrors('contact_name');
        $this->post($url,['action'=>'accepted','lock_version'=>$this->quote->lock_version,'note'=>'Confirmed by incoming phone call.'])->assertSessionHasNoErrors();
        $this->assertSame('accepted',$this->quote->fresh()->status);
        $this->assertDatabaseHas('enquiry_activities',['type'=>'quotation.accepted','body'=>$this->quote->number().' accepted by Private Person. Recorded by staff. Confirmed by incoming phone call.']);
    }

    public function test_staff_can_optionally_record_a_different_contact_and_public_name_remains_required(): void
    {
        $url=app(SalesWorkspace::class)->shareUrl($this->quote);
        $this->post($url,['decision'=>'accepted','acknowledge'=>1])->assertSessionHasErrors('contact_name');
        $this->post(route('quotations.action',$this->quote),['action'=>'accepted','lock_version'=>$this->quote->lock_version,'contact_name'=>'Authorized family contact','note'=>'Customer confirmed through family contact.'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('Authorized family contact',EnquiryActivity::where('type','quotation.accepted')->sole()->body);
    }

    public function test_superseded_quotation_stops_live_actions_and_links_the_current_revision(): void
    {
        $this->post(route('quotations.action',$this->quote),['action'=>'revise','lock_version'=>$this->quote->lock_version])->assertSessionHasNoErrors();
        $next=Quotation::latest('revision')->firstOrFail();
        $this->pollStatus()->assertJsonPath('current',false)->assertJsonPath('quotation_status','superseded')->assertJsonPath('terminal',true)
            ->assertJsonPath('order_action',null)->assertJsonPath('latest_url',route('quotations.show',$next));
    }

    public function test_authenticated_signed_pdf_requires_actual_signature_and_existing_update_access(): void
    {
        $url=route('quotations.signed-agreement',$this->quote);$this->get($url)->assertNotFound();
        $this->sign();$response=$this->get($url)->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF',$response->getContent());
        $this->actingAs(TestData::user())->get($url)->assertNotFound();
    }

    public function test_initially_approved_exception_keeps_a_slot_for_a_later_real_signed_copy(): void
    {
        $agreements=app(RentalAgreements::class);$agreement=$agreements->quotation($this->quote);
        $this->post(route('quotations.signature-exception',$this->quote),['lock_version'=>$this->quote->lock_version,
            'exception_reason'=>'Customer cannot use the digital pad; terms explained by telephone.',
            'alternative_acceptance_confirmed'=>1,'exception_context'=>$agreements->exceptionContext($agreement,$this->quote)])->assertSessionHasNoErrors();
        $this->post(route('quotations.action',$this->quote),['action'=>'accepted','lock_version'=>$this->quote->fresh()->lock_version,
            'note'=>'Customer accepted the quotation by telephone.'])->assertSessionHasNoErrors();
        $exception=$this->pollStatus()->assertJsonPath('agreement_state','exception')->assertJsonPath('signed_copy_url',null);
        $html=$this->get(route('enquiries.show',$this->enquiry))->assertOk()->assertSee('Signature exception approved')->assertDontSee('Download signed PDF');
        $this->assertStringContainsString('data-live-agreement-actions data-live-signed="0"',$html->getContent());
        $this->saveFixture('enquiry-exception.html',$html->getContent());$this->saveFixture('exception.json',$exception->getContent());
        $this->sign();$signed=$this->pollStatus()->assertJsonPath('agreement_state','signed');
        $this->assertStringContainsString('/signed-agreement',$signed->json('signed_copy_url'));
        $this->saveFixture('exception-signed.json',$signed->getContent());
    }
}
