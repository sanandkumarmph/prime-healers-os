<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class QuotationProductSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_sales_receive_searchable_products_without_foreign_catalogue(): void
    {
        $org=TestData::organization(['currency_code'=>'INR','state'=>'Karnataka']);
        foreach (['Oxygen concentrator Mini'=>'rentable','Nasal cannula'=>'sellable','Portable oxygen cylinder'=>'both'] as $name=>$type) {
            Product::create(['organization_id'=>$org->id,'name'=>$name,'product_type'=>$type,'stock_mode'=>'untracked',
                'sku'=>$type==='rentable'?'OX-5':'ITEM-'.$type,'brand'=>$type==='rentable'?'Oxymed':'Clinical','model_name'=>$type==='rentable'?'Mini':'Standard',
                'available_quantity'=>10,'total_quantity'=>10,'price_per_day'=>100,'rental_price'=>3000,'sale_price'=>150]);
        }
        Product::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign private catalogue item','product_type'=>'both','stock_mode'=>'untracked','price_per_day'=>1,'available_quantity'=>0,'total_quantity'=>0]);
        foreach ([User::ROLE_SUPER_ADMIN,User::ROLE_SALES] as $role) {
            $user=TestData::user($org,['role'=>$role]);$this->actingAs($user);
            $enquiry=Enquiry::create(['organization_id'=>$org->id,'owner_id'=>$user->id,'name'=>'Synthetic prospect','subject'=>'Rental product search','intent'=>'rental','source'=>'incoming_phone']);
            $response=$this->get(route('quotations.create',$enquiry))->assertOk()->assertSee('data-quote-product-picker',false)
                ->assertSee('OX-5')->assertSee('phos-quote-product-search.js')->assertDontSee('Foreign private catalogue item');
            if($folder=getenv('PHOS_PRODUCT_SEARCH_RENDER_DIR')){
                if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$role.'.html',$response->getContent());
            }
        }
        $this->assertDatabaseCount('quotations',0);$this->assertDatabaseCount('rentals',0);
    }
}
