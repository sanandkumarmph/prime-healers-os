<?php

use App\Models\{Customer, Delivery, DeliveryCashHandover, DeliveryCollection, Enquiry, Invoice, Payment, Product, Quotation, Rental, Sale, User, Warehouse};
use App\Services\Deliveries\{DeliveryCashHandoverService, DeliveryCollectionService};
use App\Services\Sales\{QuotationConversion, SalesWorkspace};
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestData;

require dirname(__DIR__).'/Support/DisposableMariaDb.php';
if ($mode==='init') {
    $exit=$console->call('migrate',['--force'=>true]);
    if ($exit) throw new RuntimeException($console->output());
    echo json_encode(['initialized'=>true,'version'=>DB::selectOne('SELECT VERSION() AS version')->version]),PHP_EOL; exit;
}
if ($mode==='fixture') {
    $org=TestData::organization(['name'=>'Synthetic concurrency '.bin2hex(random_bytes(4))]);
    $collector=TestData::user($org,['role'=>User::ROLE_DELIVERY]); $finance=TestData::user($org,['role'=>User::ROLE_FINANCE]); $admin=TestData::user($org);
    auth()->guard('web')->setUser($admin);
    $customer=Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic concurrency contact','phone'=>'9000000000']);
    $product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic unit','product_type'=>'both','stock_mode'=>'untracked',
        'total_quantity'=>20,'available_quantity'=>20,'price_per_day'=>100,'rental_price'=>100,'sale_price'=>100]);
    Warehouse::create(['organization_id'=>$org->id,'name'=>'Synthetic warehouse','is_active'=>true]);
    $rental=Rental::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'product_id'=>$product->id,'customer_name'=>$customer->name,'phone'=>$customer->phone,
        'quantity'=>3,'start_date'=>today(),'end_date'=>today()->addDays(29),'rental_amount'=>300,'status'=>'active']);
    app(\App\Services\Deliveries\DeliveryWorkflowService::class)->ensureRentalItemsExist($org->id,$rental);
    $invoice=Invoice::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'rental_id'=>$rental->id,'invoice_number'=>'TEST-CONC-'.bin2hex(random_bytes(5)),
        'invoice_date'=>today(),'due_date'=>today()->addDay(),'status'=>'unpaid','payment_status'=>'unpaid','subtotal'=>300,'total_amount'=>300,'paid_amount'=>0,'balance_amount'=>300]);
    $task=Delivery::create(['organization_id'=>$org->id,'rental_id'=>$rental->id,'type'=>'delivery','status'=>'in_progress','assigned_user_id'=>$collector->id,
        'assignment_type'=>'delivery_team','collection_required'=>true,'collection_amount_to_collect'=>250]);
    $entry=null; $handover=null;
    if (!empty($input['cash']) || !empty($input['online']) || !empty($input['handover'])) {
        $entry=app(DeliveryCollectionService::class)->record($collector,$task->id,['submission_token'=>(string)Str::uuid(),
            'version'=>app(DeliveryCollectionService::class)->version($task,$invoice),'amount'=>150,'method'=>!empty($input['online'])?'upi':'cash',
            'reference'=>!empty($input['online'])?'SYNTHETIC-TRANSFER':'','confirmed'=>1]);
        if (!empty($input['handover'])) $handover=app(DeliveryCashHandoverService::class)->submit($collector,$entry->id,['submission_token'=>(string)Str::uuid(),'amount'=>100,'confirmed'=>1]);
    }
    $quote=null;
    if (!empty($input['quotation'])) {
        $enquiry=Enquiry::create(['organization_id'=>$org->id,'owner_id'=>$admin->id,'created_by_user_id'=>$admin->id,'customer_id'=>$customer->id,
            'name'=>$customer->name,'subject'=>'Synthetic accepted offer','stage'=>'accepted']);
        $quote=Quotation::create(['organization_id'=>$org->id,'enquiry_id'=>$enquiry->id,'revision'=>1,'kind'=>'sale','status'=>'accepted','valid_until'=>today()->addDays(7),
            'snapshot'=>['region'=>app(SalesWorkspace::class)->region($org),'customer_id'=>$customer->id,'recipient'=>['name'=>$customer->name,'phone'=>$customer->phone,'address'=>''],
                'timezone'=>'Asia/Kolkata','tax_type'=>'cgst_sgst','currency'=>'INR','transport'=>0,'deposit'=>0,'terms'=>'Synthetic agreed terms',
                'lines'=>[['product_id'=>$product->id,'quantity'=>1,'rate'=>100,'tax_rate'=>0,'tax_mode'=>'inclusive']]]]);
    }
    $invoice->refresh();
    $quote?->refresh();
    echo json_encode(['organization'=>$org->id,'collector'=>$collector->id,'finance'=>$finance->id,'admin'=>$admin->id,'task'=>$task->id,'invoice'=>$invoice->id,
        'rental'=>$rental->id,'item'=>$rental->rentalItems()->sole()->id,'product'=>$product->id,'collection'=>$entry?->id,'handover'=>$handover?->id,
        'quote'=>$quote?->id,'quotePayload'=>$quote?app(QuotationConversion::class)->prefill($quote):null,
        'version'=>app(DeliveryCollectionService::class)->version($task,$invoice)]),PHP_EOL; exit;
}
if ($mode==='summary') {
    $invoice=Invoice::findOrFail($input['invoice']);
    $entry=isset($input['collection']) ? DeliveryCollection::find($input['collection']) : DeliveryCollection::where('delivery_id',$input['task'])->first();
    echo json_encode(['payments'=>Payment::where('invoice_id',$invoice->id)->count(),'paid'=>(float)Payment::where('invoice_id',$invoice->id)->sum('amount'),
        'balance'=>(float)$invoice->balance_amount,'collections'=>DeliveryCollection::where('delivery_id',$input['task'])->count(),
        'handovers'=>$entry?$entry->handovers()->count():0,'cash'=>$entry?$entry->cashAmounts():null,
        'delivered'=>(int)DB::table('rental_items')->where('rental_id',$input['rental'])->sum('delivered_quantity'),
        'submissions'=>DB::table('delivery_workflow_submissions')->where('delivery_id',$input['task'])->count(),
        'sales'=>Sale::where('organization_id',$input['organization'])->count(),'stock'=>(int)Product::findOrFail($input['product'])->available_quantity,
        'quoteStatus'=>!empty($input['quote'])?Quotation::findOrFail($input['quote'])->status:null]),PHP_EOL; exit;
}
if ($mode!=='request') throw new RuntimeException('Unknown worker operation.');
class PhosCommonBarrier
{
    public function handle($request,$next)
    {
        $input=$GLOBALS['phosCommonInput']; file_put_contents($input['entered'],'entered');
        if (!empty($input['release'])) {
            $deadline=microtime(true)+25;
            while (!is_file($input['release'])) { if(microtime(true)>$deadline) throw new RuntimeException('Test barrier timed out.'); usleep(20000); }
        }
        $response=$next($request);
        if (!empty($input['failAfterSave'])) throw \Illuminate\Validation\ValidationException::withMessages(['synthetic'=>'Injected rollback after workflow save.']);
        return $response;
    }
}
$GLOBALS['phosCommonInput']=$input;
$op=$input['operation'];
[$route,$url,$method,$actor,$payload]=match($op) {
    'collection'=>['delivery-collections.store','/deliveries/'.$input['task'].'/collections','POST',$input['collector'],
        ['submission_token'=>$input['token'],'version'=>$input['version'],'amount'=>$input['amount']??150,'method'=>'cash','confirmed'=>1]],
    'handover'=>['cash-handovers.store','/cash-handovers/'.$input['collection'],'POST',$input['collector'],['submission_token'=>$input['token'],'amount'=>$input['amount']??100,'confirmed'=>1]],
    'confirm'=>['cash-handovers.confirm','/cash-handovers/'.$input['handover'].'/confirm','POST',$input['finance'],['confirmation_token'=>$input['token'],'received_amount'=>$input['amount']??60,'reason'=>'Synthetic partial cash receipt','confirmed'=>1]],
    'review'=>['delivery-collections.review','/collections/'.$input['collection'].'/review','POST',$input['finance'],['action'=>'verify','reason'=>'Synthetic verification against bank receipt','confirmed'=>1]],
    'reverse'=>['delivery-collections.reverse','/collections/'.$input['collection'].'/reverse','POST',$input['admin'],['reason'=>'Synthetic incorrect collection','confirmed'=>1]],
    'partial'=>['deliveries.partial_delivery','/deliveries/'.$input['task'].'/partial-delivery','PUT',$input['collector'],['rental_item_id'=>$input['item'],'quantity'=>$input['amount']??1,'workflow_submission_token'=>$input['token']]],
    'quotation'=>['sales.store','/sales','POST',$input['admin'],$input['quotePayload']],
    'payment'=>['invoices.payments.store','/invoices/'.$input['invoice'].'/payments','POST',$input['admin'],['payment_submission_token'=>$input['token'],'payment_date'=>date('Y-m-d'),'amount'=>$input['amount']??200,'payment_method'=>'cash']],
    default=>throw new RuntimeException('Unknown test operation.'),
};
$app['router']->getRoutes()->getByName($route)->middleware(PhosCommonBarrier::class);
auth()->guard('web')->setUser(User::findOrFail($actor));
$request=Request::create('http://127.0.0.1'.$url,$method,$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
file_put_contents($input['started'],'started');
$http=$app->make(HttpKernel::class); $response=$http->handle($request); $http->terminate($request,$response);
echo json_encode(['status'=>$response->getStatusCode(),'body'=>$response->getStatusCode()>=400?$response->getContent():null]),PHP_EOL;
