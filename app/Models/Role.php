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
        'vendors.export' => 'Export vendor master',
        'vendor_costs.view' => 'View vendor procurement and fulfilment costs',
        'vendor_costs.update' => 'Update vendor procurement and fulfilment costs',
        'vendor_reports.view' => 'View vendor fulfilment analytics',
        'vendor_reports.export' => 'Export vendor fulfilment analytics',
        'stock_history.view' => 'View stock history',
        'stock_history.export' => 'Export stock history',
        'stock_history.product' => 'View product stock movements',
        'stock_history.asset' => 'View asset stock movements',
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

    public static function defaultSystemRoleTemplates(): array
    {
        $modulePermissions = [
            'users' => ['read', 'create', 'update', 'delete'],
            'roles' => ['read', 'create', 'update', 'delete'],
            'cities' => ['read', 'create', 'update', 'delete'],
            'warehouses' => ['read', 'create', 'update', 'delete'],
            'vendors' => ['read', 'create', 'update', 'delete'],
            'customers' => ['read', 'create', 'update', 'delete'],
            'products' => ['read', 'create', 'update', 'delete'],
            'assets' => ['read', 'create', 'update', 'delete'],
            'rentals' => ['read', 'create', 'update', 'delete'],
            'sales' => ['read', 'create', 'update', 'delete'],
            'invoices' => ['read', 'create', 'update', 'delete'],
            'payments' => ['read', 'create', 'update', 'delete'],
            'deliveries' => ['read', 'create', 'update', 'delete'],
            'reports' => ['read'],
            'settings' => ['read', 'update'],
        ];

        return [
            'super_admin' => [
                'name' => 'Super Admin',
                'description' => 'Super Admin role',
                'permissions' => $modulePermissions,
            ],
            'admin_operations' => [
                'name' => 'Operations Admin',
                'description' => 'Operations Admin role',
                'permissions' => array_merge($modulePermissions, [
                    'roles' => ['read'],
                    'users' => ['read', 'update'],
                    'settings' => ['read'],
                ]),
            ],
            'sales' => [
                'name' => 'Sales',
                'description' => 'Sales role',
                'permissions' => [
                    'customers' => ['read', 'create', 'update'],
                    'products' => ['read'],
                    'rentals' => ['read', 'create', 'update'],
                    'sales' => ['read', 'create', 'update'],
                    'invoices' => ['read', 'create', 'update'],
                    'payments' => ['read', 'create'],
                    'deliveries' => ['read'],
                    'reports' => ['read'],
                ],
            ],
            'sales_renewals' => [
                'name' => 'Sales Renewals',
                'description' => 'Sales Renewals role',
                'permissions' => [
                    'customers' => ['read', 'update'],
                    'products' => ['read'],
                    'rentals' => ['read', 'update'],
                    'sales' => ['read'],
                    'invoices' => ['read', 'create'],
                    'payments' => ['read'],
                    'deliveries' => ['read'],
                    'reports' => ['read'],
                ],
            ],
            'delivery' => [
                'name' => 'Delivery Staff',
                'description' => 'Delivery Staff role',
                'permissions' => [
                    'deliveries' => ['read', 'update'],
                    'rentals' => ['read'],
                ],
            ],
            'vendor' => [
                'name' => 'Vendor',
                'description' => 'Vendor role',
                'permissions' => [
                    'deliveries' => ['read', 'update'],
                    'rentals' => ['read'],
                ],
            ],
            'staff' => [
                'name' => 'Staff',
                'description' => 'Staff role',
                'permissions' => [
                    'dashboard' => ['read'],
                ],
            ],
        ];
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
