<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, DashboardWidget, Invoice, Product, Role, RoleDashboardWidget, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class PhosPresentationAndGuidesTest extends TestCase
{
    use RefreshDatabase;

    private function saveFixture(string $name, string $content): void
    {
        if ($directory = getenv('GETTING_STARTED_PREVIEW_DIR')) {
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/'.$name, $content);
        }
    }

    public function test_guides_are_permission_aware_and_describe_phos_billing_and_return_verification(): void
    {
        $owner=TestData::user(); $this->actingAs($owner);
        $response=$this->get(route('workflow-guides.index'))->assertOk()
            ->assertSee('Delivery completion does not change this billing policy.')
            ->assertSee('Knowledge Hub')->assertSee('Prepare and share a quotation');
        $this->saveFixture('phos-guides.html',$response->getContent());
        $role=Role::create(['organization_id'=>$owner->organization_id,'slug'=>'guide_reader','name'=>'Reader', 'is_active'=>true,'permissions'=>['customers'=>['read']]]);
        $reader=TestData::user($owner->organization,['role_id'=>$role->id,'role'=>'guide_reader']);
        $this->actingAs($reader)->get(route('workflow-guides.index'))->assertOk()->assertDontSee('Add a customer')->assertDontSee('Create a rental')->assertDontSee('Record and correct a receipt');
    }

    public function test_compact_dashboard_preserves_scoped_figures_and_disabled_widgets(): void
    {
        $owner=TestData::user(); $this->actingAs($owner);
        $redirect=$this->get(route('dashboard.compact'))->assertRedirect(route('dashboard',['dashboard_tab'=>'overview']));
        $response=$this->get($redirect->headers->get('Location'))->assertOk()->assertSee('Executive command centre')->assertSee('Business overview figures');
        $full=$this->get(route('dashboard'))->assertOk();
        foreach (['activeRentals','pendingDeliveryCount','pendingPickupCount','paymentsReceivedThisMonth','outstandingDueAmount'] as $key) {
            $this->assertEquals($full->viewData($key),$response->viewData($key));
        }
        $this->saveFixture('phos-overview.html',$response->getContent());
        $role=Role::create(['organization_id'=>$owner->organization_id,'slug'=>'admin_operations','name'=>'Operations','is_active'=>true,
            'permissions'=>Role::defaultSystemRoleTemplates()['admin_operations']['permissions']]);
        $widget=DashboardWidget::where('widget_key','kpi_active_rentals')->firstOrFail();
        RoleDashboardWidget::create(['organization_id'=>$owner->organization_id,'role_id'=>$role->id,'dashboard_widget_id'=>$widget->id,'is_enabled'=>false,'sort_order'=>1]);
        $staff=TestData::user($owner->organization,['role_id'=>$role->id,'role'=>'admin_operations']);
        $redirect=$this->actingAs($staff)->get(route('dashboard.compact'))->assertRedirect();
        $this->get($redirect->headers->get('Location'))->assertOk()->assertDontSee('data-overview-widget="kpi_active_rentals"',false);
    }

    public function test_delivery_role_compact_dashboard_cannot_render_company_finance(): void
    {
        $operator=TestData::user(null,['role'=>User::ROLE_DELIVERY]);
        $redirect=$this->actingAs($operator)->get(route('dashboard.compact'))->assertRedirect();
        $this->get($redirect->headers->get('Location'))->assertOk()
            ->assertDontSee('Collections this month')->assertDontSee('Outstanding invoices')->assertDontSee('Invoice aging');
    }

    public function test_pdf_download_preserves_invoice_and_permissions_and_renders_branding(): void
    {
        Storage::fake('public');
        $image=imagecreatetruecolor(600,180); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        imagestring($image,5,30,65,'PHOS SYNTHETIC BRAND',imagecolorallocate($image,8,126,131));
        ob_start(); imagepng($image); $png=ob_get_clean(); imagedestroy($image);
        Storage::disk('public')->put('logos/synthetic.png',$png);
        $org=TestData::organization(['logo'=>'logos/synthetic.png','name'=>'PHOS Synthetic Equipment Care','address'=>'Synthetic business address','gst_number'=>'TEST-GST',
            'default_terms'=>'Synthetic document for layout testing only.']);
        $owner=TestData::user($org); $this->actingAs($owner);
        $customer=Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic Invoice Customer','phone'=>'9000000000','address'=>'Synthetic billing address']);
        $product=Product::create(['organization_id'=>$org->id,'name'=>'Hospital bed with accessories','product_type'=>'rentable','price_per_day'=>100,'total_quantity'=>5,'available_quantity'=>5]);
        $invoice=Invoice::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'invoice_number'=>'TEST-PRESENTATION-001',
            'invoice_date'=>today(),'due_date'=>today()->addDays(7),'bill_to_name'=>$customer->name,'bill_to_address'=>$customer->address,
            'status'=>'generated','payment_status'=>'unpaid','subtotal'=>100,'total_amount'=>100,'balance_amount'=>100,'paid_amount'=>0,
            'terms_conditions'=>'Synthetic document for layout testing only.']);
        $invoice->items()->create(['product_id'=>$product->id,'source_type'=>'manual','description'=>$product->name,'quantity'=>1,'unit'=>'rental','rate'=>100,'taxable_amount'=>100,'line_total'=>100]);
        config(['pdf.engine'=>'dompdf']);
        $invoice->syncFinancialStatus();
        $before=$invoice->fresh()->only(['total_amount','paid_amount','balance_amount','status','payment_status']);
        $response=$this->get(route('invoices.download',$invoice))->assertOk()->assertHeader('content-type','application/pdf');
        $this->assertStringContainsString('attachment;',$response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF',$response->getContent());
        $this->assertSame($before,$invoice->fresh()->only(array_keys($before))); $this->assertDatabaseCount('payments',0);
        $this->saveFixture('phos-invoice.pdf',$response->getContent());
        $this->saveFixture('phos-invoice.html',$this->get(route('invoices.show',$invoice))->assertOk()->assertSee('Download PDF')->getContent());
        if (getenv('GETTING_STARTED_PREVIEW_DIR')) {
            foreach (range(2,24) as $i) {
                $invoice->items()->create(['product_id'=>$product->id,'source_type'=>'manual',
                    'description'=>'Synthetic item '.$i.': hospital bed, mattress, side rails and equipment accessories with a longer service description.',
                    'quantity'=>1,'unit'=>'rental','rate'=>100,'taxable_amount'=>100,'line_total'=>100]);
            }
            $invoice->update(['subtotal'=>2400,'total_amount'=>2400,'balance_amount'=>2400]);
            $long=$this->get(route('invoices.download',$invoice))->assertOk()->assertHeader('content-type','application/pdf');
            $this->saveFixture('phos-invoice-long.pdf',$long->getContent());
        }
        $role=Role::create(['organization_id'=>$org->id,'slug'=>'invoice_reader','name'=>'Invoice reader','is_active'=>true,'permissions'=>['invoices'=>['read']]]);
        $reader=TestData::user($org,['role_id'=>$role->id,'role'=>'invoice_reader']);
        $this->actingAs($reader)->get(route('invoices.download',$invoice))->assertRedirect($reader->defaultRedirectPath())
            ->assertSessionHas('error','You are not authorized to access this section.');
        $this->getJson(route('invoices.download',$invoice))->assertForbidden();
        $this->actingAs(TestData::user())->get(route('invoices.download',$invoice))->assertNotFound();
    }
}
