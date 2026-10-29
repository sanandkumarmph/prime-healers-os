<?php

namespace Tests\Feature\Regression;

use App\Models\{ActivityLog, Customer, Delivery, DeliveryCashHandover, DeliveryCollection, Invoice, Payment, Product, Rental, User};
use App\Services\Deliveries\DeliveryCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class PhosCashWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private $collector;
    private $finance;
    private $admin;
    private $task;
    private $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $org = TestData::organization();
        $this->collector = TestData::user($org, ['role' => User::ROLE_DELIVERY]);
        $this->finance = TestData::user($org, ['role' => User::ROLE_FINANCE]);
        $this->admin = TestData::user($org);
        $customer = Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic collection customer','phone'=>'9000000000']);
        $product = Product::create(['organization_id'=>$org->id,'name'=>'Synthetic rental unit','product_type'=>'rentable','stock_mode'=>'untracked','price_per_day'=>10,'available_quantity'=>5,'total_quantity'=>5]);
        $rental = Rental::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'product_id'=>$product->id,'customer_name'=>$customer->name,'phone'=>$customer->phone,
            'quantity'=>1,'start_date'=>today(),'end_date'=>today()->addDays(29),'rental_amount'=>300,'status'=>'active']);
        $this->invoice = Invoice::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'rental_id'=>$rental->id,'invoice_number'=>'TEST-CASH-001',
            'invoice_date'=>today(),'due_date'=>today(),'status'=>'generated','payment_status'=>'unpaid','subtotal'=>300,'total_amount'=>300,'paid_amount'=>0,'balance_amount'=>300]);
        $this->task = Delivery::create(['organization_id'=>$org->id,'rental_id'=>$rental->id,'type'=>'delivery','status'=>'in_progress',
            'assigned_user_id'=>$this->collector->id,'assignment_type'=>'delivery_team','collection_required'=>true,'collection_amount_to_collect'=>250]);
        $this->actingAs($this->collector);
    }

    private function payload(array $extra=[]): array
    {
        $invoice=$this->invoice->fresh(); $invoice->syncFinancialStatus();
        return $extra + ['submission_token'=>(string)Str::uuid(),'version'=>app(DeliveryCollectionService::class)->version($this->task->fresh(),$invoice),
            'amount'=>150,'method'=>'cash','confirmed'=>1];
    }

    private function collect(array $extra=[]): DeliveryCollection
    {
        $this->post(route('delivery-collections.store',$this->task),$this->payload($extra))->assertSessionHasNoErrors()->assertRedirect();
        $entry=DeliveryCollection::latest('id')->firstOrFail();
        if ($directory=getenv('GETTING_STARTED_PREVIEW_DIR')) {
            file_put_contents($directory.'/phos-collection-ledger.html',$this->get(route('delivery-collections.show',$this->task))->assertOk()->getContent());
        }
        return $entry;
    }

    private function handover(DeliveryCollection $entry, float $amount=100): DeliveryCashHandover
    {
        $this->post(route('cash-handovers.store',$entry),['submission_token'=>(string)Str::uuid(),'amount'=>$amount,'confirmed'=>1])->assertSessionHasNoErrors()->assertRedirect();
        return DeliveryCashHandover::latest('id')->firstOrFail();
    }

    public function test_cash_posts_once_and_changed_token_replay_cannot_credit_twice(): void
    {
        $data=$this->payload();
        $this->post(route('delivery-collections.store',$this->task),$data)->assertSessionHasNoErrors();
        $this->post(route('delivery-collections.store',$this->task),$data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments',1); $this->assertDatabaseCount('delivery_collections',1);
        $this->assertEquals(150,$this->invoice->fresh()->paid_amount); $this->assertEquals(150,$this->invoice->fresh()->balance_amount);
        $data['amount']=50;
        $this->post(route('delivery-collections.store',$this->task),$data)->assertSessionHasErrors('collection');
        $this->assertDatabaseCount('payments',1);
    }

    public function test_authorisation_invoice_balance_and_stale_state_block_excess_or_uncertain_receipts(): void
    {
        $this->post(route('delivery-collections.store',$this->task),$this->payload(['amount'=>251]))->assertSessionHasErrors('collection');
        $stale=$this->payload(); $this->task->update(['collection_amount_to_collect'=>100]);
        $this->post(route('delivery-collections.store',$this->task),$stale)->assertSessionHasErrors('collection');
        $this->assertDatabaseCount('payments',0);
        $this->task->update(['collection_amount_to_collect'=>400]);
        $this->post(route('delivery-collections.store',$this->task),$this->payload(['amount'=>301]))->assertSessionHasErrors('collection');
    }

    public function test_online_report_requires_evidence_and_finance_verification_before_credit(): void
    {
        $this->post(route('delivery-collections.store',$this->task),$this->payload(['method'=>'upi']))->assertSessionHasErrors('collection');
        $entry=$this->collect(['method'=>'upi','reference'=>'SYNTHETIC-TRANSFER-1']);
        $this->assertDatabaseCount('payments',0); $this->assertEquals(0,$this->invoice->fresh()->paid_amount);
        $decision=['action'=>'verify','reason'=>'Checked synthetic bank receipt','confirmed'=>1];
        $this->post(route('delivery-collections.review',$entry),$decision)->assertForbidden();
        $this->actingAs($this->finance);
        $this->get(route('delivery-collections.index'))->assertOk()->assertSee('SYNTHETIC-TRANSFER-1');
        $this->post(route('delivery-collections.review',$entry),$decision)->assertSessionHasNoErrors();
        $this->post(route('delivery-collections.review',$entry),$decision)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments',1); $this->assertSame('verified',$entry->fresh()->status);
        $this->get(route('delivery-collections.show',$this->task))->assertOk()->assertSee('Customer receipt #');
        $proof=\App\Models\DeliveryProof::create(['organization_id'=>$this->task->organization_id,'delivery_id'=>$this->task->id,
            'rental_id'=>$this->task->rental_id,'workflow_stage'=>'delivery','capture_moment'=>'complete','proof_type'=>\App\Models\DeliveryProof::TYPE_COLLECTION,
            'created_by_user_id'=>$this->collector->id,'captured_at'=>now(),'file_path'=>'synthetic/private-proof.jpg']);
        $this->actingAs($this->collector);
        $this->post(route('delivery-collections.store',$this->task),$this->payload(['method'=>'upi','amount'=>10,'proof_id'=>$proof->id]))->assertSessionHasNoErrors();
        $this->post(route('delivery-collections.store',$this->task),$this->payload(['method'=>'upi','amount'=>10,'proof_id'=>$proof->id]))->assertSessionHasErrors('collection');
        $this->assertDatabaseCount('payments',1); $this->assertDatabaseCount('delivery_collections',2);
    }

    public function test_pending_online_reports_reserve_the_invoice_balance_without_becoming_receipts(): void
    {
        $this->collect(['method'=>'upi','reference'=>'SYNTHETIC-1','amount'=>200]);
        $other=$this->task->replicate(); $other->collection_amount_to_collect=300; $other->save();
        $invoice=$this->invoice->fresh();
        $data=$this->payload(['amount'=>150,'version'=>app(DeliveryCollectionService::class)->version($other,$invoice)]);
        $this->post(route('delivery-collections.store',$other),$data)->assertSessionHasErrors('collection');
        $this->assertDatabaseCount('delivery_collections',1); $this->assertDatabaseCount('payments',0);
    }

    public function test_partial_handover_preserves_outstanding_cash_and_never_adds_invoice_credit(): void
    {
        $entry=$this->collect(); $handover=$this->handover($entry);
        $this->assertEquals(50,$entry->fresh()->cashAmounts()['available']/100);
        $decision=['confirmation_token'=>(string)Str::uuid(),'received_amount'=>60,'confirmed'=>1];
        $this->actingAs($this->finance);
        if ($directory=getenv('GETTING_STARTED_PREVIEW_DIR')) file_put_contents($directory.'/phos-cash-handovers.html',$this->get(route('cash-handovers.index'))->assertOk()->getContent());
        $this->post(route('cash-handovers.confirm',$handover),$decision)->assertSessionHasErrors('handover');
        $decision['reason']='Received only part of the submitted cash';
        $this->post(route('cash-handovers.confirm',$handover),$decision)->assertSessionHasNoErrors();
        $this->post(route('cash-handovers.confirm',$handover),$decision)->assertSessionHasNoErrors();
        $amounts=$entry->fresh()->cashAmounts();
        $this->assertSame(6000,$amounts['confirmed']); $this->assertSame(9000,$amounts['outstanding']); $this->assertSame(9000,$amounts['available']);
        $this->assertDatabaseCount('payments',1); $this->assertEquals(150,$this->invoice->fresh()->paid_amount);
        $this->get(route('cash-handovers.index'))->assertOk()->assertSee('Received only part');
    }

    public function test_duplicate_handover_and_overbooking_are_rejected_and_cancellation_releases_cash(): void
    {
        $entry=$this->collect(); $data=['submission_token'=>(string)Str::uuid(),'amount'=>100,'confirmed'=>1];
        $this->post(route('cash-handovers.store',$entry),$data)->assertSessionHasNoErrors();
        $this->post(route('cash-handovers.store',$entry),$data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('delivery_cash_handovers',1);
        $this->post(route('cash-handovers.store',$entry),['submission_token'=>(string)Str::uuid(),'amount'=>51,'confirmed'=>1])->assertSessionHasErrors('handover');
        $handover=DeliveryCashHandover::sole();
        $this->post(route('cash-handovers.correct',$handover),['action'=>'cancel','reason'=>'Cash was not handed over','confirmed'=>1])->assertSessionHasNoErrors();
        $this->assertEquals(15000,$entry->fresh()->cashAmounts()['available']);
        $this->assertEquals(100,$handover->fresh()->submitted_amount);
    }

    public function test_receipt_and_collection_corrections_keep_all_original_history(): void
    {
        $entry=$this->collect(); $handover=$this->handover($entry);
        $this->actingAs($this->finance);
        $this->post(route('cash-handovers.confirm',$handover),['confirmation_token'=>(string)Str::uuid(),'received_amount'=>100,'confirmed'=>1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin);
        $correction=['reason'=>'Synthetic wrong collection entry','confirmed'=>1];
        $this->post(route('delivery-collections.reverse',$entry),$correction)->assertSessionHasErrors('collection');
        $this->post(route('cash-handovers.correct',$handover),['action'=>'reverse','reason'=>'Synthetic cash receipt mistake','confirmed'=>1])->assertSessionHasNoErrors();
        $this->post(route('delivery-collections.reverse',$entry),$correction)->assertSessionHasNoErrors();
        $this->post(route('delivery-collections.reverse',$entry),$correction)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments',2); $this->assertDatabaseCount('delivery_cash_handovers',1);
        $this->assertEquals(100,$handover->fresh()->received_amount); $this->assertEquals(150,$entry->fresh()->amount);
        $this->assertEquals(0,$this->invoice->fresh()->paid_amount); $this->assertEquals(300,$this->invoice->fresh()->balance_amount);
        $this->assertDatabaseHas('payments',['reversal_of_payment_id'=>$entry->payment_id,'amount'=>-150]);
    }

    public function test_other_collectors_and_other_organisations_cannot_read_or_submit_for_this_task(): void
    {
        $this->collect();
        $other=TestData::user($this->collector->organization,['role'=>User::ROLE_DELIVERY]);
        $this->actingAs($other)->get(route('delivery-collections.show',$this->task))->assertForbidden();
        $this->get(route('cash-handovers.index'))->assertOk()->assertDontSee('Collection #1');
        $this->actingAs(TestData::user())->get(route('delivery-collections.show',$this->task))->assertNotFound();
    }

    public function test_custom_payment_creation_permission_does_not_grant_company_cash_visibility(): void
    {
        $role=\App\Models\Role::create(['organization_id'=>$this->collector->organization_id,'name'=>'Receipt entry','slug'=>'receipt_entry',
            'is_active'=>true,'permissions'=>['payments'=>['create']]]);
        $writer=TestData::user($this->collector->organization,['role_id'=>$role->id,'role'=>'receipt_entry']);
        $this->actingAs($writer)->get(route('cash-handovers.index'))->assertForbidden();
        $this->get(route('delivery-collections.index'))->assertForbidden();
    }

    public function test_collector_cannot_confirm_own_receipt_even_when_also_an_administrator(): void
    {
        $this->task->update(['assigned_user_id'=>$this->admin->id]);
        $this->actingAs($this->admin); $entry=$this->collect(); $handover=$this->handover($entry);
        $this->post(route('cash-handovers.confirm',$handover),['confirmation_token'=>(string)Str::uuid(),'received_amount'=>100,'confirmed'=>1])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_collection_and_payment_and_preserves_legacy_reports(): void
    {
        $this->task->update(['collection_amount_collected'=>77]);
        ActivityLog::creating(function ($row) { if ($row->action==='delivery.collection_recorded') throw new \RuntimeException('Synthetic audit failure'); });
        try {
            $this->post(route('delivery-collections.store',$this->task),$this->payload())->assertServerError();
            $this->assertDatabaseCount('payments',0); $this->assertDatabaseCount('delivery_collections',0);
            $this->assertEquals(77,$this->task->fresh()->collection_amount_collected);
            $this->assertEquals(0,$this->invoice->fresh()->paid_amount);
        } finally { ActivityLog::flushEventListeners(); }
    }

    public function test_history_blocks_task_invoice_and_standalone_payment_deletion(): void
    {
        $entry=$this->collect(); $this->actingAs($this->admin);
        $this->delete(route('deliveries.destroy',$this->task))->assertSessionHas('error');
        $this->delete(route('rentals.destroy',$this->task->rental_id))->assertSessionHas('error');
        $this->delete(route('invoices.destroy',$this->invoice))->assertSessionHasErrors('invoice');
        $payment=Payment::findOrFail($entry->payment_id);
        $service=app(\App\Services\Finance\PaymentCorrectionService::class);
        try { $service->reverse($payment,'Synthetic correction attempt',$service->version($payment)); $this->fail('Standalone reversal should be blocked.'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('payment',$e->errors()); }
        $this->assertDatabaseCount('payments',1); $this->assertDatabaseHas('deliveries',['id'=>$this->task->id]);
    }

    public function test_collection_history_prevents_changing_the_task_order_or_type(): void
    {
        $this->collect(); $this->actingAs($this->admin);
        $this->put(route('deliveries.update',$this->task),['rental_id'=>$this->task->rental_id,'type'=>'pickup','status'=>'in_progress',
            'assignment_type'=>'delivery_team','assigned_user_id'=>$this->collector->id])->assertSessionHasErrors('rental_id');
        $this->assertSame('delivery',$this->task->fresh()->type);
    }
}
