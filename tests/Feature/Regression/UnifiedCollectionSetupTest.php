<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Delivery, FollowUp, Invoice, Product, Rental, User};
use App\Services\Deliveries\OrderCollectionSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestData;
use Tests\TestCase;

class UnifiedCollectionSetupTest extends TestCase
{
    use RefreshDatabase;
    private User $admin, $sales, $driver;
    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $org=TestData::organization();$this->admin=TestData::user($org);
        $this->sales=TestData::user($org,['role'=>User::ROLE_SALES]);
        $this->driver=TestData::user($org,['role'=>User::ROLE_DELIVERY]);
        $this->customer=Customer::create(['organization_id'=>$org->id,'name'=>'Collection test customer','phone'=>'+919000000001']);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Collection rental product','product_type'=>Product::TYPE_RENTABLE,
            'stock_mode'=>Product::STOCK_MODE_UNTRACKED,'is_rentable'=>true,'is_sellable'=>false,'available_quantity'=>10,'total_quantity'=>10,'price_per_day'=>100]);
        $this->actingAs($this->admin);
    }

    private function payload(array $extra=[]): array
    {
        return $extra+['customer_type'=>'direct_customer','customer_id'=>$this->customer->id,'customer_name'=>$this->customer->name,
            'phone'=>$this->customer->phone,'product_id'=>$this->product->id,'quantity'=>1,'start_date'=>'2026-11-10','end_date'=>'2026-11-15',
            'rental_amount'=>1000,'deposit_amount'=>0,'transport_amount'=>0,'other_amount'=>0,'fulfilment_source'=>'in_house',
            'delivery_assignment_type'=>'ph_internal','delivery_responsibility'=>'ph_internal_delivery','pickup_responsibility'=>'ph_internal_pickup'];
    }

    private function deliverySetup(array $extra=[]): array
    {
        return $extra+['collection_owner'=>'delivery','collection_enabled'=>1,'collection_submission_token'=>(string)\Illuminate\Support\Str::uuid(),
            'collection_amount'=>'100.00','collection_methods'=>'cash','collection_instructions'=>'Call before collecting.',
            'delivery_staff_id'=>'user:'.$this->driver->id];
    }

    public function test_create_pages_show_one_collection_section_and_retain_validation_input(): void
    {
        foreach (['rentals.create','sales.create'] as $route) {
            $response=$this->get(route($route))->assertOk()->assertSee('Who will collect the unpaid amount?');
            $dom=new \DOMDocument();@$dom->loadHTML($response->getContent());$xpath=new \DOMXPath($dom);
            $this->assertCount(1,$xpath->query('//*[@data-order-collection]'));
            $this->assertCount(1,$xpath->query('//*[@id="collection_owner"]'));
            $this->assertCount(1,$xpath->query('//*[@id="collection_mode"]'));
            $this->assertCount(1,$xpath->query('//*[@data-sales-collection-fields]//*[@id="collection_assignee_id"]'));
            if ($folder=getenv('PHOS_COLLECTION_RENDER_DIR')) {
                if (!is_dir($folder)) mkdir($folder,0700,true);
                file_put_contents($folder.'/'.($route==='rentals.create'?'rental':'sale').'.html',$response->getContent());
            }
        }
        $this->withSession(['_old_input'=>['collection_owner'=>'sales','collection_mode'=>'cash','collection_assignee_id'=>$this->sales->id]])
            ->get(route('rentals.create'))->assertOk()->assertSee('value="sales" selected',false);
    }

    public function test_forged_delivery_collection_is_rejected_for_pickup_before_any_order_is_saved(): void
    {
        $this->post(route('rentals.store'),$this->payload($this->deliverySetup(['delivery_assignment_type'=>'customer_pickup'])))
            ->assertSessionHasErrors('collection_enabled');
        $this->assertDatabaseCount('rentals',0);$this->assertDatabaseCount('invoices',0);
        $this->assertDatabaseCount('payments',0);$this->assertDatabaseCount('order_collection_submissions',0);
        $this->assertSame(10,(int)$this->product->fresh()->available_quantity);
    }

    public function test_internal_collection_assigns_one_delivery_and_does_not_record_a_payment(): void
    {
        $this->post(route('rentals.store'),$this->payload($this->deliverySetup()))->assertSessionHasNoErrors();
        $rental=Rental::sole();$invoice=Invoice::where('rental_id',$rental->id)->sole();
        $task=Delivery::where('rental_id',$rental->id)->sole();
        $this->assertTrue($task->collection_required);$this->assertEquals(100,$task->collection_amount_to_collect);
        $this->assertSame($this->driver->id,(int)$task->assigned_user_id);
        $this->assertEquals(1000,$invoice->balance_amount);$this->assertDatabaseCount('payments',0);
        $this->assertSame(0,FollowUp::where('rental_id',$rental->id)->where('source','like','order_collection:%')->count());
    }

    public function test_self_pickup_can_still_assign_sales_followup_without_delivery_pod_or_receipt(): void
    {
        $this->post(route('rentals.store'),$this->payload(['delivery_assignment_type'=>'customer_pickup','collection_owner'=>'sales',
            'collection_mode'=>'cash','collection_assignee_id'=>$this->sales->id]))->assertSessionHasNoErrors();
        $rental=Rental::sole();
        $this->assertDatabaseHas('follow_ups',['rental_id'=>$rental->id,'assigned_user_id'=>$this->sales->id,'source'=>'order_collection:cash']);
        $this->assertSame(0,Delivery::where('rental_id',$rental->id)->where('collection_required',true)->count());
        $this->assertDatabaseCount('order_collection_submissions',0);$this->assertDatabaseCount('payments',0);
    }

    public function test_two_owners_or_incomplete_sales_intent_cannot_silently_create_an_order(): void
    {
        $this->post(route('rentals.store'),$this->payload($this->deliverySetup(['collection_mode'=>'cash','collection_assignee_id'=>$this->sales->id])))
            ->assertSessionHasErrors('collection_owner');
        $this->post(route('rentals.store'),$this->payload(['collection_owner'=>'sales']))->assertSessionHasErrors('collection_assignee_id');
        $this->assertDatabaseCount('rentals',0);$this->assertDatabaseCount('payments',0);
    }

    public function test_noninternal_modes_and_foreign_sales_assignees_remain_guarded(): void
    {
        foreach (['customer_pickup','vendor','third_party'] as $assignment) {
            $request=Request::create('/synthetic-collection','POST',$this->deliverySetup(['delivery_assignment_type'=>$assignment]));
            $request->setUserResolver(fn()=>$this->admin);
            try { app(OrderCollectionSetup::class)->validate($request,'rentals');$this->fail('Noninternal collection was accepted'); }
            catch (ValidationException $exception) {$this->assertArrayHasKey('collection_enabled',$exception->errors());}
        }
        $foreign=TestData::user(null,['role'=>User::ROLE_SALES]);
        $this->post(route('rentals.store'),$this->payload(['collection_owner'=>'sales','collection_mode'=>'cash','collection_assignee_id'=>$foreign->id]))
            ->assertSessionHasErrors('collection_assignee_id');
        $this->assertDatabaseCount('rentals',0);
    }
}
