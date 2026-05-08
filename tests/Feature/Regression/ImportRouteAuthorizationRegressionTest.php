<?php

namespace Tests\Feature\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class ImportRouteAuthorizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_authenticated_user_gets_403_on_import_routes(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization, [
            'role' => 'staff',
        ]);

        $this->actingAs($user)
            ->get(route('imports.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('imports.templates.customers'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('imports.sales.upload'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('imports.opening-balances.upload'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('imports.module', 'customers'))
            ->assertForbidden();
    }

    public function test_superadmin_can_access_import_routes(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);

        $this->actingAs($user)
            ->get(route('imports.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('imports.templates.customers'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('imports.sales.upload'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('imports.opening-balances.upload'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('imports.module', 'customers'))
            ->assertOk();
    }

    public function test_unauthenticated_direct_url_access_is_blocked(): void
    {
        $this->get(route('imports.index'))
            ->assertRedirect(route('login'));

        $this->get(route('imports.templates.customers'))
            ->assertRedirect(route('login'));

        $this->get(route('imports.sales.upload'))
            ->assertRedirect(route('login'));

        $this->get(route('imports.module', 'customers'))
            ->assertRedirect(route('login'));
    }
}
