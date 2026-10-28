<?php

use App\Models\{Customer, Delivery, DeliveryCollection, DeliveryProof, Enquiry, Invoice, Payment, Product, Rental, Role};
use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\TestData;

require dirname(__DIR__).'/Support/DisposableMariaDb.php';
$newMigrations=['2026_10_24_120000_create_sales_workspace','2026_10_25_120000_add_guided_sales_fields',
    '2026_10_28_190000_create_phos_collection_and_cash_history','2026_10_28_190100_create_delivery_workflow_submissions'];
$newTables=['enquiries','quotations','enquiry_follow_ups','enquiry_activities','delivery_collections','delivery_cash_handovers','delivery_workflow_submissions'];
$stateFile=dirname($argv[1]).'/migration-state.json';
function legacySnapshot(array $exclude): array
{
    $snapshot=[];
    $tables=DB::select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\'');
    foreach ($tables as $record) {
        $table=$record->name;
        if (in_array($table,array_merge(['migrations'],$exclude),true)) continue;
        $rows=DB::table($table)->get()->map(function($row)use($table){
            $values=(array)$row;
            if ($table==='roles' && $row->is_system && in_array($row->slug,['super_admin','admin_operations','sales'],true)) {
                $permissions=json_decode($row->permissions,true); unset($permissions['enquiries'],$permissions['quotations']);
                $values['permissions']=json_encode($permissions);
            }
            ksort($values);return json_encode($values,JSON_THROW_ON_ERROR);
        })->all(); sort($rows);
        $snapshot[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }
    ksort($snapshot);return $snapshot;
}
if ($mode==='baseline') {
    $paths=array_values(array_filter(glob($root.'/database/migrations/*.php'),fn($file)=>!in_array(basename($file,'.php'),$newMigrations,true)));
    $status=$console->call('migrate',['--force'=>true,'--realpath'=>true,'--path'=>$paths]);
    if ($status) throw new RuntimeException($console->output());
    $org=TestData::organization(); $owner=TestData::user($org);auth()->guard('web')->setUser($owner);
    Role::create(['organization_id'=>$org->id,'slug'=>'sales','name'=>'Existing system sales','is_system'=>true,'is_active'=>true,'permissions'=>['customers'=>['read']]]);
    Role::create(['organization_id'=>$org->id,'slug'=>'custom_sales','name'=>'Existing custom role','is_system'=>false,'is_active'=>true,'permissions'=>['customers'=>['read'],'payments'=>['create']]]);
    $customer=Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic retained customer','phone'=>'9000000000']);
    $product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic retained unit','product_type'=>'rentable','price_per_day'=>100,'available_quantity'=>4,'total_quantity'=>5]);
    $rental=Rental::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'product_id'=>$product->id,'customer_name'=>$customer->name,'phone'=>$customer->phone,
        'quantity'=>1,'start_date'=>today(),'end_date'=>today()->addDays(29),'rental_amount'=>1000,'status'=>'active']);
    $invoice=Invoice::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'rental_id'=>$rental->id,'invoice_number'=>'TEST-MIGRATION-RETAIN',
        'invoice_date'=>today(),'due_date'=>today()->addDay(),'subtotal'=>1000,'total_amount'=>1000,'paid_amount'=>0,'balance_amount'=>1000,'status'=>'unpaid','payment_status'=>'unpaid']);
    Payment::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'rental_id'=>$rental->id,'invoice_id'=>$invoice->id,'payment_date'=>today(),'amount'=>100,'payment_method'=>'cash']);
    $invoice->syncFinancialStatus();
    $task=Delivery::create(['organization_id'=>$org->id,'rental_id'=>$rental->id,'type'=>'delivery','status'=>'completed','completed_at'=>now(),'assigned_user_id'=>$owner->id]);
    DeliveryProof::create(['organization_id'=>$org->id,'delivery_id'=>$task->id,'rental_id'=>$rental->id,'workflow_stage'=>'delivery','capture_moment'=>'complete',
        'proof_type'=>'location','latitude'=>12.34,'longitude'=>77.12,'captured_at'=>now(),'created_by_user_id'=>$owner->id,'meta'=>['sentinel'=>'retain this historical evidence']]);
    $state=['snapshot'=>legacySnapshot($newTables),'organization'=>$org->id,'owner'=>$owner->id,'task'=>$task->id,'invoice'=>$invoice->id];
    file_put_contents($stateFile,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['baseline_created'=>true,'legacy_tables'=>count($state['snapshot'])]),PHP_EOL;exit;
}
if ($mode!=='upgrade') throw new RuntimeException('Use baseline or upgrade mode.');
$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);
$currentSnapshot=legacySnapshot($newTables);
if ($currentSnapshot!==$state['snapshot']) throw new RuntimeException('Baseline changed before migration: '.implode(', ',array_keys(array_filter($currentSnapshot,fn($value,$key)=>$value!==($state['snapshot'][$key]??null),ARRAY_FILTER_USE_BOTH))));
$applied=DB::table('migrations')->pluck('migration')->all();
$pending=array_values(array_filter(glob($root.'/database/migrations/*.php'),fn($file)=>!in_array(basename($file,'.php'),$applied,true)));
$names=array_map(fn($file)=>basename($file,'.php'),$pending);sort($names);$expected=$newMigrations;sort($expected);
if ($names!==$expected) throw new RuntimeException('Unexpected pending migration set.');
$status=$console->call('migrate',['--force'=>true]);if($status)throw new RuntimeException($console->output());
$currentSnapshot=legacySnapshot($newTables);
if ($currentSnapshot!==$state['snapshot']) throw new RuntimeException('An existing business record changed during additive migrations: '.implode(', ',array_keys(array_filter($currentSnapshot,fn($value,$key)=>$value!==($state['snapshot'][$key]??null),ARRAY_FILTER_USE_BOTH))));
if (!Schema::hasColumn('delivery_proofs','proof_type') || Schema::hasColumn('delivery_proofs','quantities')) throw new RuntimeException('Legacy PHOS proof shape was replaced.');
$permissions=json_decode(DB::table('roles')->where('slug','sales')->value('permissions'),true);
if (($permissions['enquiries']??[])!==['read','create','update'] || ($permissions['customers']??[])!==['read']) throw new RuntimeException('System role grant adaptation failed.');
$enquiry=Enquiry::create(['organization_id'=>$state['organization'],'owner_id'=>$state['owner'],'created_by_user_id'=>$state['owner'],
    'name'=>'Synthetic post-upgrade enquiry','subject'=>'Retained new history','intent'=>'rental']);
