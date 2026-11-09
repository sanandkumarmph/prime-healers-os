<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, Invoice, Product, Quotation, Role, Sale, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class SaleCommercialDefaultsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private Customer $customer;
    private Product $small;
    private Product $heavy;

    protected function setUp(): void
    {
        parent::setUp();Mail::fake();$this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(10,0));
        $organization=TestData::organization(['state'=>'Karnataka','currency_code'=>'INR']);
        $this->admin=TestData::user($organization);$this->actingAs($this->admin);
        $this->customer=Customer::create(['organization_id'=>$organization->id,'name'=>'Synthetic sale customer','phone'=>'+919000000001','state'=>'Karnataka','address'=>'Synthetic address']);
        $data=['organization_id'=>$organization->id,'product_type'=>'both','stock_mode'=>'untracked','total_quantity'=>20,'available_quantity'=>20,
            'is_sellable'=>true,'is_rentable'=>true,'price_per_day'=>100,'rental_price'=>3500,'sale_price'=>1180,'gst_tax_type'=>'cgst_sgst','gst_calculation_mode'=>'exclusive','cgst_rate'=>9,'sgst_rate'=>9];
        $this->small=Product::create(['name'=>'Synthetic small unit']+$data);
        $this->heavy=Product::create(['name'=>'Synthetic hospital bed','sale_price'=>2360]+$data);
    }

    private function fixture(string $name,$response): void
    {
        if($folder=getenv('PHOS_SALE_DEFAULTS_RENDER_DIR')){
            if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$name.'.html',$response->getContent());
            file_put_contents($folder.'/ids.json',json_encode(['small'=>$this->small->id,'heavy'=>$this->heavy->id,'customer'=>$this->customer->id]));
        }
    }

    private function shipping($response): string
    {
        $dom=new \DOMDocument();@$dom->loadHTML($response->getContent());return $dom->getElementById('shipping_charges')->getAttribute('value');
    }

    public function test_fresh_admin_and_sales_forms_have_editable_transport_and_inclusive_master_selection_data(): void
    {
        $role=Role::create(['organization_id'=>$this->admin->organization_id,'name'=>'Sales','slug'=>'sales','is_system'=>true,'is_active'=>true,'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $sales=TestData::user($this->admin->organization,['role'=>'sales','role_id'=>$role->id]);
        foreach(['admin'=>$this->admin,'sales'=>$sales] as $name=>$actor){
            $this->actingAs($actor);$this->session(['_old_input'=>[]]);
            $response=$this->get(route('sales.create'))->assertOk()->assertSee('let saleTransportAutomatic = true',false)->assertSee('"tax_mode":"inclusive"',false)->assertDontSee('name="deposit_amount"',false);
            $this->assertSame('800.00',$this->shipping($response));$this->fixture('fresh-'.$name,$response);
        }
        $this->assertSame('exclusive',$this->small->fresh()->gst_calculation_mode);$this->assertDatabaseCount('sales',0);Mail::assertNothingSent();
    }

    public function test_validation_retry_preserves_zero_transport_and_edited_prices_and_tax(): void
    {
        $old=['shipping_charges'=>0,'customer_id'=>$this->customer->id,'sale_items'=>[['product_id'=>$this->small->id,'quantity'=>4,'unit_price'=>777,'discount_amount'=>0,'tax_percentage'=>5,'tax_calculation_mode'=>'exclusive','tax_type'=>'cgst_sgst']]];
        $response=$this->withSession(['_old_input'=>$old])->get(route('sales.create'))->assertOk()->assertSee('let saleTransportAutomatic = false',false);
        $this->assertSame('0.00',$this->shipping($response));$response->assertSee('"unit_price":777',false)->assertSee('"tax_calculation_mode":"exclusive"',false);$this->fixture('retry',$response);
        $this->assertDatabaseCount('sales',0);
    }

    public function test_saved_sale_edit_retains_existing_commercial_values(): void
    {
        $this->post(route('sales.store'),['customer_id'=>$this->customer->id,'sale_date'=>'2026-11-08','payment_status'=>'pending','shipping_charges'=>0,
            'sale_items'=>[['product_id'=>$this->small->id,'quantity'=>4,'unit_price'=>777,'discount_amount'=>0,'tax_percentage'=>5,'tax_calculation_mode'=>'exclusive']]])->assertSessionHasNoErrors();
        $sale=Sale::sole();$before=$sale->getRawOriginal();
        $response=$this->get(route('sales.edit',$sale))->assertOk()->assertSee('let saleTransportAutomatic = false',false);
        $this->assertSame('0.00',$this->shipping($response));$response->assertSee('"unit_price":"777.00"',false)->assertSee('"tax_calculation_mode":"exclusive"',false);$this->fixture('edit',$response);
        $this->assertSame($before,$sale->fresh()->getRawOriginal());
    }

    public function test_inclusive_selected_master_price_is_not_taxed_again_in_the_saved_sale_and_invoice(): void
    {
        $this->post(route('sales.store'),['customer_id'=>$this->customer->id,'sale_date'=>'2026-11-08','payment_status'=>'pending','shipping_charges'=>800,
            'sale_items'=>[['product_id'=>$this->small->id,'quantity'=>1,'unit_price'=>1180,'discount_amount'=>0,'tax_percentage'=>18,'tax_calculation_mode'=>'inclusive']]])->assertSessionHasNoErrors();
        $sale=Sale::sole();$invoice=Invoice::sole();
        $this->assertSame(1980.0,(float)$sale->sale_amount);$this->assertSame(1980.0,(float)$invoice->total_amount);
        $this->assertSame(180.0,(float)$sale->saleItems()->sole()->total_tax_amount);$this->assertSame(180.0,(float)$invoice->total_tax_amount);
        $this->assertSame(1180.0,(float)$sale->saleItems()->sole()->unit_price);$this->assertSame(800.0,(float)$sale->shipping_charges);
    }

    public function test_accepted_quotation_prefill_and_conversion_keep_the_exact_agreed_sale_values(): void
    {
        $this->post(route('enquiries.store'),['name'=>$this->customer->name,'phone'=>$this->customer->phone,'subject'=>'Synthetic accepted sale','source'=>'incoming_phone','owner_id'=>$this->admin->id,'customer_id'=>$this->customer->id,'intent'=>'sale'])->assertSessionHasNoErrors();
        $enquiry=Enquiry::sole();
        $this->post(route('quotations.store',$enquiry),['kind'=>'sale','valid_until'=>'2026-11-20','transport'=>0,'deposit'=>0,'tax_type'=>'cgst_sgst','customer_id'=>$this->customer->id,'enquiry_lock_version'=>$enquiry->lock_version,
            'lines'=>[['product_id'=>$this->small->id,'quantity'=>4,'rate'=>777,'tax_rate'=>5,'tax_mode'=>'exclusive','line_type'=>'sale']]])->assertSessionHasNoErrors();
        $quote=Quotation::sole();foreach(['send','accepted'] as $action){$this->post(route('quotations.action',$quote),['action'=>$action,'lock_version'=>$quote->fresh()->lock_version,'note'=>'Customer confirmed by phone'])->assertSessionHasNoErrors();}
        $response=$this->get(route('sales.create',['quotation_id'=>$quote->id]))->assertOk()->assertSee('const salePricingFromQuote = true',false)->assertSee('let saleTransportAutomatic = false',false);
        $this->assertSame('0.00',$this->shipping($response));$response->assertSee('"unit_price":777',false)->assertSee('"tax_calculation_mode":"exclusive"',false);$this->fixture('conversion',$response);
        $payload=app(\App\Services\Sales\QuotationConversion::class)->prefill($quote->fresh());$payload['quotation_recipient_confirmed']=1;
        $this->post(route('sales.store'),$payload)->assertSessionHasNoErrors();
        $sale=Sale::sole();$this->assertSame(0.0,(float)$sale->shipping_charges);$this->assertSame(777.0,(float)$sale->saleItems()->sole()->unit_price);$this->assertSame('exclusive',$sale->saleItems()->sole()->tax_calculation_mode);
        Mail::assertNothingSent();
    }
}
