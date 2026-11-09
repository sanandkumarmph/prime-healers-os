<?php

namespace Tests\Feature\Regression;

use App\Models\{City,Customer,Enquiry,Invoice,Product,Quotation,Rental,Role,User,Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalCommercialDefaultsTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $sales;
    private Customer $customer;
    private Product $small;
    private Product $heavy;
    private Product $accessory;

    protected function setUp(): void
    {
        parent::setUp();Mail::fake();config(['app.rental_agreements'=>false]);
        $this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(11,0));
        $org=TestData::organization(['state'=>'Karnataka','currency_code'=>'INR']);$this->admin=TestData::user($org);
        $role=Role::create(['organization_id'=>$org->id,'slug'=>'sales','name'=>'Sales','is_active'=>true,'is_system'=>true,'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $this->sales=TestData::user($org,['role'=>User::ROLE_SALES,'role_id'=>$role->id]);
        $city=City::create(['organization_id'=>$org->id,'name'=>'Bengaluru','is_active'=>true]);
        Warehouse::create(['organization_id'=>$org->id,'city_id'=>$city->id,'name'=>'Synthetic main warehouse','is_active'=>true]);
        $this->customer=Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic commercial customer','phone'=>'+919000000001','address'=>'Synthetic address','city'=>'Bengaluru','state'=>'Karnataka']);
        $base=['organization_id'=>$org->id,'product_type'=>'both','stock_mode'=>'untracked','total_quantity'=>30,'available_quantity'=>30,'price_per_day'=>100,
            'rental_price'=>3000,'rental_price_30_days'=>3000,'sale_price'=>150,'gst_tax_type'=>'cgst_sgst','cgst_rate'=>2.5,'sgst_rate'=>2.5,'gst_calculation_mode'=>'exclusive'];
        $this->small=Product::create($base+['name'=>'Synthetic oxygen concentrator']);
        $this->heavy=Product::create(array_replace($base,['name'=>'Synthetic hospital bed','rental_price'=>7000,'rental_price_30_days'=>7000]));
        $this->accessory=Product::create(array_replace($base,['name'=>'Synthetic nasal cannula','product_type'=>'sellable','gst_tax_type'=>'igst','igst_rate'=>12]));
        $this->actingAs($this->admin);
    }
    private function value($response,string $id): string
    {
        $dom=new \DOMDocument();@$dom->loadHTML($response->getContent());$xpath=new \DOMXPath($dom);
        return (string)$xpath->evaluate('string(//*[@id="'.$id.'"]/@value)');
    }
    private function fixture(string $name,$response): void
    {
        if($folder=getenv('PHOS_RENTAL_DEFAULTS_RENDER_DIR')){
            if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$name.'.html',$response->getContent());
            file_put_contents($folder.'/products.json',json_encode(['small'=>$this->small->id,'heavy'=>$this->heavy->id,'accessory'=>$this->accessory->id]));
        }
    }
    public function test_fresh_admin_and_sales_rentals_have_editable_deposit_transport_and_inclusive_catalogue_defaults(): void
    {
        foreach(['admin'=>$this->admin,'sales'=>$this->sales] as $name=>$user){
            $this->actingAs($user);$page=$this->get(route('rentals.create'))->assertOk();
            $this->assertSame('2000',$this->value($page,'deposit_amount'));$this->assertSame('800',$this->value($page,'transport_amount'));
            $page->assertSee('data-rental-transport-default="automatic"',false)->assertSee('id="rentalUseSuggestedTransport"',false)
                ->assertSee('data-gst-mode="inclusive"',false)->assertSee('data-default-price="150"',false);
            $this->fixture('fresh-'.$name,$page);
        }
        $this->assertDatabaseCount('rentals',0);
        $this->assertSame('exclusive',$this->small->fresh()->gst_calculation_mode);
    }
    public function test_validation_retry_and_edit_preserve_zero_and_negotiated_commercial_values(): void
    {
        $this->withSession(['_old_input'=>['product_id'=>$this->small->id,'quantity'=>2,'rental_amount'=>'0','deposit_amount'=>'0','transport_amount'=>'0','gst_rate'=>'12','gst_mode'=>'exclusive']]);
        $page=$this->get(route('rentals.create'))->assertOk();
        foreach(['rental_amount','deposit_amount','transport_amount'] as $id)$this->assertSame('0',$this->value($page,$id));
        $page->assertSee('data-rental-transport-default="preserved"',false);$this->fixture('retry',$page);
        $this->flushSession();
        $rental=Rental::create(['organization_id'=>$this->admin->organization_id,'customer_id'=>$this->customer->id,'product_id'=>$this->small->id,
            'customer_name'=>$this->customer->name,'phone'=>$this->customer->phone,'quantity'=>1,'start_date'=>'2026-11-09','end_date'=>'2026-12-08',
            'rental_amount'=>1777,'deposit_amount'=>0,'transport_amount'=>123,'status'=>'active']);
        $before=$rental->fresh()->getRawOriginal();$edit=$this->get(route('rentals.edit',$rental))->assertOk();
        $this->assertSame(0.0,(float)$this->value($edit,'deposit_amount'));$this->assertSame(123.0,(float)$this->value($edit,'transport_amount'));
        $edit->assertSee('data-rental-transport-default="preserved"',false);$this->fixture('edit',$edit);
        $this->assertSame($before,$rental->fresh()->getRawOriginal());
    }
    public function test_accepted_quotation_prefill_remains_exact_instead_of_reapplying_new_defaults(): void
    {
        $e=Enquiry::create(['organization_id'=>$this->admin->organization_id,'owner_id'=>$this->admin->id,'created_by_user_id'=>$this->admin->id,
            'name'=>$this->customer->name,'subject'=>'Synthetic agreed pricing','source'=>'incoming_phone','intent'=>'rental','stage'=>'accepted']);
        $quote=$e->quotations()->create(['organization_id'=>$e->organization_id,'kind'=>'rental','revision'=>1,'status'=>'accepted','valid_until'=>'2026-11-20',
            'snapshot'=>['customer_id'=>$this->customer->id,'recipient'=>['name'=>$this->customer->name,'phone'=>$this->customer->phone,'address'=>$this->customer->address],
                'currency'=>'INR','region'=>app(\App\Services\Sales\SalesWorkspace::class)->region($this->admin->organization),'timezone'=>'Asia/Kolkata','tax_type'=>'cgst_sgst','transport'=>600,'deposit'=>0,'total'=>2805,'payable_with_deposit'=>2805,
                'start_date'=>'2026-11-09','end_date'=>'2026-12-08','lines'=>[['product_id'=>$this->small->id,'name'=>$this->small->name,'line_type'=>'rental','quantity'=>1,'rate'=>2100,'tax_rate'=>5,'tax_mode'=>'exclusive']]]]);
        $before=$quote->fresh()->getRawOriginal();$page=$this->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertOk();
        $this->assertSame('2100.00',$this->value($page,'rental_amount'));$this->assertSame('0',$this->value($page,'deposit_amount'));$this->assertSame('600',$this->value($page,'transport_amount'));
        $page->assertSee('data-rental-transport-default="preserved"',false)->assertDontSee('id="rentalUseSuggestedTransport"',false);
        $dom=new \DOMDocument();@$dom->loadHTML($page->getContent());$xpath=new \DOMXPath($dom);
        $this->assertSame('exclusive',$xpath->evaluate('string(//select[@id="gst_mode"]/option[@selected]/@value)'));
        $this->fixture('quotation',$page);$this->assertSame($before,$quote->fresh()->getRawOriginal());$this->assertDatabaseCount('rentals',0);
    }
    public function test_inclusive_master_default_creates_matching_rental_and_invoice_without_tax_added_twice(): void
    {
        $this->small->update(['rental_price'=>1180,'rental_price_30_days'=>1180,'cgst_rate'=>9,'sgst_rate'=>9]);
        $master=\App\Support\CommercialDefaults::productData($this->small->fresh());
        $this->actingAs($this->sales)->post(route('rentals.store'),[
            'customer_id'=>$this->customer->id,'customer_name'=>$this->customer->name,'phone'=>$this->customer->phone,
            'product_id'=>$this->small->id,'quantity'=>1,'start_date'=>'2026-11-09','end_date'=>'2026-12-08',
            'rental_amount'=>$master['rental'],'gst_rate'=>$master['tax_rate'],'gst_mode'=>$master['tax_mode'],'tax_type'=>'cgst_sgst',
            'deposit_amount'=>\App\Support\CommercialDefaults::DEPOSIT,'transport_amount'=>\App\Support\CommercialDefaults::SMALL_TRANSPORT,
            'other_amount'=>0,'enquiry_source'=>'incoming_phone',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $rental=Rental::sole()->load('rentalItems');$invoice=Invoice::sole()->load('items');$line=$invoice->items->firstWhere('source_type','rental');
        $this->assertSame(1180.0,(float)$rental->rental_amount);$this->assertSame(2000.0,(float)$rental->deposit_amount);$this->assertSame(800.0,(float)$rental->transport_amount);
        $this->assertSame('inclusive',$rental->rentalItems->first()->gst_mode);$this->assertSame(18.0,(float)$line->tax_percentage);
        $this->assertSame(1000.0,(float)$line->taxable_amount);$this->assertSame(90.0,(float)$line->cgst_amount);$this->assertSame(90.0,(float)$line->sgst_amount);$this->assertSame(1180.0,(float)$line->line_total);
        $this->assertSame(180.0,(float)$invoice->total_tax_amount);
        $this->assertSame(3980.0,(float)$invoice->total_amount);Mail::assertNothingSent();
    }
}
