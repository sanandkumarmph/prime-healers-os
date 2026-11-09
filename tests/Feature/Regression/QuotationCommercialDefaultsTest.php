<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry,Product,Quotation,Role,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class QuotationCommercialDefaultsTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private Enquiry $enquiry;
    private Product $small;

    protected function setUp(): void
    {
        parent::setUp();Mail::fake();$this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(12,0));
        $org=TestData::organization(['currency_code'=>'INR','state'=>'Karnataka']);
        $this->owner=TestData::user($org);$this->actingAs($this->owner);
        $this->enquiry=Enquiry::create(['organization_id'=>$org->id,'owner_id'=>$this->owner->id,'name'=>'Synthetic defaults prospect','subject'=>'Editable quotation defaults','source'=>'incoming_phone','intent'=>'rental']);
        $this->small=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic small concentrator','product_type'=>'both','stock_mode'=>'untracked','total_quantity'=>20,'available_quantity'=>20,
            'rental_price'=>3500,'rental_price_15_days'=>2000,'rental_price_30_days'=>3500,'rental_price_3_months'=>9000,'sale_price'=>20000,'price_per_day'=>100,'gst_tax_type'=>'cgst_sgst','cgst_rate'=>2.5,'sgst_rate'=>2.5,'gst_calculation_mode'=>'exclusive']);
        Product::create(['organization_id'=>$org->id,'name'=>'Synthetic hospital bed','product_type'=>'both','stock_mode'=>'untracked','total_quantity'=>10,'available_quantity'=>10,'rental_price'=>5000,'sale_price'=>25000,'price_per_day'=>200]);
        Product::create(['organization_id'=>$org->id,'name'=>'Synthetic nasal cannula','product_type'=>'sellable','stock_mode'=>'untracked','total_quantity'=>100,'available_quantity'=>100,'sale_price'=>150,'price_per_day'=>0]);
    }

    private function payload(array $changes=[]): array
    {
        return array_replace(['kind'=>'rental','valid_until'=>'2026-11-20','start_date'=>'2026-11-09','end_date'=>'2026-12-08','transport'=>800,'deposit'=>2000,
            'tax_type'=>'cgst_sgst','enquiry_lock_version'=>$this->enquiry->fresh()->lock_version,'customer_id'=>null,
            'lines'=>[['product_id'=>$this->small->id,'quantity'=>1,'rate'=>3500,'tax_rate'=>5,'tax_mode'=>'inclusive']]],$changes);
    }

    private function saveFixture(string $name,string $html): void
    {
        if($folder=getenv('PHOS_QUOTE_DEFAULTS_RENDER_DIR')){if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$name.'.html',$html);}
    }

    private function field(string $html,string $id): \DOMElement
    {
        $dom=new \DOMDocument();@$dom->loadHTML($html);return (new \DOMXPath($dom))->query('//*[@id="'.$id.'"]')->item(0);
    }

    public function test_fresh_admin_and_sales_quote_defaults_and_catalogue_do_not_gross_up_master_prices(): void
    {
        foreach([User::ROLE_SUPER_ADMIN,User::ROLE_SALES] as $role){
            if($role===User::ROLE_SALES){$permissions=Role::defaultSystemRoleTemplates()['sales']['permissions'];$r=Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Sales','slug'=>'sales','is_active'=>true,'permissions'=>$permissions]);$this->owner->update(['role'=>$role,'role_id'=>$r->id]);$this->owner->unsetRelation('assignedRole');}
            $response=$this->get(route('quotations.create',$this->enquiry))->assertOk();$html=$response->getContent();
            $this->assertSame('2000',$this->field($html,'deposit')->getAttribute('value'));$this->assertSame('800',$this->field($html,'transport')->getAttribute('value'));
            $this->assertSame('1',$this->field($html,'transport')->getAttribute('data-commercial-auto'));
            $this->saveFixture($role,$html);
        }
        $this->assertDatabaseCount('quotations',0);Mail::assertNothingSent();
    }

    public function test_inclusive_master_rate_and_editable_zero_amounts_survive_save_and_edit(): void
    {
        $this->post(route('quotations.store',$this->enquiry),$this->payload(['transport'=>0,'deposit'=>0]))->assertSessionHasNoErrors();
        $quote=Quotation::sole();$this->assertSame(3500,(int)$quote->snapshot['total']);$this->assertSame(0,(int)$quote->snapshot['deposit']);
        $this->assertSame('inclusive',$quote->snapshot['lines'][0]['tax_mode']);$this->assertEquals(166.67,$quote->snapshot['lines'][0]['tax']);
        $html=$this->get(route('quotations.edit',$quote))->assertOk()->getContent();
        $this->assertSame('0',$this->field($html,'transport')->getAttribute('value'));$this->assertSame('0',$this->field($html,'deposit')->getAttribute('value'));
        $this->assertSame('0',$this->field($html,'transport')->getAttribute('data-commercial-auto'));$this->saveFixture('saved-zero',$html);
    }

    public function test_saved_and_validation_retry_values_are_preserved_including_explicit_exclusive_gst(): void
    {
        $payload=$this->payload(['transport'=>450,'deposit'=>1200]);$payload['lines'][0]['tax_mode']='exclusive';
        $this->post(route('quotations.store',$this->enquiry),$payload)->assertSessionHasNoErrors();$quote=Quotation::sole();
        $html=$this->get(route('quotations.edit',$quote))->assertOk()->getContent();$this->assertSame('450',$this->field($html,'transport')->getAttribute('value'));
        $this->assertSame('1200',$this->field($html,'deposit')->getAttribute('value'));$this->saveFixture('saved-exclusive',$html);
        $html=$this->withSession(['_old_input'=>$this->payload(['transport'=>'0','deposit'=>'0'])])->get(route('quotations.edit',$quote))->assertOk()->getContent();
        $this->assertSame('0',$this->field($html,'transport')->getAttribute('value'));$this->assertSame('0',$this->field($html,'transport')->getAttribute('data-commercial-auto'));
        $this->saveFixture('validation-zero',$html);
    }

    public function test_product_master_both_tax_rate_is_available_and_sale_has_no_deposit(): void
    {
        $this->small->update(['gst_tax_type'=>'both','cgst_rate'=>1,'sgst_rate'=>1,'igst_rate'=>2]);
        $this->enquiry->update(['intent'=>'sale']);
        $this->get(route('quotations.create',$this->enquiry))->assertOk()->assertSee('>2%</option>',false);
        $payload=$this->payload(['kind'=>'sale','deposit'=>0]);$payload['lines'][0]['tax_rate']=2;
        $this->post(route('quotations.store',$this->enquiry),$payload)->assertSessionHasNoErrors();$quote=Quotation::sole();
        $this->assertSame(0,(int)$quote->snapshot['deposit']);$this->assertSame(4300,(int)$quote->snapshot['total']);
    }
}
