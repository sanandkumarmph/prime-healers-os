<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class InternalSingleOrgModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled_in_internal_single_org_mode(): void
    {
        $this->assertTrue(config('primehealers.internal_single_org_mode'));
        $this->assertFalse(config('auth.allow_public_registration'));
        $this->assertFalse(Route::has('register'));
    }

    public function test_orgless_user_is_assigned_prime_healers_organization_on_authenticated_request(): void
    {
        $organization = Organization::create([
            'name' => 'Prime Healers',
            'is_internal' => true,
            'is_active' => true,
        ]);

        DB::table('users')->insert([
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::where('email', 'legacy@example.com')->firstOrFail();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk();

        $this->assertSame($organization->id, $user->fresh()->organization_id);
    }

    public function test_new_org_scoped_records_default_to_prime_healers_organization(): void
    {
        $organization = Organization::create([
            'name' => 'Prime Healers',
            'is_internal' => true,
            'is_active' => true,
        ]);

        $city = City::create([
            'name' => 'Kolkata',
            'state' => 'West Bengal',
            'country' => 'India',
            'is_active' => true,
        ]);

        $this->assertSame($organization->id, $city->organization_id);
    }
}
