<?php

namespace Tests\Feature\Regression;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationSettingsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_self_heals_when_user_org_id_is_stale_and_only_one_org_exists(): void
    {
        $organization = Organization::create([
            'name' => 'UAT Org',
            'is_internal' => true,
            'is_active' => true,
            'plan' => 'uat',
        ]);

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('users')->whereKey($user->id)->update([
            'organization_id' => 999999,
        ]);
        DB::statement('PRAGMA foreign_keys = ON');

        $response = $this->actingAs($user)->get(route('organization.settings.edit'));

        $response->assertOk();
        $response->assertViewHas('organization', fn (Organization $resolved) => (int) $resolved->id === (int) $organization->id);
        $this->assertSame((int) $organization->id, (int) $user->fresh()->organization_id);
    }
}
