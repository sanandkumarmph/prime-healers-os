<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerActionMenuRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_actions_keep_their_permissions_and_render_browser_fixtures(): void
    {
        $organization = TestData::organization();
        foreach (range(1, 8) as $number) {
            Customer::create(['organization_id'=>$organization->id, 'name'=>'Synthetic customer '.$number,
                'phone'=>'900000000'.$number, 'whatsapp_number'=>'900000000'.$number,
                'city'=>'Bengaluru', 'state'=>'Karnataka']);
        }
        foreach ([User::ROLE_SUPER_ADMIN, User::ROLE_SALES] as $role) {
            $this->actingAs(TestData::user($organization, ['role'=>$role]));
            $response = $this->get(route('customers.index'))->assertOk();
            $response->assertSee('customer-action-menu')->assertSee('customer-action-panel');
            if ($role === User::ROLE_SALES) $response->assertDontSee('Delete Selected');
            if ($folder = getenv('PHOS_CUSTOMER_MENU_RENDER_DIR')) {
                if (!is_dir($folder)) mkdir($folder,0777,true);
                file_put_contents($folder.'/'.$role.'.html', $response->getContent());
            }
        }
    }
}
