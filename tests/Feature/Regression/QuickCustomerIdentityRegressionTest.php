<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, Invoice, Product, Quotation, Rental, User};
use App\Services\Sales\QuotationConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class QuickCustomerIdentityRegressionTest extends TestCase
{
    use RefreshDatabase;
    private $admin;
    private Customer $helen;

    protected function setUp(): void
    {
        parent::setUp();$this->admin=TestData::user();$this->actingAs($this->admin);
        $this->helen=Customer::create(['organization_id'=>$this->admin->organization_id,'customer_type'=>'Individual',
            'salutation'=>'Ms.','first_name'=>'Helen','name'=>'Ms. Helen','phone'=>'+919001234567','email'=>'helen@example.test',
            'address'=>'Keep Helen address unchanged','city'=>'Bengaluru','state'=>'Karnataka']);
    }

    private function kiara(array $extra=[]): array
    {
        return $extra+['customer_type'=>'Individual','salutation'=>'Ms.','first_name'=>'Kiara','last_name'=>'','name'=>'Ms. Kiara',
            'phone_country_code'=>'+91','phone'=>'9001234567','email'=>'kiara@example.test','address'=>'Kiara delivery address',
            'city'=>'Bengaluru','state'=>'Karnataka'];
    }

    public function test_same_phone_different_name_returns_a_choice_without_selecting_or_creating_customer(): void
    {
        $original=$this->helen->fresh()->getRawOriginal();
        $response=$this->postJson(route('rentals.customers.quick-store'),$this->kiara())->assertConflict()
            ->assertJsonPath('code','customer_identity_conflict')->assertJsonPath('entered_name','Ms. Kiara')
            ->assertJsonPath('matches.0.id',$this->helen->id)->assertJsonPath('matches.0.name','Ms. Helen');
        $this->assertNull($response->json('customer'));$this->assertDatabaseCount('customers',1);
        $this->assertSame($original,$this->helen->fresh()->getRawOriginal());
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }

    public function test_sales_can_explicitly_create_kiara_with_shared_family_phone_and_repeat_is_idempotent(): void
    {
        $this->actingAs(TestData::user($this->admin->organization,['role'=>User::ROLE_SALES]));
        $original=$this->helen->fresh()->getRawOriginal();
        $data=$this->kiara(['quick_conflict_action'=>'separate','quick_conflict_customer_ids'=>[$this->helen->id]]);
        $created=$this->postJson(route('rentals.customers.quick-store'),$data)->assertOk()->assertJsonPath('reused',false)
            ->assertJsonPath('customer.name','Ms. Kiara')->assertJsonPath('customer.address','Kiara delivery address');
        $this->assertNotEquals($this->helen->id,$created->json('customer.id'));
        $this->assertDatabaseHas('customers',['id'=>$created->json('customer.id'),'first_name'=>'Kiara','phone'=>'+919001234567']);
        $this->postJson(route('rentals.customers.quick-store'),$data)->assertOk()->assertJsonPath('customer.id',$created->json('customer.id'));
        $this->assertDatabaseCount('customers',2);$this->assertSame($original,$this->helen->fresh()->getRawOriginal());
    }

    public function test_explicit_shared_contact_customer_can_later_update_address_without_changing_helen(): void
    {
        $data=$this->kiara(['quick_conflict_action'=>'separate','quick_conflict_customer_ids'=>[$this->helen->id],'email'=>'helen@example.test']);
        $created=$this->postJson(route('rentals.customers.quick-store'),$data)->assertOk();
        $id=$created->json('customer.id');
        $this->put(route('customers.update',$id),$this->kiara(['email'=>'helen@example.test','address'=>'Updated Kiara address']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers',['id'=>$id,'address'=>'Updated Kiara address']);
        $this->assertSame('Keep Helen address unchanged',$this->helen->fresh()->address);
        Customer::create(['organization_id'=>$this->admin->organization_id,'name'=>'Another customer','phone'=>'+919009876543']);
        $this->putJson(route('customers.update',$id),$this->kiara(['phone'=>'9009876543','email'=>'helen@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseHas('customers',['id'=>$id,'phone'=>'+919001234567']);
    }

    public function test_reuse_of_a_different_person_requires_explicit_existing_profile_selection(): void
    {
        $this->postJson(route('sales.customers.quick-store'),$this->kiara(['quick_conflict_action'=>'existing','quick_existing_customer_id'=>$this->helen->id]))
            ->assertOk()->assertJsonPath('customer.id',$this->helen->id)->assertJsonPath('reused',true);
        $this->assertDatabaseCount('customers',1);$this->assertSame('Ms. Helen',$this->helen->fresh()->name);
    }

    public function test_email_match_is_checked_even_when_a_different_valid_phone_is_present(): void
    {
        $this->postJson(route('rentals.customers.quick-store'),$this->kiara(['phone'=>'9988776655','email'=>'helen@example.test']))
            ->assertConflict()->assertJsonPath('matches.0.id',$this->helen->id);
        $this->assertDatabaseCount('customers',1);
    }

    public function test_foreign_or_changed_conflict_ids_cannot_authorize_a_shared_contact(): void
    {
        $foreign=Customer::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign private customer','phone'=>'+919001234567']);
        $this->postJson(route('rentals.customers.quick-store'),$this->kiara(['quick_conflict_action'=>'existing','quick_existing_customer_id'=>$foreign->id]))->assertUnprocessable();
        $this->postJson(route('rentals.customers.quick-store'),$this->kiara(['quick_conflict_action'=>'separate','quick_conflict_customer_ids'=>[$foreign->id]]))
            ->assertConflict()->assertDontSee('Foreign private customer');
        $this->assertDatabaseCount('customers',2);
    }

    public function test_invalid_new_customer_is_never_replaced_by_matching_phone_profile(): void
    {
        $this->postJson(route('rentals.customers.quick-store'),$this->kiara(['first_name'=>'','name'=>'']))
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->assertDatabaseCount('customers',1);
    }

    public function test_regular_create_keeps_contact_uniqueness_and_unauthorized_role_cannot_quick_create(): void
    {
        $this->postJson(route('customers.store'),$this->kiara())->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAs(TestData::user($this->admin->organization,['role'=>User::ROLE_DELIVERY]))
            ->postJson(route('rentals.customers.quick-store'),$this->kiara())->assertForbidden();
        $this->assertDatabaseCount('customers',1);
    }

    public function test_foreign_phone_does_not_block_valid_sales_customer_and_modal_preserves_conflict_controls(): void
    {
        $other=TestData::organization();Customer::create(['organization_id'=>$other->id,'name'=>'Foreign customer','phone'=>'+919988776655']);
        $this->actingAs(TestData::user($this->admin->organization,['role'=>User::ROLE_SALES]));
        $this->postJson(route('rentals.customers.quick-store'),$this->kiara(['phone'=>'9988776655']))
            ->assertOk()->assertJsonPath('customer.name','Ms. Kiara');
        $response=$this->get(route('rentals.create'))->assertOk()->assertSee('data-customer-identity-conflict',false);
        if ($folder=getenv('PHOS_CUSTOMER_IDENTITY_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder,0700,true);
            file_put_contents($folder.'/rental.html',$response->getContent());
        }
    }

    public function test_real_sales_quick_create_and_quotation_order_keep_kiara_identity_and_require_new_confirmation(): void
    {
        config(['app.rental_agreements'=>false]);
        $this->admin->organization->update(['state'=>'Karnataka','country_code'=>'IN','currency_code'=>'INR']);
        $sales=TestData::user($this->admin->organization,['role'=>User::ROLE_SALES]);$this->actingAs($sales);
        $product=Product::create(['organization_id'=>$sales->organization_id,'name'=>'Identity test concentrator','product_type'=>'rentable',
            'stock_mode'=>'untracked','is_rentable'=>true,'is_sellable'=>false,'price_per_day'=>100,'rental_price'=>3500,'total_quantity'=>10,'available_quantity'=>10]);
        $this->post(route('enquiries.store'),['salutation'=>'Ms.','name'=>'Kiara','phone'=>'+919001234567','email'=>'kiara@example.test',
            'subject'=>'Oxygen concentrator for Kiara','intent'=>'rental','source'=>'incoming_phone','owner_id'=>$sales->id])->assertSessionHasNoErrors();
        $enquiry=Enquiry::sole();
        $this->post(route('quotations.store',$enquiry),['kind'=>'rental','enquiry_lock_version'=>$enquiry->lock_version,
            'valid_until'=>now()->addDays(7)->toDateString(),'start_date'=>today()->addDay()->toDateString(),'end_date'=>today()->addDays(30)->toDateString(),
            'transport'=>600,'deposit'=>2000,'tax_type'=>'cgst_sgst','lines'=>[['product_id'=>$product->id,'quantity'=>1,'rate'=>3500,'tax_rate'=>5,'tax_mode'=>'inclusive']]])->assertSessionHasNoErrors();
        $quote=Quotation::sole();
        foreach (['send','accepted'] as $action) $this->post(route('quotations.action',$quote),['action'=>$action,'lock_version'=>$quote->fresh()->lock_version,
            'contact_name'=>'Kiara','note'=>'Confirmed by telephone'])->assertSessionHasNoErrors();
        $orderPage=$this->get(route('rentals.create',['quotation_id'=>$quote->id]))->assertOk();
        $conflict=$this->postJson(route('rentals.customers.quick-store'),$this->kiara())->assertConflict();
        $created=$this->postJson(route('rentals.customers.quick-store'),$this->kiara(['quick_conflict_action'=>'separate','quick_conflict_customer_ids'=>[$this->helen->id]]))->assertOk();
        $kiaraId=$created->json('customer.id');$this->assertDatabaseHas('customers',['id'=>$kiaraId,'first_name'=>'Kiara']);
        if ($folder=getenv('PHOS_CUSTOMER_IDENTITY_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder,0700,true);
            file_put_contents($folder.'/conversion.html',$orderPage->getContent());
            file_put_contents($folder.'/actual-api-responses.json',json_encode(['conflict'=>$conflict->json(),'created'=>$created->json(),'helen_id'=>$this->helen->id]));
        }
        $payload=app(QuotationConversion::class)->prefill($quote->fresh());
        $payload['customer_id']=$kiaraId;$payload['delivery_assignment_type']='customer_pickup';
        $this->post(route('rentals.store'),$payload)->assertSessionHasErrors('quotation_recipient_confirmed');
        $this->assertDatabaseCount('rentals',0);
        $this->post(route('rentals.store'),$payload+['quotation_recipient_confirmed'=>1])->assertSessionHasNoErrors();
        $rental=Rental::sole();$this->assertSame((int)$kiaraId,(int)$rental->customer_id);
        $this->assertSame((int)$kiaraId,(int)Invoice::sole()->customer_id);
        $this->assertStringContainsString('Kiara',$rental->customer_name);$this->assertStringNotContainsString('Helen',$rental->customer_name);
        $this->assertDatabaseHas('customers',['id'=>$this->helen->id,'name'=>'Ms. Helen','address'=>'Keep Helen address unchanged']);
    }
}
