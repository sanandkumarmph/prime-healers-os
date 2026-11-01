<?php

namespace Tests\Feature\Regression;

use App\Models\{Delivery, DeliveryProof, Product, Rental, User};
use App\Services\Deliveries\SignatureCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class PhosDeliverySubmissionTest extends TestCase
{
    use RefreshDatabase;

    private $operator;
    private $task;

    protected function setUp(): void
    {
        parent::setUp(); Storage::fake('local'); config(['proof.storage_disk'=>'local']);
        $this->operator=TestData::user(null,['role'=>User::ROLE_DELIVERY]);
        $product=Product::create(['organization_id'=>$this->operator->organization_id,'name'=>'Synthetic proof unit','product_type'=>'rentable',
            'stock_mode'=>'untracked','total_quantity'=>5,'available_quantity'=>4,'price_per_day'=>10]);
        $rental=Rental::create(['organization_id'=>$this->operator->organization_id,'product_id'=>$product->id,'customer_name'=>'Synthetic delivery customer',
            'phone'=>'9000000000','quantity'=>1,'start_date'=>today(),'end_date'=>today()->addDays(29),'rental_amount'=>100,'status'=>'active']);
        $this->task=Delivery::create(['organization_id'=>$this->operator->organization_id,'rental_id'=>$rental->id,'type'=>'delivery','status'=>'pending',
            'assigned_user_id'=>$this->operator->id,'assignment_type'=>'delivery_team','scheduled_at'=>now()]);
        $this->actingAs($this->operator);
    }

    private function location(): array
    {
        return ['workflow_capture_form'=>1,'location_latitude'=>'12.971599','location_longitude'=>'77.594566','location_accuracy'=>'5',
            'location_captured_at'=>now()->toIso8601String(),'workflow_submission_token'=>(string)Str::uuid()];
    }

    private function signature(bool $blank=false, int $scale=1): string
    {
        $image=imagecreatetruecolor(240*$scale,80*$scale); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        if (!$blank) { $ink=imagecolorallocate($image,20,30,40); imagesetthickness($image,max(1,$scale)); imageline($image,20*$scale,55*$scale,95*$scale,20*$scale,$ink); imageline($image,95*$scale,20*$scale,130*$scale,60*$scale,$ink); imageline($image,130*$scale,60*$scale,215*$scale,25*$scale,$ink); }
        ob_start(); imagepng($image); $bytes=ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_start_retry_saves_one_proof_and_rejects_changed_replay(): void
    {
        $data=$this->location();
        $this->put(route('deliveries.in_progress',$this->task),$data)->assertSessionHasNoErrors();
        $this->put(route('deliveries.in_progress',$this->task),$data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('delivery_workflow_submissions',1); $this->assertDatabaseCount('delivery_proofs',1);
        $data['location_accuracy']='99';
        $this->put(route('deliveries.in_progress',$this->task),$data)->assertSessionHasErrors('workflow_submission_token');
        $this->assertDatabaseCount('delivery_proofs',1);
    }

    public function test_partial_quantity_retry_does_not_apply_stock_progress_twice(): void
    {
        $this->put(route('deliveries.in_progress',$this->task),$this->location())->assertSessionHasNoErrors();
        $rental=$this->task->rental;
        app(\App\Services\Deliveries\DeliveryWorkflowService::class)->ensureRentalItemsExist($this->operator->organization_id,$rental);
        $item=$rental->rentalItems()->sole();
        $data=['rental_item_id'=>$item->id,'quantity'=>1,'workflow_submission_token'=>(string)Str::uuid()];
        $this->put(route('deliveries.partial_delivery',$this->task),$data)->assertSessionHasNoErrors();
        $this->put(route('deliveries.partial_delivery',$this->task),$data)->assertSessionHasNoErrors();
        $this->assertSame(1,$item->fresh()->delivered_quantity_value);
        $this->assertDatabaseCount('delivery_workflow_submissions',2);
    }

    public function test_invalid_signature_rolls_back_task_proofs_and_new_private_files(): void
    {
        $this->task->update(['status'=>'in_progress']);
        $data=$this->location()+['confirm_partial'=>1,'completion_confirmed'=>1,
            'delivery_device_photos'=>[UploadedFile::fake()->image('device.jpg',60,40)],
            'premises_photo'=>UploadedFile::fake()->image('location.jpg',60,40),'signature_signer_name'=>'Synthetic signer','signature_data'=>$this->signature(true)];
        $this->put(route('deliveries.complete',$this->task),$data)->assertSessionHasErrors('signature_data');
        $this->assertSame('in_progress',$this->task->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs',0); $this->assertDatabaseCount('delivery_workflow_submissions',0);
        $this->assertSame([],Storage::disk('local')->allFiles());
    }

    public function test_completion_retry_preserves_one_evidence_batch_and_original_table_shape(): void
    {
        $this->task->update(['status'=>'in_progress']);
        $token=(string)Str::uuid(); $location=$this->location(); $location['workflow_submission_token']=$token;
        $device=UploadedFile::fake()->image('device.jpg',60,40)->getContent();
        $premises=UploadedFile::fake()->image('location.jpg',60,40)->getContent();
        $payload=fn()=> $location+['confirm_partial'=>1,'completion_confirmed'=>1,
            'delivery_device_photos'=>[UploadedFile::fake()->createWithContent('device.jpg',$device)],
            'premises_photo'=>UploadedFile::fake()->createWithContent('location.jpg',$premises),'signature_signer_name'=>'Synthetic signer','signature_data'=>$this->signature()];
        $this->put(route('deliveries.complete',$this->task),$payload())->assertSessionHasNoErrors();
        $count=DeliveryProof::count(); $files=Storage::disk('local')->allFiles();
        $this->put(route('deliveries.complete',$this->task),$payload())->assertSessionHasNoErrors();
        $this->assertSame($count,DeliveryProof::count()); $this->assertSame($files,Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('delivery_workflow_submissions',1);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('delivery_proofs','proof_type'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('delivery_proofs','quantities'));
    }

    public function test_server_rejects_more_than_three_photos_without_saving_partial_evidence(): void
    {
        $this->task->update(['status'=>'in_progress']);
        $data=$this->location()+['confirm_partial'=>1,'completion_confirmed'=>1,
            'delivery_device_photos'=>array_map(fn($i)=>UploadedFile::fake()->image('device-'.$i.'.jpg',60,40),range(1,4)),
            'premises_photo'=>UploadedFile::fake()->image('location.jpg',60,40),'signature_signer_name'=>'Synthetic signer','signature_data'=>$this->signature()];
        $this->put(route('deliveries.complete',$this->task),$data)->assertSessionHasErrors('delivery_device_photos');
        $this->assertSame('in_progress',$this->task->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs',0); $this->assertSame([],Storage::disk('local')->allFiles());
    }

    public function test_parent_rental_edits_and_deletion_preserve_saved_task_history(): void
    {
        $this->put(route('deliveries.in_progress',$this->task),$this->location())->assertSessionHasNoErrors();
        $this->actingAs(TestData::user($this->operator->organization));
        $rental=$this->task->rental;
        $customer=\App\Models\Customer::create(['organization_id'=>$rental->organization_id,'name'=>$rental->customer_name,'phone'=>$rental->phone]);
        $rental->update(['customer_id'=>$customer->id]);
        $vendor=\App\Models\Vendor::create(['organization_id'=>$rental->organization_id,'name'=>'Synthetic fulfilment vendor','phone'=>'9000000001','is_active'=>true]);
        $this->put(route('rentals.update',$rental),['customer_type'=>'direct_customer','customer_id'=>$customer->id,
            'customer_name'=>$customer->name,'phone'=>$customer->phone,'product_id'=>$rental->product_id,'quantity'=>1,
            'start_date'=>$rental->start_date->toDateString(),'end_date'=>$rental->end_date->toDateString(),
            'rental_amount'=>100,'fulfilment_source'=>'vendor_supplied','vendor_id'=>$vendor->id,'delivery_assignment_type'=>'vendor',
            'delivery_responsibility'=>'vendor_delivery'])->assertSessionHasErrors('delivery_responsibility');
        $this->assertSame('in_progress',$this->task->fresh()->status); $this->assertDatabaseCount('delivery_proofs',1);
        $this->task->update(['status'=>'cancelled']);
        $this->delete(route('rentals.destroy',$rental))->assertSessionHas('error');
        $this->assertDatabaseHas('rentals',['id'=>$rental->id]); $this->assertDatabaseCount('delivery_workflow_submissions',1);
    }

    public function test_high_dpi_signatures_are_normalized_and_can_complete_a_delivery(): void
    {
        foreach ([6,12] as $scale) {
            $clean=SignatureCapture::clean($this->signature(false,$scale),51200);
            $size=getimagesizefromstring($clean);
            $this->assertSame(1200,$size[0]); $this->assertSame(400,$size[1]);
            $this->assertLessThanOrEqual(51200,strlen($clean));
        }
        $this->task->update(['status'=>'in_progress']);
        $data=$this->location()+['confirm_partial'=>1,'completion_confirmed'=>1,
            'delivery_device_photos'=>[UploadedFile::fake()->image('device.jpg',60,40)],
            'premises_photo'=>UploadedFile::fake()->image('location.jpg',60,40),'signature_signer_name'=>'Synthetic signer','signature_data'=>$this->signature(false,6)];
        $this->put(route('deliveries.complete',$this->task),$data)->assertSessionHasNoErrors();
        $this->assertSame('completed',$this->task->fresh()->status);
        $proof=DeliveryProof::where('proof_type',DeliveryProof::TYPE_SIGNATURE)->sole();
        $size=getimagesizefromstring(Storage::disk('local')->get($proof->file_path));
        $this->assertSame(1200,$size[0]); $this->assertSame(400,$size[1]);
    }

    public function test_high_dpi_blank_signatures_are_still_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        SignatureCapture::clean($this->signature(true,6),51200);
    }

    public function test_signature_decoder_rejects_malformed_and_blank_images_and_cleans_valid_pixels(): void
    {
        foreach (['data:image/png;base64,'.base64_encode('not an image'),$this->signature(true)] as $bad) {
            try { SignatureCapture::clean($bad,51200); $this->fail('Invalid signature accepted.'); }
            catch (\Illuminate\Validation\ValidationException $e) { $this->assertArrayHasKey('signature_data',$e->errors()); }
        }
        $clean=SignatureCapture::clean($this->signature(),51200);
        $this->assertSame(IMAGETYPE_PNG,getimagesizefromstring($clean)[2]);
    }

    public function test_delivery_form_exposes_retry_token_and_retained_photo_controls_without_changing_billing_dates(): void
    {
        $this->task->update(['status'=>'in_progress']); $dates=$this->task->rental->only(['start_date','end_date']);
        $response=$this->get(route('deliveries.show',$this->task))->assertOk()->assertSee('workflow_submission_token',false)->assertSee('retainedPhotoFiles',false);
        if ($directory=getenv('GETTING_STARTED_PREVIEW_DIR')) file_put_contents($directory.'/phos-delivery.html',$response->getContent());
        $this->assertEquals($dates,$this->task->rental->fresh()->only(['start_date','end_date']));
    }
}
