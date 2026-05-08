<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const MODULES = [
        'users' => 'Users Management',
        'roles' => 'Roles & Permissions',
        'cities' => 'Cities Management',
        'warehouses' => 'Warehouses',
        'vendors' => 'Vendors',
        'customers' => 'Customers',
        'products' => 'Products',
        'assets' => 'Assets',
        'rentals' => 'Rentals',
        'sales' => 'Sales',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'deliveries' => 'Deliveries',
        'reports' => 'Reports',
        'settings' => 'Settings',
    ];

    public const ACTIONS = ['read', 'create', 'update', 'delete'];
    public const SPECIAL_PERMISSIONS = [
        'customers.proof.download' => 'Download customer ID proofs',
        'customers.export' => 'Export customer data',
        'invoices.export' => 'Export invoices',
        'invoices.print' => 'Print invoices',
        'payments.export' => 'Export payments',
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'permissions',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function moduleOptions(): array
    {
        return self::MODULES;
    }

    public static function actionOptions(): array
    {
        return self::ACTIONS;
    }

    public static function specialPermissionOptions(): array
    {
        return self::SPECIAL_PERMISSIONS;
    }

    public static function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach (self::MODULES as $module => $label) {
            $actions = collect($permissions[$module] ?? [])
                ->map(fn ($action) => strtolower((string) $action))
                ->filter(fn ($action) => in_array($action, self::ACTIONS, true))
                ->values()
                ->all();

            if ($actions !== []) {
                $normalized[$module] = $actions;
            }
        }

        $specialPermissions = collect($permissions['__special'] ?? [])
            ->map(fn ($permission) => strtolower((string) $permission))
            ->filter(fn ($permission) => array_key_exists($permission, self::SPECIAL_PERMISSIONS))
            ->values()
            ->all();

        if ($specialPermissions !== []) {
            $normalized['__special'] = $specialPermissions;
        }

        return $normalized;
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function allows(string $module, string $action = 'read'): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $permissions = $this->permissions ?? [];
        $actions = $permissions[$module] ?? [];

        return in_array($action, $actions, true);
    }

    public function allowsSpecialPermission(string $permission): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $permissions = $this->permissions ?? [];
        $specialPermissions = $permissions['__special'] ?? [];

        return in_array(strtolower(trim($permission)), $specialPermissions, true);
    }
}
