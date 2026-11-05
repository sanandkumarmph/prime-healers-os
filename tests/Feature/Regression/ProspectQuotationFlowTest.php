<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Delivery, Enquiry, Product, Quotation, Rental, RentalAgreement, Sale, Warehouse};
use App\Services\Sales\{QuotationConversion, RentalAgreements, RentalAgreementTerms, SalesWorkspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class ProspectQuotationFlowTest extends TestCase
{
    use RefreshDatabase;
    private $owner, $product;

    protected function setUp(): void
    {
        parent::setUp(); Mail::fake();
        $this->travelTo(now()->setDate(2026,11,2)->setTime(10,0));
        $org=TestData::organization(['country_code'=>'IN','state'=>'Karnataka','currency_code'=>'INR']);
        $this->owner=TestData::user($org);$this->actingAs($this->owner);
        Warehouse::create(['organization_id'=>$org->id,'name'=>'Synthetic warehouse','is_active'=>true]);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic oxygen concentrator','product_type'=>'both',
            'stock_mode'=>'untracked','is_rentable'=>true,'is_sellable'=>true,'total_quantity'=>20,'available_quantity'=>20,'price_per_day'=>100,'rental_price'=>700,'sale_price'=>700]);
    }

    private function enquiry(): Enquiry
    {
        $this->post(route('enquiries.store'),['name'=>'Synthetic prospect','phone'=>'+919876543210','email'=>'prospect@example.test',
            'subject'=>'Oxygen concentrator for a week','requirements'=>'Call before preparing quotation','source'=>'incoming_phone','owner_id'=>$this->owner->id,'next'=>'quotation'])
            ->assertSessionHasNoErrors()->assertRedirect(route('enquiries.show',1));
        return Enquiry::sole();
    }

    private function draft(string $kind='rental'): Quotation
    {
        $enquiry=$this->enquiry();
        $this->post(route('quotations.store',$enquiry),['kind'=>$kind,'valid_until'=>'2026-11-20','start_date'=>'2026-11-03','end_date'=>'2026-11-09',
            'transport'=>50,'deposit'=>500,'tax_type'=>'cgst_sgst','customer_id'=>null,'enquiry_lock_version'=>$enquiry->lock_version,'next'=>'review',
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>1,'rate'=>700,'tax_rate'=>18,'tax_mode'=>'inclusive']]])
            ->assertSessionHasNoErrors()->assertSessionHas('review_ready',true);
        return Quotation::sole();
    }

    private function action(Quotation $q,string $action): Quotation
    {
        $this->post(route('quotations.action',$q),['action'=>$action,'lock_version'=>$q->fresh()->lock_version,'contact_name'=>'Synthetic prospect','note'=>'Confirmed by telephone'])
            ->assertSessionHasNoErrors();
        return $q->fresh();
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(180, 70);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 18, 29, 44);
        imagesetthickness($image, 3);
        imageline($image, 20, 55, 50, 19, $ink);
        imageline($image, 50, 19, 110, 49, $ink);
        imageline($image, 110, 49, 145, 31, $ink);
        ob_start();
        imagepng($image);
        return 'data:image/png;base64,'.base64_encode(ob_get_clean());
    }

    private function agreementPayload(): array
    {
        return [
            'decision' => 'accepted', 'contact_name' => 'Synthetic signer', 'acknowledge' => 1,
            'rental_agreement_acknowledged' => 1,
            'rental_agreement_version' => hash('sha256', RentalAgreementTerms::TEXT),
            'signature_data' => $this->signature(),
        ];
    }

    public function test_rental_signature_is_required_before_conversion_and_saved_on_the_order(): void
    {
        config(['app.rental_agreements' => true]);
        $quote = $this->action($this->draft(), 'send');
        $url = app(SalesWorkspace::class)->shareUrl($quote);
        $this->from($url)->post($url, ['decision' => 'accepted', 'contact_name' => 'Synthetic signer', 'acknowledge' => 1])
            ->assertSessionHasErrors('signature_data');
        $this->assertSame('sent', $quote->fresh()->status);
        $quote = $this->action($quote, 'accepted');
        $this->get(route('quotations.show', $quote))->assertOk()->assertSee('Awaiting customer signature')->assertDontSee('Create rental order');
        $this->get(route('rentals.create', ['quotation_id' => $quote->id]))->assertSessionHasErrors('quotation_id');
        $this->fixture('rental-signing', $this->get($url)->assertOk()->assertSee('Prime Healers – Rental T&C')->assertSee('₹100/day')->assertSee('Agree &amp; sign', false));
        $this->from($url)->post($url, array_replace($this->agreementPayload(), ['rental_agreement_version' => str_repeat('0', 64)]))
            ->assertSessionHasErrors('rental_agreement_version');
        $this->from($url)->post($url, array_replace($this->agreementPayload(), ['signature_data' => 'invalid', 'rental_agreement_id_reference' => '0123']))
            ->assertSessionHasErrors('signature_data');
        $this->assertNull(session()->getOldInput('signature_data'));
        $this->assertNull(session()->getOldInput('rental_agreement_id_reference'));
        $this->assertDatabaseCount('rental_agreements', 0);
        $this->post($url, $this->agreementPayload())->assertRedirect($url);
        $agreement = RentalAgreement::sole();
        $this->assertSame(RentalAgreementTerms::TEXT, $agreement->terms);
        $this->assertSame('Synthetic signer', $agreement->proof['name']);
        $this->assertNull($agreement->proof['id_reference']);
        $this->assertSame($quote->snapshot, $agreement->proof['quotation']);
        $this->assertStringNotContainsString('Synthetic signer', $agreement->getRawOriginal('proof'));
        $this->get($url)->assertOk()->assertSee('Rental terms signed')->assertDontSee('Synthetic signer');
        $this->post($url, array_replace($this->agreementPayload(), ['contact_name' => 'Different name']))->assertRedirect($url);
        $this->assertSame('Synthetic signer', $agreement->fresh()->proof['name']);
        $this->get(route('quotations.show', $quote))->assertSee('Create rental order');

        $customer = Customer::create(['organization_id' => $this->owner->organization_id, 'name' => 'Synthetic signer',
            'phone' => '+919876543210', 'address' => 'Synthetic address', 'state' => 'Karnataka']);
        $data = array_replace(app(QuotationConversion::class)->prefill($quote->fresh()), ['customer_id' => $customer->id,
            'quotation_recipient_confirmed' => 1, 'delivery_assignment_type' => 'customer_pickup']);
        $this->post(route('rentals.store'), $data)->assertSessionHasNoErrors();
        $rental = Rental::sole();
        $this->assertEquals($rental->id, $agreement->fresh()->rental_id);
        $this->get(route('rentals.show', $rental))->assertOk()->assertSee('View signed terms and signature');
        $this->assertTrue(app(RentalAgreements::class)->signedFor($rental, $agreement->fresh()));
    }

    public function test_direct_rental_blocks_dispatch_and_invalidates_a_link_after_a_material_edit(): void
    {
        config(['app.rental_agreements' => true]);
        $quote = $this->draft();
        $customer = Customer::create(['organization_id' => $this->owner->organization_id, 'name' => 'Synthetic renter',
            'phone' => '+919876543210', 'address' => 'Synthetic address', 'state' => 'Karnataka']);
        $data = array_replace(app(QuotationConversion::class)->prefill($quote), ['customer_id' => $customer->id,
            'delivery_assignment_type' => 'customer_pickup', 'enquiry_source' => 'incoming_phone']);
        unset($data['quotation_id'], $data['quotation_version']);
        $this->post(route('rentals.store'), $data)->assertSessionHasNoErrors();
        $rental = Rental::sole();
        $agreement = RentalAgreement::sole();
        $agreements = app(RentalAgreements::class);
        $url = $agreements->link($agreement);
        $this->get(route('rental-agreement.show', $agreement))->assertForbidden();
        $this->get(route('rentals.show', $rental))->assertOk()->assertSee('Share on WhatsApp');
        $delivery = Delivery::where('rental_id', $rental->id)->firstOrFail();
        try {
            $delivery->update(['status' => 'in_progress']);
            $this->fail('Unsigned rental was dispatched.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('rental_agreement', $error->errors());
        }
        $this->assertSame('pending', $delivery->fresh()->status);
        $rental->update(['transport_amount' => 150]);
        $this->get($url)->assertGone();
        $this->post(route('rentals.agreement.reissue', $rental))->assertRedirect();
        $replacement = $agreements->current($rental->fresh());
        $this->assertNotSame($agreement->id, $replacement->id);
        $newUrl = $agreements->link($replacement);
        $this->get($newUrl)->assertOk()->assertSee('Prime Healers – Rental T&C');
        $this->post($newUrl, $this->agreementPayload() + ['rental_agreement_id_reference' => '0123'])->assertRedirect($newUrl);
        $this->assertSame('0123', $replacement->fresh()->proof['id_reference']);
        $this->get($newUrl)->assertOk()->assertSee('Rental terms signed')->assertDontSee('Synthetic signer');
        $delivery->fresh()->update(['status' => 'in_progress']);
        $this->assertSame('in_progress', $delivery->fresh()->status);
    }

    private function fixture(string $name,$response): void
    {
        if($folder=getenv('PHOS_PROSPECT_RENDER_DIR')) {
            if(!is_dir($folder))mkdir($folder,0700,true);
            file_put_contents($folder.'/'.$name.'.html',$response->getContent());
        }
    }

    public function test_enquiry_save_shows_filled_record_and_optional_followup_and_quote_without_creating_customer(): void
    {
        $this->fixture('capture',$this->get(route('enquiries.create'))->assertOk()->assertSee('Save enquiry')->assertDontSee('Save &amp; create quotation',false)->assertDontSee('salesQuickCustomerForm'));
        $e=$this->enquiry();
        $this->fixture('enquiry',$this->get(route('enquiries.show',$e))->assertOk()->assertSee('Create quotation')->assertSee('Follow-ups')->assertSee('Call before preparing quotation')->assertSee('Synthetic prospect'));
        $this->post(route('enquiries.follow-up',$e),['channel'=>'call','subject'=>'Discuss requirements','due_at'=>'2026-11-03T17:00'])->assertSessionHasNoErrors();
        $this->assertSame(1,$e->followUps()->count());$this->assertDatabaseCount('quotations',0);$this->assertDatabaseCount('customers',0);
    }

    public function test_prospect_can_review_share_pdf_and_accept_without_customer_or_full_address(): void
    {
        $q=$this->draft();
        $this->assertNull($q->snapshot['customer_id']);$this->assertSame('',$q->snapshot['recipient']['address']);
        $this->fixture('review',$this->get(route('quotations.show',$q))->assertOk()->assertSee('id="quotation-review"',false)->assertDontSee('salesQuickCustomerForm'));
        $q=$this->action($q,'send');$snapshot=$q->snapshot;
        $this->fixture('shared',$this->get(route('quotations.show',$q))->assertOk()->assertSee('Synthetic prospect')->assertSee('wa.me/919876543210'));
        $this->get(route('quotations.pdf',$q))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->get(app(SalesWorkspace::class)->shareUrl($q))->assertOk()->assertSee('Synthetic prospect');
        $q=$this->action($q,'accepted');
        $this->assertSame('accepted',$q->status);$this->assertSame($snapshot,$q->snapshot);
        $this->assertNull($q->enquiry->customer_id);$this->assertDatabaseCount('customers',0);$this->assertDatabaseCount('rentals',0);$this->assertDatabaseCount('sales',0);
        Mail::assertNothingSent();Mail::assertNothingQueued();
    }

    public function test_new_customer_is_linked_only_when_accepted_rental_is_created_and_retries_are_safe(): void
    {
        $q=$this->action($this->action($this->draft(),'send'),'accepted');
        $this->fixture('order',$this->get(route('rentals.create',['quotation_id'=>$q->id]))->assertOk()->assertSee('quotation_recipient_confirmed')->assertSee('value="Synthetic prospect"',false));
        $this->assertDatabaseCount('customers',0);
        $customer=Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Confirmed billing contact','phone'=>'+919876543210','address'=>'Confirmed delivery address','state'=>'Karnataka']);
        $data=array_replace(app(QuotationConversion::class)->prefill($q),['customer_id'=>$customer->id,'delivery_assignment_type'=>'customer_pickup']);
        $this->post(route('rentals.store'),$data)->assertSessionHasErrors('quotation_recipient_confirmed');
        $this->assertNull($q->enquiry->fresh()->customer_id);$this->assertDatabaseCount('rentals',0);
        $data['quotation_recipient_confirmed']=1;
        $this->post(route('rentals.store'),$data)->assertSessionHasNoErrors();
        $rental=Rental::sole();$this->assertEquals($customer->id,$rental->customer_id);
        $this->assertEquals($customer->id,$q->enquiry->fresh()->customer_id);
        $this->assertNull($q->fresh()->snapshot['customer_id']);$this->assertSame('Synthetic prospect',$q->fresh()->snapshot['recipient']['name']);
        $this->post(route('rentals.store'),$data)->assertRedirect(route('rentals.show',$rental));
        $this->assertDatabaseCount('rentals',1);$this->assertDatabaseCount('customers',1);
    }

    public function test_sale_conversion_requires_explicit_customer_confirmation_and_rejects_foreign_customer(): void
    {
        $q=$this->action($this->action($this->draft('sale'),'send'),'accepted');
        $customer=Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Confirmed customer','phone'=>'+919876543210','state'=>'Karnataka']);
        $other=Customer::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign','phone'=>'+919876543211']);
        $data=array_replace(app(QuotationConversion::class)->prefill($q),['customer_id'=>$other->id,'quotation_recipient_confirmed'=>1]);
        $this->post(route('sales.store'),$data)->assertSessionHasErrors('customer_id');$this->assertDatabaseCount('sales',0);
        $data['customer_id']=$customer->id;
        $this->post(route('sales.store'),$data)->assertSessionHasNoErrors();
        $this->assertSame('converted',$q->fresh()->status);$this->assertEquals($customer->id,Sale::sole()->customer_id);
        $this->assertEquals($customer->id,$q->enquiry->fresh()->customer_id);
    }

    public function test_changed_enquiry_contact_must_be_reviewed_before_finalising_a_prospect_quote(): void
    {
        $q=$this->draft();$q->enquiry->update(['phone'=>'+919999999999']);
        $this->post(route('quotations.action',$q),['action'=>'send','lock_version'=>$q->lock_version])->assertSessionHasErrors('workspace');
        $this->assertSame('draft',$q->fresh()->status);$this->assertDatabaseCount('customers',0);
    }
}
