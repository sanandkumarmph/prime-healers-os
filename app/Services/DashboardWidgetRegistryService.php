<?php

namespace App\Services;

use App\Models\DashboardWidget;
use App\Models\Role;
use App\Models\RoleDashboardWidget;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DashboardWidgetRegistryService
{
    public const SENSITIVITY_PUBLIC_OPERATIONAL = 'public_operational';
    public const SENSITIVITY_ROLE_OPERATIONAL = 'role_operational';
    public const SENSITIVITY_MANAGEMENT = 'management';
    public const SENSITIVITY_FINANCE = 'finance';
    public const SENSITIVITY_SENSITIVE_BUSINESS = 'sensitive_business';

    public function all(): Collection
    {
        if (!Schema::hasTable('dashboard_widgets')) {
            return collect();
        }

        return DashboardWidget::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function configurationForRole(Role $role): Collection
    {
        $widgets = $this->all()->keyBy('id');

        $overrides = Schema::hasTable('role_dashboard_widgets')
            ? RoleDashboardWidget::query()
                ->where('organization_id', $role->organization_id)
                ->where('role_id', $role->id)
                ->get()
                ->keyBy('dashboard_widget_id')
            : collect();

        return $widgets
            ->map(function (DashboardWidget $widget) use ($role, $overrides) {
                $override = $overrides->get($widget->id);
                $enabled = $override?->is_enabled ?? $this->defaultEnabledForRoleSlug($role->slug ?: $role->name, $widget->widget_key);
                $sortOrder = $override?->sort_order ?? $widget->sort_order;

                return [
                    'widget' => $widget,
                    'widget_key' => $widget->widget_key,
                    'name' => $widget->name,
                    'description' => $widget->description,
                    'category' => $widget->category,
                    'sensitivity' => $widget->sensitivity,
                    'enabled' => (bool) $enabled,
                    'sort_order' => (int) $sortOrder,
                ];
            })
            ->sortBy([
                ['enabled', 'desc'],
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    public function visibleWidgetsForUser(User $user): Collection
    {
        $role = $user->assignedRole;
        $configured = $role
            ? $this->configurationForRole($role)
            : $this->fallbackConfigurationForSlug($user->effective_role);

        return $configured
            ->filter(fn (array $item) => $item['enabled'] && $this->userCanSeeWidget($user, $item))
            ->sortBy([
                ['sort_order', 'asc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    public function saveRoleConfiguration(Role $role, array $widgets): void
    {
        $registry = $this->all()->keyBy('widget_key');

        foreach ($registry as $widgetKey => $widget) {
            $payload = $widgets[$widgetKey] ?? [];
            $enabled = filter_var($payload['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            RoleDashboardWidget::query()->updateOrCreate(
                [
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->id,
                    'dashboard_widget_id' => $widget->id,
                ],
                [
                    'is_enabled' => $enabled ?? false,
                    'sort_order' => isset($payload['sort_order']) ? max(1, (int) $payload['sort_order']) : (int) $widget->sort_order,
                ]
            );
        }
    }

    public function widgetKeysForUser(User $user): array
    {
        return $this->visibleWidgetsForUser($user)->pluck('widget_key')->values()->all();
    }

    private function fallbackConfigurationForSlug(string $roleSlug): Collection
    {
        return $this->all()
            ->map(function (DashboardWidget $widget) use ($roleSlug) {
                return [
                    'widget' => $widget,
                    'widget_key' => $widget->widget_key,
                    'name' => $widget->name,
                    'description' => $widget->description,
                    'category' => $widget->category,
                    'sensitivity' => $widget->sensitivity,
                    'enabled' => $this->defaultEnabledForRoleSlug($roleSlug, $widget->widget_key),
                    'sort_order' => (int) $widget->sort_order,
                ];
            })
            ->values();
    }

    private function defaultEnabledForRoleSlug(string $roleSlug, string $widgetKey): bool
    {
        $roleSlug = str($roleSlug)->slug('_')->toString();

        $leadershipWidgets = [
            'primary_pending_deliveries',
            'primary_overdue_rentals',
            'primary_outstanding_dues',
            'primary_pending_pickups',
            'primary_tasks_completed_today',
            'kpi_active_rentals',
            'kpi_deliveries_today',
            'kpi_outstanding_invoices',
            'kpi_unbilled_rentals',
            'kpi_unpaid_renewal_invoices',
            'kpi_unbilled_sales',
            'kpi_overdue_rentals',
            'kpi_collections_this_month',
            'kpi_rental_available',
            'kpi_sale_stock_available',
            'snapshot_collections_today',
            'snapshot_deliveries_pending',
            'snapshot_returns_expected',
            'snapshot_open_invoices',
            'snapshot_asset_alerts',
            'widget_today_renewals',
            'widget_today_pickups',
            'widget_today_deliveries',
            'widget_today_followups',
            'widget_pending_payments',
            'section_sales_overview',
            'section_finance_summary',
            'section_staff_workload',
            'section_business_partner_signals',
            'section_inventory_intelligence',
            'section_organization_analytics',
            'section_recent_activity',
            'section_recent_rentals',
            'section_recent_customers',
            'section_recent_payments',
            'section_recent_deliveries',
            'section_high_priority_followups',
            'alert_overdue_renewals',
            'alert_pickups_delayed',
            'alert_failed_field_tasks',
            'alert_unassigned_tasks',
            'alert_high_priority_followups',
            'alert_large_unpaid_invoices',
        ];

        $salesWidgets = [
            'primary_pending_deliveries',
            'primary_pending_pickups',
            'primary_tasks_completed_today',
            'kpi_deliveries_today',
            'widget_today_renewals',
            'widget_today_followups',
            'section_recent_activity',
            'section_recent_rentals',
            'section_recent_customers',
            'section_recent_deliveries',
            'section_high_priority_followups',
            'alert_pickups_delayed',
            'alert_failed_field_tasks',
            'alert_high_priority_followups',
        ];

        $deliveryWidgets = [
            'primary_pending_deliveries',
            'primary_pending_pickups',
            'primary_tasks_completed_today',
            'kpi_deliveries_today',
            'snapshot_deliveries_pending',
            'widget_today_pickups',
            'widget_today_deliveries',
            'section_recent_deliveries',
            'alert_pickups_delayed',
            'alert_failed_field_tasks',
        ];

        $warehouseWidgets = [
            'snapshot_asset_alerts',
            'kpi_rental_available',
            'kpi_sale_stock_available',
            'section_inventory_intelligence',
            'section_recent_activity',
            'section_recent_deliveries',
        ];

        $financeWidgets = [
            'primary_outstanding_dues',
            'kpi_outstanding_invoices',
            'kpi_unpaid_renewal_invoices',
            'kpi_unbilled_sales',
            'kpi_collections_this_month',
            'snapshot_collections_today',
            'snapshot_open_invoices',
            'widget_pending_payments',
            'widget_today_followups',
            'section_finance_summary',
            'section_recent_activity',
            'section_recent_payments',
            'section_high_priority_followups',
            'alert_high_priority_followups',
            'alert_large_unpaid_invoices',
        ];

        $operationsWidgets = [
            'primary_pending_deliveries',
            'primary_pending_pickups',
            'primary_tasks_completed_today',
            'kpi_deliveries_today',
            'widget_today_renewals',
            'widget_today_pickups',
            'widget_today_deliveries',
            'widget_today_followups',
            'section_recent_activity',
            'section_recent_rentals',
            'section_recent_customers',
            'section_recent_deliveries',
            'section_high_priority_followups',
            'alert_pickups_delayed',
            'alert_failed_field_tasks',
            'alert_high_priority_followups',
        ];

        return match ($roleSlug) {
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN_OPERATIONS,
            'admin',
            'operations',
            'operations_manager' => in_array($widgetKey, $leadershipWidgets, true),
            User::ROLE_SALES,
            User::ROLE_SALES_RENEWALS => in_array($widgetKey, $salesWidgets, true),
            User::ROLE_DELIVERY,
            User::ROLE_DELIVERY_EXECUTIVE,
            User::ROLE_VENDOR,
            User::ROLE_THIRD_PARTY => in_array($widgetKey, $deliveryWidgets, true),
            User::ROLE_FINANCE => in_array($widgetKey, $financeWidgets, true),
            'warehouse',
            'warehouse_user',
            'inventory',
            'store_keeper',
            'warehouse_manager' => in_array($widgetKey, $warehouseWidgets, true),
            User::ROLE_OPERATIONS_EXECUTIVE,
            User::ROLE_SERVICE => in_array($widgetKey, $operationsWidgets, true),
            default => in_array($widgetKey, $operationsWidgets, true),
        };
    }

    private function userCanSeeWidget(User $user, array $item): bool
    {
        $warehouseOperationalWidgets = [
            'kpi_rental_available',
            'kpi_sale_stock_available',
            'snapshot_asset_alerts',
            'section_inventory_intelligence',
        ];

        $requiredPermission = $this->requiredPermissionForWidget($item['widget_key']);

        if ($requiredPermission && !$user->hasPermission($requiredPermission)) {
            return false;
        }

        if ($this->isWarehouseFocusedUser($user) && in_array($item['widget_key'], $warehouseOperationalWidgets, true)) {
            return true;
        }

        return match ($item['sensitivity']) {
            self::SENSITIVITY_FINANCE => $user->canViewFinanceDashboard(),
            self::SENSITIVITY_MANAGEMENT => $user->isSuperAdmin() || $user->isAdminOperations() || $user->hasAnyPermission([
                'dashboard.organization_analytics',
                'dashboard.staff_workload',
                'dashboard.inventory_intelligence',
                'dashboard.sales_analytics',
            ]),
            self::SENSITIVITY_SENSITIVE_BUSINESS => $user->isSuperAdmin() || $user->isAdminOperations() || $user->hasAnyPermission([
                'dashboard.business_signals',
                'dashboard.organization_analytics',
            ]),
            self::SENSITIVITY_ROLE_OPERATIONAL,
            self::SENSITIVITY_PUBLIC_OPERATIONAL => true,
            default => true,
        };
    }

    private function requiredPermissionForWidget(string $widgetKey): ?string
    {
        return match ($widgetKey) {
            'primary_pending_deliveries',
            'primary_pending_pickups',
            'primary_tasks_completed_today',
            'kpi_deliveries_today',
            'snapshot_deliveries_pending',
            'widget_today_pickups',
            'widget_today_deliveries',
            'section_recent_deliveries',
            'alert_pickups_delayed',
            'alert_failed_field_tasks',
            'alert_unassigned_tasks' => 'deliveries.read',
            'kpi_rental_available',
            'kpi_sale_stock_available',
            'snapshot_asset_alerts',
            'section_inventory_intelligence' => 'assets.read',
            'section_sales_overview',
            'kpi_unbilled_sales' => 'sales.read',
            'section_recent_rentals',
            'kpi_active_rentals',
            'kpi_overdue_rentals',
            'primary_overdue_rentals',
            'widget_today_renewals' => 'rentals.read',
            'kpi_outstanding_invoices',
            'kpi_unbilled_rentals',
            'kpi_unpaid_renewal_invoices',
            'snapshot_open_invoices',
            'widget_pending_payments',
            'section_recent_payments',
            'section_finance_summary',
            'alert_large_unpaid_invoices',
            'primary_outstanding_dues',
            'kpi_collections_this_month',
            'snapshot_collections_today' => 'invoices.read',
            'section_recent_customers' => 'customers.read',
            default => null,
        };
    }

    private function isWarehouseFocusedUser(User $user): bool
    {
        return ($user->canAccessModule('assets', 'read') || $user->canAccessModule('warehouses', 'read'))
            && !$user->canAccessModule('sales', 'read')
            && !$user->canAccessModule('payments', 'read')
            && !$user->canAccessModule('invoices', 'read')
            && !$user->canAccessModule('deliveries', 'read');
    }
}
