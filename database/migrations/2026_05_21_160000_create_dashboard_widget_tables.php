<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('widget_key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('category')->default('operations');
            $table->string('sensitivity')->default('public_operational');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('role_dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('dashboard_widget_id')->constrained('dashboard_widgets')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'role_id', 'dashboard_widget_id'], 'role_dashboard_widgets_unique');
        });

        DB::table('dashboard_widgets')->insert([
            ['widget_key' => 'primary_pending_deliveries', 'name' => 'Pending Deliveries', 'description' => 'Primary action card for pending delivery work.', 'category' => 'priority', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 10, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'primary_overdue_rentals', 'name' => 'Overdue Rentals', 'description' => 'Primary action card for overdue rentals.', 'category' => 'priority', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 20, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'primary_outstanding_dues', 'name' => 'Outstanding Dues', 'description' => 'Primary action card for outstanding collections.', 'category' => 'priority', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 30, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'primary_pending_pickups', 'name' => 'Pending Pickups', 'description' => 'Primary action card for open pickup tasks.', 'category' => 'priority', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'primary_tasks_completed_today', 'name' => 'Tasks Completed Today', 'description' => 'Primary action card for today completed logistics tasks.', 'category' => 'priority', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 50, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],

            ['widget_key' => 'kpi_active_rentals', 'name' => 'Active Rentals', 'description' => 'KPI card for active rentals.', 'category' => 'kpi', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 60, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_deliveries_today', 'name' => 'Deliveries Today', 'description' => 'KPI card for deliveries completed today.', 'category' => 'kpi', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 70, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_outstanding_invoices', 'name' => 'Outstanding Invoices', 'description' => 'KPI card for unpaid invoices.', 'category' => 'kpi', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 80, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_unbilled_rentals', 'name' => 'Unbilled Rentals', 'description' => 'KPI card for delivered rentals awaiting invoice.', 'category' => 'kpi', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 90, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_unpaid_renewal_invoices', 'name' => 'Unpaid Renewal Invoices', 'description' => 'KPI card for renewal invoice follow-up.', 'category' => 'kpi', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 100, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_unbilled_sales', 'name' => 'Unbilled Sales', 'description' => 'KPI card for sales orders missing invoices.', 'category' => 'kpi', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 110, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_overdue_rentals', 'name' => 'Overdue Rentals KPI', 'description' => 'KPI card for overdue rentals.', 'category' => 'kpi', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 120, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_collections_this_month', 'name' => 'Collections This Month', 'description' => 'KPI card for monthly collections.', 'category' => 'kpi', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 130, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_rental_available', 'name' => 'Rental Available', 'description' => 'KPI card for available rental assets.', 'category' => 'kpi', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 140, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'kpi_sale_stock_available', 'name' => 'Sale Stock Available', 'description' => 'KPI card for sellable stock.', 'category' => 'kpi', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 150, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],

            ['widget_key' => 'snapshot_collections_today', 'name' => 'Collections Today Snapshot', 'description' => 'Snapshot tile for collections today.', 'category' => 'snapshot', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 160, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'snapshot_deliveries_pending', 'name' => 'Deliveries Pending Snapshot', 'description' => 'Snapshot tile for open deliveries.', 'category' => 'snapshot', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 170, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'snapshot_returns_expected', 'name' => 'Returns Expected Snapshot', 'description' => 'Snapshot tile for returns due today.', 'category' => 'snapshot', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 180, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'snapshot_open_invoices', 'name' => 'Open Invoices Snapshot', 'description' => 'Snapshot tile for open invoices.', 'category' => 'snapshot', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 190, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'snapshot_asset_alerts', 'name' => 'Asset Alerts Snapshot', 'description' => 'Snapshot tile for maintenance and stock alerts.', 'category' => 'snapshot', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 200, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],

            ['widget_key' => 'widget_today_renewals', 'name' => 'Today\'s Renewals', 'description' => 'Operational widget for renewals due today.', 'category' => 'today_widget', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 210, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'widget_today_pickups', 'name' => 'Today\'s Pickups', 'description' => 'Operational widget for pickup tasks due today.', 'category' => 'today_widget', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 220, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'widget_today_deliveries', 'name' => 'Today\'s Deliveries', 'description' => 'Operational widget for delivery tasks due today.', 'category' => 'today_widget', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 230, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'widget_today_followups', 'name' => 'Today\'s Follow-ups', 'description' => 'Operational widget for today follow-ups.', 'category' => 'today_widget', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 240, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'widget_pending_payments', 'name' => 'Pending Payments', 'description' => 'Operational widget for payment follow-up list.', 'category' => 'today_widget', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 250, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],

            ['widget_key' => 'section_sales_overview', 'name' => 'Sales Overview Section', 'description' => 'Sales operations card grid.', 'category' => 'section', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 260, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_finance_summary', 'name' => 'Finance Summary Section', 'description' => 'Finance KPI section with amount visibility.', 'category' => 'section', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 270, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_staff_workload', 'name' => 'Staff Workload', 'description' => 'Management view of staff workload.', 'category' => 'section', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 280, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_business_partner_signals', 'name' => 'Business Partner Signals', 'description' => 'Sensitive partner workload and follow-up signals.', 'category' => 'section', 'sensitivity' => 'sensitive_business', 'default_enabled' => true, 'sort_order' => 290, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_inventory_intelligence', 'name' => 'Inventory Intelligence', 'description' => 'Inventory and utilization management section.', 'category' => 'section', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 300, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_organization_analytics', 'name' => 'Organization Analytics', 'description' => 'Organization-wide analytics and rankings.', 'category' => 'section', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 310, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_recent_activity', 'name' => 'Recent Activity Feed', 'description' => 'Unified operational activity feed.', 'category' => 'section', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 320, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_recent_rentals', 'name' => 'Recent Rentals', 'description' => 'Recent rentals feed.', 'category' => 'section', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 330, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_recent_customers', 'name' => 'Recent Customers', 'description' => 'Recent customers feed.', 'category' => 'section', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 340, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_recent_payments', 'name' => 'Recent Payments', 'description' => 'Recent payments feed.', 'category' => 'section', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 350, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_recent_deliveries', 'name' => 'Recent Deliveries', 'description' => 'Recent delivery and pickup feed.', 'category' => 'section', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 360, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'section_high_priority_followups', 'name' => 'High Priority Follow-ups', 'description' => 'Urgent follow-up feed.', 'category' => 'section', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 370, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],

            ['widget_key' => 'alert_overdue_renewals', 'name' => 'Alert: Overdue Renewals', 'description' => 'Operational alert for overdue renewals.', 'category' => 'alert', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 380, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'alert_pickups_delayed', 'name' => 'Alert: Pickups Delayed', 'description' => 'Operational alert for delayed pickups.', 'category' => 'alert', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 390, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'alert_failed_field_tasks', 'name' => 'Alert: Failed Field Tasks', 'description' => 'Operational alert for failed pickups or deliveries.', 'category' => 'alert', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 400, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'alert_unassigned_tasks', 'name' => 'Alert: Unassigned Tasks', 'description' => 'Operational alert for unassigned field tasks.', 'category' => 'alert', 'sensitivity' => 'management', 'default_enabled' => true, 'sort_order' => 410, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'alert_high_priority_followups', 'name' => 'Alert: High Priority Follow-ups', 'description' => 'Operational alert for urgent follow-up workload.', 'category' => 'alert', 'sensitivity' => 'role_operational', 'default_enabled' => true, 'sort_order' => 420, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['widget_key' => 'alert_large_unpaid_invoices', 'name' => 'Alert: Large Unpaid Invoices', 'description' => 'Operational alert for high-value unpaid invoices.', 'category' => 'alert', 'sensitivity' => 'finance', 'default_enabled' => true, 'sort_order' => 430, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_dashboard_widgets');
        Schema::dropIfExists('dashboard_widgets');
    }
};
