<?php

namespace Tests\Feature\Regression;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalBootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = (string) config('app.env');
    }

    protected function tearDown(): void
    {
        config(['app.env' => $this->originalEnv]);

        parent::tearDown();
    }

    public function test_command_refuses_outside_local_environment(): void
    {
        config(['app.env' => 'testing']);

        $this->artisan('phos:bootstrap-local-admin')
            ->expectsOutput('phos:bootstrap-local-admin is allowed only when APP_ENV is local.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_creates_minimum_local_org_roles_and_admin_user(): void
    {
        config(['app.env' => 'local']);

        $this->artisan('phos:bootstrap-local-admin')
            ->expectsOutput('PHOS local bootstrap completed.')
            ->assertExitCode(0);

        $organization = Organization::query()->where('name', 'Prime Healers')->first();
        $this->assertNotNull($organization);
        $this->assertTrue((bool) $organization->is_internal);

        $this->assertDatabaseHas('roles', [
            'organization_id' => $organization->id,
            'slug' => User::ROLE_SUPER_ADMIN,
            'name' => 'Super Admin',
        ]);

        $this->assertSame(
            count(Role::defaultSystemRoleTemplates()),
            Role::query()->where('organization_id', $organization->id)->count()
        );

        $user = User::query()->where('email', 'admin@primehealers.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($organization->id, $user->organization_id);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertNotNull($user->role_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_repeated_run_is_idempotent_and_does_not_overwrite_password_without_option(): void
    {
        config(['app.env' => 'local']);

        $this->artisan('phos:bootstrap-local-admin')->assertExitCode(0);

        $user = User::query()->where('email', 'admin@primehealers.com')->firstOrFail();
        $user->password = Hash::make('custom-secret');
        $user->save();

        $organizationCount = Organization::query()->count();
        $roleCount = Role::query()->count();
        $userCount = User::query()->count();

        $this->artisan('phos:bootstrap-local-admin')
            ->expectsOutput('PHOS local bootstrap completed.')
            ->assertExitCode(0);

        $this->assertSame($organizationCount, Organization::query()->count());
        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($userCount, User::query()->count());

        $user->refresh();
        $this->assertTrue(Hash::check('custom-secret', $user->password));
    }
}