DeliveryCollection::create(['organization_id'=>$state['organization'],'delivery_id'=>$state['task'],'invoice_id'=>$state['invoice'],'collector_user_id'=>$state['owner'],
    'submission_token'=>(string)\Illuminate\Support\Str::uuid(),'request_hash'=>str_repeat('a',64),'amount'=>10,'method'=>'upi','status'=>'pending_review','reference'=>'SYNTHETIC-MIGRATION','received_at'=>now()]);
DB::table('delivery_workflow_submissions')->insert(['organization_id'=>$state['organization'],'delivery_id'=>$state['task'],'user_id'=>$state['owner'],
    'submission_token'=>(string)\Illuminate\Support\Str::uuid(),'action'=>'markCompleted','request_hash'=>str_repeat('b',64),'response_status'=>302,'redirect_url'=>'http://127.0.0.1','created_at'=>now(),'updated_at'=>now()]);
$blocked=[];
foreach($newMigrations as $migration){
    try{(require $root.'/database/migrations/'.$migration.'.php')->down();throw new RuntimeException('Rollback unexpectedly discarded used history: '.$migration);}
    catch(RuntimeException $error){if(str_starts_with($error->getMessage(),'Rollback unexpectedly'))throw $error;$blocked[]=$migration;}
}
if(legacySnapshot($newTables)!==$state['snapshot'])throw new RuntimeException('Rollback protection altered existing business data.');
$result=['version'=>DB::selectOne('SELECT VERSION() AS version')->version,'legacy_tables_preserved'=>count($state['snapshot']),
    'original_proof_shape_preserved'=>true,'custom_permissions_preserved'=>true,'new_tables'=>count($newTables),'used_history_rollback_blocked'=>$blocked,
    'legacy_snapshot'=>$state['snapshot']];
file_put_contents(dirname($argv[1]).'/migration-results.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
