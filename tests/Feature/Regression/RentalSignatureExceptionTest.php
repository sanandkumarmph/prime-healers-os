<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, EnquiryActivity, Product, Quotation, Rental, RentalAgreement, Role, User, Warehouse};
use App\Services\Sales\{QuotationConversion, RentalAgreements, SalesWorkspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalSignatureExceptionTest extends TestCase
{
    use RefreshDatabase;
    private User $admin, $sales;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();Mail::fake();config(['app.rental_agreements'=>true]);
        $this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(10,0));
        $org=TestData::organization(['country_code'=>'IN','state'=>'Karnataka','currency_code'=>'INR']);
        $this->admin=TestData::user($org);$this->sales=TestData::user($org,['role'=>User::ROLE_SALES,'name'=>'Synthetic Sales Approver']);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Exception rental unit','product_type'=>'both','stock_mode'=>'untracked',
            'is_rentable'=>true,'is_sellable'=>true,'total_quantity'=>20,'available_quantity'=>20,'price_per_day'=>100,'rental_price'=>700,'sale_price'=>700]);
        Warehouse::create(['organization_id'=>$org->id,'name'=>'Test warehouse','is_active'=>true]);
        $this->actingAs($this->sales);
    }

    private function quote(): Quotation
    {
        $this->post(route('enquiries.store'),['name'=>'Synthetic prospect','phone'=>'+919876543210','subject'=>'Rental equipment',
            'source'=>'incoming_phone','owner_id'=>$this->sales->id,'intent'=>'rental'])->assertSessionHasNoErrors();
        $enquiry=Enquiry::latest('id')->firstOrFail();
        $this->post(route('quotations.store',$enquiry),['kind'=>'rental','valid_until'=>'2026-11-20','start_date'=>'2026-11-09','end_date'=>'2026-12-08',
            'transport'=>50,'deposit'=>500,'tax_type'=>'cgst_sgst','customer_id'=>null,'enquiry_lock_version'=>$enquiry->lock_version,
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>1,'rate'=>700,'tax_rate'=>0,'tax_mode'=>'inclusive']]])->assertSessionHasNoErrors();
        $quote=$enquiry->quotations()->sole();
        $this->post(route('quotations.action',$quote),['action'=>'send','lock_version'=>$quote->lock_version])->assertSessionHasNoErrors();
        return $quote->fresh();
    }

    private function input(Quotation $quote): array
    {
        $agreements=app(RentalAgreements::class);$quote->refresh();
        return ['lock_version'=>$quote->lock_version,'exception_reason'=>'Customer cannot use the digital signature pad; terms explained and accepted by telephone.',
            'alternative_acceptance_confirmed'=>1,'exception_context'=>$agreements->exceptionContext($agreements->quotation($quote),$quote)];
    }

    private function approve(Quotation $quote): RentalAgreement
    {
        $this->post(route('quotations.signature-exception',$quote),$this->input($quote))->assertSessionHasNoErrors()->assertRedirect();
        return app(RentalAgreements::class)->quotation($quote->fresh());
    }

    private function fixture(string $name, Quotation $quote, RentalAgreement $agreement): void
    {
        if (!$folder=getenv('PHOS_SIGNATURE_EXCEPTION_RENDER_DIR')) return;
        if (!is_dir($folder)) mkdir($folder,0700,true);
        $html=view('sales-workspace.signature-exception',['exceptionQuote'=>$quote,'exceptionRental'=>null,
            'agreement'=>$agreement,'agreements'=>app(RentalAgreements::class)])->render();
        file_put_contents($folder.'/'.$name.'.html','<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:12px;font-family:Arial,sans-serif;color:#172b4d}*{box-sizing:border-box}button{padding:8px 12px}.sw-field{display:grid;gap:5px}</style></head><body>'.$html.'</body></html>');
    }

    private function accept(Quotation $quote): Quotation
    {
        $this->post(route('quotations.action',$quote),['action'=>'accepted','lock_version'=>$quote->fresh()->lock_version,
            'contact_name'=>'Synthetic prospect','note'=>'Customer confirmed the quotation by telephone.'])->assertSessionHasNoErrors();
        return $quote->fresh();
    }

    private function rental(Quotation $quote): Rental
    {
        $customer=Customer::create(['organization_id'=>$this->sales->organization_id,'name'=>'Synthetic prospect','phone'=>'+919876543210','address'=>'Test address','state'=>'Karnataka']);
        $payload=app(QuotationConversion::class)->prefill($quote)+['quotation_recipient_confirmed'=>1,'delivery_assignment_type'=>'customer_pickup'];
        $payload['customer_id']=$customer->id;
        $this->post(route('rentals.store'),$payload)->assertSessionHasNoErrors();
        return Rental::findOrFail($quote->fresh()->order_id);
    }

    public function test_sales_can_record_a_reasoned_exception_without_faking_signature_or_acceptance(): void
    {
        $quote=$this->quote();$agreements=app(RentalAgreements::class);
        $this->fixture('pending',$quote,$agreements->quotation($quote));
        $agreement=$this->approve($quote);
        $this->fixture('approved',$quote->fresh(),$agreement);
        $this->assertNull($agreement->signed_at);$this->assertNull($agreement->proof);
        $this->assertSame('sent',$quote->fresh()->status);$this->assertTrue($agreements->readyForQuote($quote->fresh(),$agreement));
        $this->assertSame($this->sales->id,$agreement->exception_approval['approved_by_user_id']);
        $this->assertStringNotContainsString('Customer cannot use',$agreement->getRawOriginal('exception_approval'));
        $this->assertArrayNotHasKey('exception_approval',$agreement->toArray());
        $this->get($agreements->pdfLink($agreement))->assertNotFound();
        $this->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertSessionHasErrors('quotation_id');
        $quote=$this->accept($quote);
        $this->assertNotNull(app(SalesWorkspace::class)->orderAction($this->sales,$quote->enquiry,$quote,$agreement));
        $this->get(route('enquiries.show',$quote->enquiry))->assertOk()->assertSee('Signature exception approved')->assertSee('No digital signature was captured.')->assertDontSee('Download signed PDF');
        $rental=$this->rental($quote);
        $agreement->refresh();$this->assertFalse($agreements->signedFor($rental,$agreement));$this->assertTrue($agreements->readyFor($rental,$agreement));
        $agreements->dispatch($rental);
        $this->assertDatabaseCount('rentals',1);Mail::assertNothingSent();
    }

    public function test_required_reason_confirmation_context_and_version_are_enforced(): void
    {
        $quote=$this->quote();$url=route('quotations.signature-exception',$quote);$input=$this->input($quote);
        foreach ([['exception_reason'=>''],['alternative_acceptance_confirmed'=>0],['exception_context'=>str_repeat('0',64)],['lock_version'=>0]] as $invalid) {
            $this->postJson($url,array_replace($input,$invalid))->assertUnprocessable();
            $this->assertNull(app(RentalAgreements::class)->quotation($quote)->fresh()->exception_approval);
        }
        $this->post($url,$input)->assertSessionHasNoErrors();
        $original=app(RentalAgreements::class)->quotation($quote)->getRawOriginal('exception_approval');
        $this->post($url,$input)->assertSessionHasNoErrors();
        $this->assertSame($original,app(RentalAgreements::class)->quotation($quote)->getRawOriginal('exception_approval'));
        $this->assertSame(1,EnquiryActivity::where('type','quotation.signature_exception_approved')->count());
    }

    public function test_other_staff_foreign_organizations_and_custom_non_sales_roles_cannot_approve(): void
    {
        $quote=$this->quote();$input=$this->input($quote);$url=route('quotations.signature-exception',$quote);
        $this->actingAs(TestData::user($this->sales->organization,['role'=>User::ROLE_SALES]))->post($url,$input)->assertNotFound();
        $this->actingAs(TestData::user())->post($url,$input)->assertNotFound();
        $this->actingAs(TestData::user($this->sales->organization,['role'=>User::ROLE_FINANCE]))->post($url,$input)->assertForbidden();
        $role=Role::create(['organization_id'=>$this->sales->organization_id,'name'=>'Quote editor','slug'=>'quote_editor','is_active'=>true,
            'permissions'=>['enquiries'=>['read','update'],'quotations'=>['read','update']]]);
        $this->actingAs(TestData::user($this->sales->organization,['role_id'=>$role->id]))->post($url,$input)->assertForbidden();
        $this->actingAs($this->admin)->post($url,$input)->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id,app(RentalAgreements::class)->quotation($quote)->exception_approval['approved_by_user_id']);
    }

    public function test_revision_and_material_rental_changes_invalidate_exception(): void
    {
        $quote=$this->quote();$agreement=$this->approve($quote);
        $this->post(route('quotations.action',$quote),['action'=>'revise','lock_version'=>$quote->fresh()->lock_version])->assertSessionHasNoErrors();
        $next=$quote->enquiry->quotations()->latest('revision')->first();
        $this->assertFalse(app(RentalAgreements::class)->readyForQuote($next));
        $this->assertFalse(app(RentalAgreements::class)->exceptionForQuote($quote->fresh(),$agreement));
        $this->post(route('quotations.signature-exception',$quote),$this->input($quote))->assertConflict();
        $quote2=$this->quote();$this->approve($quote2);$rental=$this->rental($this->accept($quote2));
        $agreement2=app(RentalAgreements::class)->current($rental);
        $rental->update(['transport_amount'=>99]);
        $this->assertFalse(app(RentalAgreements::class)->readyFor($rental,$agreement2));
        $this->expectException(\Illuminate\Validation\ValidationException::class);app(RentalAgreements::class)->dispatch($rental);
    }

    public function test_direct_rental_exception_is_scoped_and_preserves_current_order_context(): void
    {
        $rental=Rental::create(['organization_id'=>$this->sales->organization_id,'created_by_user_id'=>$this->sales->id,'product_id'=>$this->product->id,
            'customer_name'=>'Direct customer','phone'=>'+919000000001','quantity'=>1,'start_date'=>'2026-11-09','end_date'=>'2026-12-08',
            'rental_amount'=>700,'status'=>'active']);
        $rental->refresh();$agreements=app(RentalAgreements::class);$agreement=$agreements->issue($rental);
        $payload=['exception_reason'=>'Customer cannot use the digital pad. Terms explained and accepted in person.','alternative_acceptance_confirmed'=>1,
            'exception_context'=>$agreements->exceptionContext($agreement,null,$rental)];
        $this->actingAs(TestData::user($this->sales->organization,['role'=>User::ROLE_SALES]))->post(route('rentals.signature-exception',$rental),$payload)->assertForbidden();
        $this->actingAs($this->sales)->post(route('rentals.signature-exception',$rental),array_replace($payload,['exception_context'=>str_repeat('0',64)]))->assertSessionHasErrors('exception_context');
        $this->post(route('rentals.signature-exception',$rental),$payload)->assertSessionHasNoErrors();
        $this->assertTrue($agreements->readyFor($rental,$agreement->fresh()));$this->assertNull($agreement->fresh()->signed_at);
        $this->get(route('rentals.show',$rental))->assertOk()->assertSee('Signature exception approved')->assertDontSee('Download signed PDF');
    }

    public function test_customer_can_still_provide_a_real_signature_after_exception_and_conversion(): void
    {
        $quote=$this->quote();$agreement=$this->approve($quote);$rental=$this->rental($this->accept($quote));
        $agreements=app(RentalAgreements::class);$agreement->refresh();$url=$agreements->link($agreement);
        $image=imagecreatetruecolor(760,280);imagefill($image,0,0,0xffffff);imagesetthickness($image,5);
        imageline($image,70,210,230,80,0x172b4d);imageline($image,230,80,470,190,0x172b4d);ob_start();imagepng($image);$signature='data:image/png;base64,'.base64_encode(ob_get_clean());
        $this->get($url)->assertOk();
        $this->post($url,['contact_name'=>'Real customer signer','rental_agreement_acknowledged'=>1,
            'rental_agreement_version'=>hash('sha256',$agreements->terms($agreement)),'signature_data'=>$signature])->assertSessionHasNoErrors()->assertRedirect();
        $agreement->refresh();$this->assertNotNull($agreement->signed_at);$this->assertSame('Real customer signer',$agreement->proof['name']);
        $this->assertNotNull($agreement->exception_approval);$this->assertTrue($agreements->signedFor($rental,$agreement));
        $this->get($agreements->pdfLink($agreement))->assertOk()->assertHeader('Content-Type','application/pdf');
    }
}
