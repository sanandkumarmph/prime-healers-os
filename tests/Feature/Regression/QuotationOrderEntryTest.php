<?php

namespace Tests\Feature\Regression;

use App\Models\{City, Customer, Enquiry, Product, Quotation, User, Warehouse};
use App\Services\Sales\QuotationConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class QuotationOrderEntryTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private Enquiry $enquiry;
    private Product $primary;
    private Product $secondary;
    private Product $accessory;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp(); Mail::fake(); config(['app.rental_agreements'=>false]);
        $this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(10,0));
        $org=TestData::organization(['country_code'=>'IN','state'=>'Karnataka','currency_code'=>'INR']);
        $this->owner=TestData::user($org);$this->actingAs($this->owner);
        $city=City::create(['organization_id'=>$org->id,'name'=>'Bengaluru','is_active'=>true]);
        $this->warehouse=Warehouse::create(['organization_id'=>$org->id,'city_id'=>$city->id,'name'=>'Synthetic warehouse','is_active'=>true]);
        $base=['organization_id'=>$org->id,'product_type'=>'rentable','stock_mode'=>'tracked_rental','is_rentable'=>true,'is_sellable'=>false,
            'rental_price'=>9000,'price_per_day'=>300,'total_quantity'=>0,'available_quantity'=>0,'gst_tax_type'=>'cgst_sgst',
            'cgst_rate'=>2.5,'sgst_rate'=>2.5,'gst_calculation_mode'=>'inclusive'];
        $this->primary=Product::create($base+['name'=>'Synthetic oxygen concentrator']);
        $this->secondary=Product::create($base+['name'=>'Synthetic second rental machine']);
        $this->accessory=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic nasal cannula','product_type'=>'sellable','stock_mode'=>'untracked',
            'is_sellable'=>true,'is_rentable'=>false,'sale_price'=>99,'price_per_day'=>0,'total_quantity'=>20,'available_quantity'=>20]);
        $this->post(route('enquiries.store'),['salutation'=>'Dr.','name'=>'Synthetic Prospect','phone'=>'+919876543210','email'=>'synthetic@example.test',
            'subject'=>'Two machines and nasal cannula','intent'=>'rental','source'=>'incoming_phone','owner_id'=>$this->owner->id])->assertSessionHasNoErrors();
        $this->enquiry=Enquiry::sole();
    }

    private function quote(string $taxType='cgst_sgst'): Quotation
    {
        $this->post(route('quotations.store',$this->enquiry),['kind'=>'rental','enquiry_lock_version'=>$this->enquiry->fresh()->lock_version,
            'valid_until'=>'2026-11-20','start_date'=>'2026-11-09','end_date'=>'2026-12-08','tax_type'=>$taxType,'transport'=>600,'deposit'=>2000,
            'lines'=>[
                ['product_id'=>$this->primary->id,'line_type'=>'rental','quantity'=>1,'rate'=>0,'tax_rate'=>5,'tax_mode'=>'inclusive'],
                ['product_id'=>$this->secondary->id,'line_type'=>'rental','quantity'=>2,'rate'=>321.25,'tax_rate'=>5,'tax_mode'=>'inclusive'],
                ['product_id'=>$this->accessory->id,'line_type'=>'sale','quantity'=>3,'rate'=>11.11,'tax_rate'=>5,'tax_mode'=>'inclusive'],
            ]])->assertSessionHasNoErrors();
        $quote=Quotation::sole();
        foreach (['send','accepted'] as $action) {
            $this->post(route('quotations.action',$quote),['action'=>$action,'lock_version'=>$quote->fresh()->lock_version,
                'contact_name'=>'Synthetic Prospect','note'=>'Confirmed by telephone'])->assertSessionHasNoErrors();
        }
        return $quote->fresh();
    }

    private function fixture(string $name,$response): void
    {
        if ($folder=getenv('PHOS_ORDER_ENTRY_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder,0700,true);
            file_put_contents($folder.'/'.$name.'.html',$response->getContent());
            file_put_contents($folder.'/metadata.json',json_encode(['primary'=>$this->primary->id,'secondary'=>$this->secondary->id,'accessory'=>$this->accessory->id,
                'warehouse'=>$this->warehouse->id,'city'=>$this->warehouse->city_id]));
        }
    }

    public function test_accepted_quote_carries_all_products_and_exact_prices_into_the_order(): void
    {
        $quote=$this->quote();$before=$quote->snapshot;
        $prefill=app(QuotationConversion::class)->prefill($quote);
        $this->assertSame($this->primary->id,$prefill['product_id']);$this->assertSame('0.00',$prefill['rental_amount']);
        $this->assertSame('321.25',$prefill['rental_items'][0]['unit_rental_amount']);$this->assertSame(2,$prefill['rental_items'][0]['quantity']);
        $this->assertSame('11.11',$prefill['sale_items'][0]['unit_price']);$this->assertSame(3,$prefill['sale_items'][0]['quantity']);
        $response=$this->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertOk()->assertSee('Products and prices are filled in.')
            ->assertSee('value="0.00"',false)->assertSee('quotation_recipient_confirmed')->assertDontSee('the owner gets 80%');
        $this->fixture('prospect-order',$response);
        $this->assertSame($before,$quote->fresh()->snapshot);$this->assertDatabaseCount('customers',0);$this->assertDatabaseCount('rentals',0);
    }

    public function test_returning_validation_values_are_not_replaced_by_fresh_quote_prefill(): void
    {
        $quote=$this->quote();
        $this->withSession(['_old_input'=>app(QuotationConversion::class)->prefill($quote)+['delivery_notes'=>'Preserve my retry note']])
            ->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertOk()->assertSee('Preserve my retry note');
        $this->assertSame('0.00',old('rental_amount'));
    }

    public function test_explicit_igst_quotation_preserves_its_tax_type_for_every_product_line(): void
    {
        $quote=$this->quote('igst');$data=app(QuotationConversion::class)->prefill($quote);
        $this->assertSame('igst',$data['tax_type']);$this->assertSame('igst',$data['rental_items'][0]['tax_type']);$this->assertSame('igst',$data['sale_items'][0]['tax_type']);
        $this->fixture('igst-order',$this->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertOk());
    }

    public function test_linked_customer_does_not_request_new_customer_and_fresh_rental_starts_at_customer(): void
    {
        $customer=Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Existing confirmed customer','phone'=>'+919111111111']);
        $this->enquiry->update(['customer_id'=>$customer->id]);$quote=$this->quote();
        $this->fixture('linked-order',$this->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertOk());
        $this->assertEquals($customer->id,old('customer_id'));
        $plain=$this->get(route('rentals.create'))->assertOk()->assertDontSee('name="quotation_id"',false);
        $this->fixture('plain-order',$plain);
    }

    public function test_quick_customer_modal_prefills_every_available_contact_field_without_saving(): void
    {
        $prefill=['name'=>'Full legacy name','first_name'=>'Sana','last_name'=>'Rao','salutation'=>'Prof.','phone'=>'+919876543210',
            'whatsapp_number'=>'+919876543211','email'=>'sana@example.test','address'=>'Synthetic street','city'=>'Bengaluru','state'=>'Karnataka','pincode'=>'560001'];
        $html=view('customers.partials.quick-create-modal',['quickPrefill'=>$prefill])->render();
        foreach (['Sana','Rao','Prof.','9876543210','9876543211','sana@example.test','Synthetic street','560001'] as $value) $this->assertStringContainsString($value,$html);
        $this->assertStringContainsString('value="Prof." selected',$html);$this->assertStringContainsString('value="Karnataka" selected',$html);
        $this->assertDatabaseCount('customers',0);
    }
}
