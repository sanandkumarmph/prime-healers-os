<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\InternalOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BootstrapLocalAdmin extends Command
{
    protected $signature = 'phos:bootstrap-local-admin
        {--reset-password : Reset the local admin password to the default password123}';

    protected $description = 'Create the minimum local PHOS organization, roles, and super admin login without resetting data.';

    public function handle(): int
    {
        if (!$this->isLocalEnvironment()) {
            $this->error('phos:bootstrap-local-admin is allowed only when APP_ENV is local.');

            return self::FAILURE;
        }

        $summary = DB::transaction(function (): array {
            $organization = $this->firstOrCreateOrganization();
            [$rolesCreated, $rolesExisting, $superAdminRole] = $this->ensureDefaultRoles($organization);
            [$user, $userWasCreated, $passwordReset] = $this->ensureAdminUser($organization, $superAdminRole);

            return [
                'organization' => $organization,
                'roles_created' => $rolesCreated,
                'roles_existing' => $rolesExisting,
                'user' => $user,
                'user_created' => $userWasCreated,
                'password_reset' => $passwordReset,
            ];
        });

        /** @var \App\Models\Organization $organization */
        $organization = $summary['organization'];
        /** @var \App\Models\User $user */
        $user = $summary['user'];

        $this->info('PHOS local bootstrap completed.');
        $this->newLine();
        $this->table(
            ['Item', 'Result'],
            [
                ['Organization', $organization->name . ' (#' . $organization->id . ')'],
                ['Roles created', (string) $summary['roles_created']],
                ['Roles reused', (string) $summary['roles_existing']],
                ['Admin user', $user->email . ($summary['user_created'] ? ' (created)' : ' (existing)')],
                ['Password', $summary['password_reset'] ? 'reset to password123' : 'left unchanged'],
            ]
        );

        $this->line('Login email: admin@primehealers.com');
        $this->line('Default password: password123');

        return self::SUCCESS;
    }

    private function isLocalEnvironment(): bool
    {
        return Str::lower((string) config('app.env')) === 'local';
    }

    private function firstOrCreateOrganization(): Organization
    {
        $organizationName = InternalOrganization::companyName();

        $organization = Organization::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($organizationName)])
            ->orderByDesc('is_internal')
            ->orderBy('id')
            ->first();

        if ($organization) {
            $organization->forceFill([
                'plan' => $organization->plan ?: 'internal',
                'is_internal' => true,
                'is_active' => true,
            ])->save();

            return $organization;
        }

        return Organization::query()->create([
            'name' => $organizationName,
            'plan' => 'internal',
            'is_internal' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0:int,1:int,2:\App\Models\Role}
     */
    private function ensureDefaultRoles(Organization $organization): array
    {
        $created = 0;
        $existing = 0;
        $superAdminRole = null;

        foreach (Role::defaultSystemRoleTemplates() as $slug => $template) {
            $role = Role::query()->firstOrNew([
                'organization_id' => $organization->id,
                'slug' => $slug,
            ]);

            $wasRecentlyCreated = !$role->exists;

            $role->fill([
                'name' => $template['name'],
                'description' => $template['description'],
                'permissions' => Role::normalizePermissions($template['permissions']),
                'is_system' => true,
                'is_active' => true,
            ]);
            $role->save();

            if ($wasRecentlyCreated) {
                $created++;
            } else {
                $existing++;
            }

            if ($slug === User::ROLE_SUPER_ADMIN) {
                $superAdminRole = $role;
            }
        }

        return [$created, $existing, $superAdminRole ?? throw new \RuntimeException('Super admin role template missing.')];
    }

    /**
     * @return array{0:\App\Models\User,1:bool,2:bool}
     */
    private function ensureAdminUser(Organization $organization, Role $superAdminRole): array
    {
        $user = User::query()->firstOrNew([
            'email' => 'admin@primehealers.com',
        ]);

        $wasCreated = !$user->exists;
        $passwordReset = $wasCreated || $this->option('reset-password');

        $user->forceFill([
            'name' => $user->name ?: 'Prime Healers Admin',
            'organization_id' => $organization->id,
            'role_id' => $superAdminRole->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        if ($passwordReset) {
            $user->password = Hash::make('password123');
        }

        $user->save();

        return [$user, $wasCreated, $passwordReset];
    }
}
