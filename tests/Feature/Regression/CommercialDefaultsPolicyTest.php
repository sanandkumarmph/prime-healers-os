<?php

namespace Tests\Feature\Regression;

use App\Models\{Product,Role,User};
use App\Support\CommercialDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CommercialDefaultsPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $extra=[]): Product
    {
        return Product::create($extra+['organization_id'=>TestData::organization()->id,'name'=>'Oxygen concentrator',
            'product_type'=>'both','stock_mode'=>'untracked','total_quantity'=>5,'available_quantity'=>5,'rental_price'=>3500,
            'rental_price_15_days'=>2100,'rental_price_30_days'=>3500,'rental_price_3_months'=>9000,'price_per_day'=>150,'sale_price'=>42000,
            'gst_tax_type'=>Product::GST_TAX_TYPE_CGST_SGST,'gst_calculation_mode'=>'exclusive','cgst_rate'=>2.5,'sgst_rate'=>2.5,'igst_rate'=>0]);
    }

    private function payload(array $extra=[]): array
    {
        return $extra+['name'=>'Synthetic Product Master','product_type'=>'both','price_per_day'=>100,'rental_price_30_days'=>3000,
            'sale_price'=>20000,'quantity'=>5,'gst_tax_type'=>Product::GST_TAX_TYPE_CGST_SGST,'gst_calculation_mode'=>'exclusive',
            'cgst_rate'=>2.5,'sgst_rate'=>2.5,'igst_rate'=>0];
    }

    public function test_transport_threshold_counts_total_units_and_rental_only_deposit(): void
    {
        $small=$this->product();$bed=$this->product(['name'=>'Hospital Bed Manual - Kraft 1 Function Deluxe']);
        $this->assertSame(2000,CommercialDefaults::depositFor('rental'));
        $this->assertSame(0,CommercialDefaults::depositFor('sale'));
        $this->assertSame(800,CommercialDefaults::transportFor([]));
        $this->assertSame(800,CommercialDefaults::transportFor([['product'=>$small,'quantity'=>3]]));
        $this->assertSame(2500,CommercialDefaults::transportFor([['product'=>$small,'quantity'=>4]]));
        $this->assertSame(2500,CommercialDefaults::transportFor([['product'=>$small,'quantity'=>2],['product'=>$small,'quantity'=>2]]));
        $this->assertSame(2500,CommercialDefaults::transportFor([['product'=>$bed,'quantity'=>1]]));
        $this->assertSame(800,CommercialDefaults::transportFor([['product'=>$bed,'quantity'=>0],['product'=>$small,'quantity'=>1]]));
        $this->assertSame(800,CommercialDefaults::transportFor([['quantity'=>4]]));
    }

    public function test_automatic_beds_and_explicit_transport_choices_do_not_require_catalogue_queries(): void
    {
        foreach(['Hospital Bed Manual - Kraft 1 Function Deluxe','HOSPITAL BEDS','Electric patient bed','Bed Manual 2 Function',
            'Hospital bed with mattress','Hospital bed including side rails','Manual bed with mattress and rails','Hospital bed includes mattress'] as $name)
            $this->assertTrue(CommercialDefaults::isHeavy(new Product(['name'=>$name])),$name);
        foreach(['Nasal cannula','Hospital bed mattress','Bed sheet','Bedside table','Bedpan','Mattress for hospital bed','Hospital bed rails',
            'Table for hospital bed','Hospital bed mattress with cover','Side rails for hospital bed','Premium with hospital bed mattress'] as $name)
            $this->assertFalse(CommercialDefaults::isHeavy(new Product(['name'=>$name])),$name);
        $this->assertTrue(CommercialDefaults::isHeavy(new Product(['name'=>'Kraft 1 Function Deluxe','category'=>'Hospital Beds'])));
        $this->assertTrue(CommercialDefaults::isHeavy(new Product(['name'=>'Large device','transport_class'=>'heavy'])));
        $this->assertFalse(CommercialDefaults::isHeavy(new Product(['name'=>'Hospital bed','transport_class'=>'small'])));
        $this->assertFalse(CommercialDefaults::isHeavy(new Product(['name'=>'Hospital bed with mattress','transport_class'=>'small'])));
        $this->assertTrue(CommercialDefaults::isHeavy(new Product(['name'=>'Mattress for hospital bed','transport_class'=>'heavy'])));
    }

    public function test_master_prices_are_copied_unchanged_with_inclusive_gst_and_no_master_rewrite(): void
    {
        $product=$this->product()->fresh();$before=$product->getRawOriginal();$data=CommercialDefaults::productData($product);
        $this->assertSame(3500.0,$data['rental']);$this->assertSame(42000.0,$data['sale']);
        $this->assertSame(150.0,$data['daily']);$this->assertSame(2100.0,$data['days15']);
        $this->assertSame(3500.0,$data['days30']);$this->assertSame(9000.0,$data['months3']);
        $this->assertSame(5.0,$data['tax_rate']);$this->assertSame('inclusive',$data['tax_mode']);
        $this->assertSame($before,$product->fresh()->getRawOriginal());$this->assertSame('exclusive',$product->fresh()->gst_calculation_mode);
        $product->gst_tax_type=Product::GST_TAX_TYPE_IGST;$product->igst_rate=12;
        $this->assertSame(12.0,CommercialDefaults::gstRate($product));
        $product->gst_tax_type=Product::GST_TAX_TYPE_BOTH;
        $this->assertSame(5.0,CommercialDefaults::gstRate($product));
        $product->cgst_rate=0;$product->sgst_rate=0;$this->assertSame(12.0,CommercialDefaults::gstRate($product));
        $product->gst_tax_type=null;$this->assertSame(0.0,CommercialDefaults::gstRate($product));
    }

    public function test_admin_can_edit_transport_class_and_omitted_legacy_updates_preserve_it(): void
    {
        $org=TestData::organization();$this->actingAs(TestData::user($org));
        $this->post(route('products.store'),$this->payload(['transport_class'=>'heavy']))->assertSessionHasNoErrors()->assertRedirect();
        $product=Product::where('organization_id',$org->id)->sole();$this->assertSame('heavy',$product->transport_class);
        $this->get(route('products.edit',$product))->assertOk()->assertSee('Transport default')->assertSee('value="heavy" selected',false);
        $this->put(route('products.update',$product),$this->payload(['transport_class'=>'small']))->assertSessionHasNoErrors();
        $this->assertSame('small',$product->fresh()->transport_class);
        $this->put(route('products.update',$product),$this->payload())->assertSessionHasNoErrors();
        $this->assertSame('small',$product->fresh()->transport_class);
        $this->put(route('products.update',$product),$this->payload(['transport_class'=>null]))->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->transport_class);
        $this->assertSame(3000.0,(float)$product->fresh()->rental_price);
    }

    public function test_invalid_and_unauthorized_transport_changes_are_rejected(): void
    {
        $product=$this->product(['transport_class'=>'heavy']);$this->actingAs(TestData::user($product->organization));
        $this->put(route('products.update',$product),$this->payload(['name'=>$product->name,'transport_class'=>'expensive']))->assertSessionHasErrors('transport_class');
        $this->assertSame('heavy',$product->fresh()->transport_class);
        $this->actingAs(TestData::user())->put(route('products.update',$product),$this->payload(['transport_class'=>'small']))->assertForbidden();
        $role=Role::create(['organization_id'=>$product->organization_id,'name'=>'Sales','slug'=>'sales','is_active'=>true,'is_system'=>true,
            'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $this->actingAs(TestData::user($product->organization,['role'=>User::ROLE_SALES,'role_id'=>$role->id]))->putJson(route('products.update',$product),$this->payload(['transport_class'=>'small']))->assertForbidden();
        $this->assertSame('heavy',$product->fresh()->transport_class);
    }
}
