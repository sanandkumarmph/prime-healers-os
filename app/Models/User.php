<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Rental;
use App\Models\Organization;
use App\Models\Role;
use App\Models\City;
use Illuminate\Support\Arr;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN_OPERATIONS = 'admin_operations';
    public const ROLE_SALES = 'sales';
    public const ROLE_SALES_RENEWALS = 'sales_renewals';
    public const ROLE_DELIVERY = 'delivery';
    public const ROLE_VENDOR = 'vendor';
    public const ROLE_FINANCE = 'finance';
    public const ROLE_SERVICE = 'service';
    public const ROLE_OPERATIONS_EXECUTIVE = 'operations_executive';
    public const ROLE_DELIVERY_EXECUTIVE = 'delivery_executive';
    public const ROLE_THIRD_PARTY = 'third_party';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'password',
        'role',
        'organization_id',
        'role_id',
        'city_id',
        'is_internal',
        'is_active',
        'notification_sound_enabled',
        'notification_voice_enabled',
        'notification_sound_variant',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notification_sound_enabled' => 'boolean',
            'notification_voice_enabled' => 'boolean',
            'notification_sound_variant' => 'string',
        ];
    }

    public function createdRentals()
    {
        return $this->hasMany(Rental::class, 'created_by_user_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->effective_role === self::ROLE_SUPER_ADMIN;
    }

    public function isAdminOperations(): bool
    {
        return $this->effective_role === self::ROLE_ADMIN_OPERATIONS;
    }

    public function isSales(): bool
    {
        return $this->effective_role === self::ROLE_SALES;
    }

    public function isSalesRenewals(): bool
    {
        return $this->effective_role === self::ROLE_SALES_RENEWALS;
    }

    public function isDelivery(): bool
    {
        return $this->effective_role === self::ROLE_DELIVERY;
    }

    public function isVendor(): bool
    {
        return $this->effective_role === self::ROLE_VENDOR;
    }

    public function canManageProducts(): bool
    {
        return $this->canAccessModule('products', 'read');
    }

    public function canManageRentals(): bool
    {
        return $this->canAccessModule('rentals', 'read');
    }

    public function canManageSales(): bool
    {
        return $this->canAccessModule('sales', 'read');
    }

    public function canManageCustomers(): bool
    {
        return $this->canAccessModule('customers', 'read');
    }

    public function canManageStaff(): bool
    {
        return $this->canAccessModule('users', 'read');
    }

    public function canAccessRenewals(): bool
    {
        return $this->canAccessModule('rentals', 'read')
            && $this->matchesLegacyRoles([
                self::ROLE_SUPER_ADMIN,
                self::ROLE_ADMIN_OPERATIONS,
                self::ROLE_SALES_RENEWALS,
            ]);
    }

    public function canAccessAssignedWork(): bool
    {
        return $this->canAccessModule('deliveries', 'read');
    }

    public function canSeeFinanceSummary(): bool
    {
        return $this->canAccessModule('payments', 'read')
            && $this->canAccessModule('invoices', 'read');
    }

    public function canSeeRentalFinance(): bool
    {
        return $this->canAccessModule('payments', 'read')
            || $this->canAccessModule('invoices', 'read');
    }

    public function canSeeLimitedDashboardCards(): bool
    {
        return $this->matchesLegacyRoles([
            self::ROLE_SALES,
            self::ROLE_SALES_RENEWALS,
            self::ROLE_DELIVERY,
            self::ROLE_VENDOR,
        ]) || $this->canAccessAnyModule(['rentals', 'sales', 'deliveries'], 'read');
    }

    public function canSeeOperationsDashboard(): bool
    {
        return $this->matchesLegacyRoles([self::ROLE_ADMIN_OPERATIONS, 'operations', 'delivery_coordinator'])
            || $this->canAccessAnyModule(['rentals', 'deliveries', 'assets', 'warehouses'], 'read');
    }

    public function getEffectiveRoleAttribute(): string
    {
        return $this->assignedRole?->slug ?: ($this->role ?: 'staff');
    }

    public function hasModulePermission(string $module, string $action = 'read'): bool
    {
        if ($this->effective_role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        if ($this->assignedRole) {
            return $this->assignedRole->allows($module, $action);
        }

        return false;
    }

    public function canAccessModule(string $module, string $action = 'read'): bool
    {
        $module = strtolower(trim($module));
        $action = strtolower(trim($action));

        if ($this->effective_role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        if (array_key_exists('is_active', $this->attributes) && !$this->is_active) {
            return false;
        }

        if ($module === 'dashboard') {
            return $action === 'read' && $this->canAccessDashboard();
        }

        if ($module === 'inventory') {
            return $this->canAccessAnyModule(['products', 'assets', 'warehouses'], $action);
        }

        if ($this->assignedRole) {
            return $this->hasModulePermission($module, $action);
        }

        if ($this->hasModulePermission($module, $action)) {
            return true;
        }

        return $this->legacyRoleAllows($module, $action);
    }

    public function hasPermission(string $permission): bool
    {
        $permission = strtolower(trim($permission));

        if ($permission === '') {
            return false;
        }

        if ($this->effective_role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        if (array_key_exists('is_active', $this->attributes) && !$this->is_active) {
            return false;
        }

        if (str_contains($permission, '.')) {
            [$group, $action] = explode('.', $permission, 2);

            if (array_key_exists($group, Role::moduleOptions()) && in_array($action, Role::actionOptions(), true)) {
                if ($this->assignedRole) {
                    return $this->hasModulePermission($group, $action);
                }

                return $this->hasModulePermission($group, $action)
                    || $this->legacyRoleAllows($group, $action);
            }
        }

        if ($this->assignedRole?->allowsSpecialPermission($permission)) {
            return true;
        }

        return in_array($permission, $this->roleExtraPermissions()[$this->effective_role] ?? [], true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function canAccessAnyModule(array $modules, string $action = 'read'): bool
    {
        foreach ($modules as $module) {
            if ($this->canAccessModule($module, $action)) {
                return true;
            }
        }

        return false;
    }

    public function canViewFinance(?string $permission = null): bool
    {
        if ($permission !== null) {
            return $this->hasPermission($permission);
        }

        return $this->hasAnyPermission([
            'finance.view_revenue',
            'finance.view_dues',
            'finance.view_payments',
            'finance.view_deposits',
            'finance.view_profit',
            'finance.view_transport',
            'finance.view_other_charges',
            'finance.view_reports',
            'dashboard.finance.full',
        ]);
    }

    public function canViewFinanceDashboard(): bool
    {
        return $this->canViewFinance();
    }

    public function scopeType(?string $module = null): string
    {
        $roleScopes = $this->roleScopeMatrix()[$this->effective_role] ?? [];

        if ($module !== null) {
            return $roleScopes[strtolower($module)] ?? ($roleScopes['default'] ?? 'all');
        }

        return $roleScopes['default'] ?? 'all';
    }

    public function hasScope(string $scope, ?string $module = null): bool
    {
        return $this->scopeType($module) === strtolower($scope);
    }

    public function defaultRedirectRoute(): string
    {
        $candidates = [
            'dashboard' => fn () => $this->hasPermission('dashboard.main'),
            'inventory.dashboard' => fn () => $this->hasPermission('dashboard.inventory'),
            'rentals.index' => fn () => $this->canAccessModule('rentals', 'read'),
            'deliveries.index' => fn () => $this->canAccessModule('deliveries', 'read'),
            'sales.index' => fn () => $this->canAccessModule('sales', 'read'),
            'invoices.index' => fn () => $this->canAccessModule('invoices', 'read'),
            'customers.index' => fn () => $this->canAccessModule('customers', 'read'),
            'products.index' => fn () => $this->canAccessModule('products', 'read'),
            'assets.index' => fn () => $this->canAccessModule('assets', 'read'),
            'reports.index' => fn () => $this->canAccessModule('reports', 'read'),
            'users.index' => fn () => $this->canAccessModule('users', 'read'),
            'organization.settings.edit' => fn () => $this->canAccessModule('settings', 'read'),
            'profile.edit' => fn () => true,
        ];

        foreach ($candidates as $routeName => $allowed) {
            if ($allowed()) {
                return $routeName;
            }
        }

        return 'profile.edit';
    }

    public function defaultRedirectPath(): string
    {
        return route($this->defaultRedirectRoute(), absolute: false);
    }

    public function assignedRole()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function cityRecord()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function performedStockMovements()
    {
        return $this->hasMany(StockMovement::class, 'performed_by_user_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    private function matchesLegacyRoles(array $roles): bool
    {
        return in_array($this->effective_role, $roles, true);
    }

    public function canAccessDashboard(): bool
    {
        if (array_key_exists('is_active', $this->attributes) && !$this->is_active) {
            return false;
        }

        return true
            && (
                $this->hasPermission('dashboard.main')
            || $this->hasPermission('dashboard.inventory')
            || $this->legacyRoleAllows('dashboard', 'read')
            || $this->legacyRoleAllows('inventory', 'read')
            || $this->canAccessAnyModule([
                'customers',
                'rentals',
                'sales',
                'deliveries',
                'assets',
                'warehouses',
                'invoices',
                'payments',
                'reports',
                'products',
            ], 'read')
            || $this->canAccessAnyModule([
                'customers',
                'rentals',
                'sales',
                'deliveries',
                'invoices',
                'payments',
                'products',
            ], 'create')
        );
    }

    private function legacyRoleAllows(string $module, string $action): bool
    {
        $rolePermissions = $this->legacyPermissionMatrix()[$this->effective_role] ?? [];

        return in_array($action, $rolePermissions[$module] ?? [], true);
    }

    private function legacyPermissionMatrix(): array
    {
        $fullCrud = ['read', 'create', 'update', 'delete'];
        $readOnly = ['read'];
        $readUpdate = ['read', 'update'];
        $salesCrud = ['read', 'create', 'update', 'delete'];

        return [
            self::ROLE_ADMIN_OPERATIONS => [
                'users' => $fullCrud,
                'roles' => $fullCrud,
                'cities' => $fullCrud,
                'warehouses' => $fullCrud,
                'vendors' => $fullCrud,
                'customers' => $fullCrud,
                'products' => $fullCrud,
                'assets' => $fullCrud,
                'rentals' => $fullCrud,
                'sales' => $fullCrud,
                'deliveries' => $fullCrud,
                'reports' => $readOnly,
                'settings' => $readOnly,
            ],
            self::ROLE_SALES => [
                'customers' => $salesCrud,
                'products' => $readOnly,
                'rentals' => $salesCrud,
                'sales' => $salesCrud,
                'invoices' => $readUpdate,
                'payments' => $readOnly,
            ],
            self::ROLE_SALES_RENEWALS => [
                'customers' => $salesCrud,
                'products' => $readOnly,
                'rentals' => $salesCrud,
                'sales' => $salesCrud,
                'invoices' => $readUpdate,
                'payments' => $readOnly,
            ],
            self::ROLE_FINANCE => [
                'customers' => $readOnly,
                'sales' => $readOnly,
                'invoices' => $fullCrud,
                'payments' => $fullCrud,
                'reports' => $readOnly,
                'rentals' => $readOnly,
            ],
            self::ROLE_DELIVERY => [
                'deliveries' => $readUpdate,
                'rentals' => $readOnly,
                'customers' => $readOnly,
                'assets' => $readOnly,
            ],
            self::ROLE_VENDOR => [
                'deliveries' => $readUpdate,
            ],
            self::ROLE_SERVICE => [
                'customers' => $readOnly,
                'assets' => $readOnly,
                'deliveries' => $readOnly,
            ],
            self::ROLE_OPERATIONS_EXECUTIVE => [
                'customers' => $fullCrud,
                'products' => $fullCrud,
                'assets' => $fullCrud,
                'rentals' => $fullCrud,
                'deliveries' => $fullCrud,
                'warehouses' => $fullCrud,
            ],
            self::ROLE_DELIVERY_EXECUTIVE => [
                'deliveries' => $readUpdate,
                'rentals' => $readOnly,
                'customers' => $readOnly,
                'assets' => $readOnly,
            ],
            self::ROLE_THIRD_PARTY => [
                'deliveries' => $fullCrud,
            ],
            'admin' => [
                'users' => $fullCrud,
                'roles' => $fullCrud,
                'cities' => $fullCrud,
                'warehouses' => $fullCrud,
                'vendors' => $fullCrud,
                'customers' => $fullCrud,
                'products' => $fullCrud,
                'assets' => $fullCrud,
                'rentals' => $fullCrud,
                'sales' => $fullCrud,
                'invoices' => $fullCrud,
                'payments' => $fullCrud,
                'deliveries' => $fullCrud,
                'reports' => $readOnly,
                'settings' => $fullCrud,
            ],
            'operations' => [
                'customers' => $fullCrud,
                'products' => $fullCrud,
                'assets' => $fullCrud,
                'rentals' => $fullCrud,
                'deliveries' => $fullCrud,
                'warehouses' => $fullCrud,
                'vendors' => $fullCrud,
                'reports' => $readOnly,
            ],
            'delivery_coordinator' => [
                'customers' => $readOnly,
                'products' => $readOnly,
                'rentals' => $readOnly,
                'deliveries' => $fullCrud,
                'warehouses' => $readOnly,
                'vendors' => $readOnly,
            ],
        ];
    }

    private function roleExtraPermissions(): array
    {
        return [
            self::ROLE_ADMIN_OPERATIONS => [
                'dashboard.rentals.full',
                'dashboard.delivery.full',
                'dashboard.service.full',
                'customers.proof.download',
                'customers.export',
                'invoices.export',
                'invoices.print',
                'payments.export',
                'scope.all',
            ],
            self::ROLE_FINANCE => [
                'customers.proof.download',
                'finance.view_revenue',
                'finance.view_dues',
                'finance.view_payments',
                'finance.view_deposits',
                'finance.view_profit',
                'finance.view_transport',
                'finance.view_other_charges',
                'finance.view_reports',
                'invoices.export',
                'invoices.print',
                'payments.export',
                'scope.all',
            ],
            self::ROLE_SALES => [
                'dashboard.sales.minimal',
                'scope.self_created',
            ],
            self::ROLE_SALES_RENEWALS => [
                'dashboard.sales.minimal',
                'scope.self_created',
            ],
            self::ROLE_SERVICE => [
                'dashboard.service.minimal',
                'scope.assigned',
            ],
            self::ROLE_OPERATIONS_EXECUTIVE => [
                'customers.proof.download',
                'dashboard.rentals.minimal',
                'dashboard.delivery.minimal',
                'scope.assigned',
            ],
            self::ROLE_DELIVERY => [
                'dashboard.delivery.minimal',
                'scope.assigned',
            ],
            self::ROLE_DELIVERY_EXECUTIVE => [
                'dashboard.delivery.minimal',
                'scope.assigned',
            ],
            self::ROLE_VENDOR => [
                'scope.assigned',
            ],
            self::ROLE_THIRD_PARTY => [
                'scope.assigned',
            ],
            'admin' => [
                'customers.proof.download',
                'customers.export',
                'invoices.export',
                'invoices.print',
                'payments.export',
                'scope.all',
            ],
        ];
    }

    private function roleScopeMatrix(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => ['default' => 'all'],
            self::ROLE_ADMIN_OPERATIONS => ['default' => 'all'],
            self::ROLE_FINANCE => ['default' => 'all', 'invoices' => 'all', 'payments' => 'all'],
            self::ROLE_SALES => ['default' => 'self_created', 'rentals' => 'self_created', 'invoices' => 'self_created'],
            self::ROLE_SALES_RENEWALS => ['default' => 'self_created', 'rentals' => 'self_created', 'invoices' => 'self_created'],
            self::ROLE_SERVICE => ['default' => 'assigned', 'rentals' => 'assigned', 'deliveries' => 'assigned'],
            self::ROLE_OPERATIONS_EXECUTIVE => ['default' => 'assigned', 'rentals' => 'assigned', 'deliveries' => 'assigned'],
            self::ROLE_DELIVERY => ['default' => 'assigned', 'deliveries' => 'assigned', 'rentals' => 'assigned'],
            self::ROLE_DELIVERY_EXECUTIVE => ['default' => 'assigned', 'deliveries' => 'assigned', 'rentals' => 'assigned'],
            self::ROLE_VENDOR => ['default' => 'assigned', 'deliveries' => 'assigned'],
            self::ROLE_THIRD_PARTY => ['default' => 'assigned', 'deliveries' => 'assigned'],
        ];
    }
}
