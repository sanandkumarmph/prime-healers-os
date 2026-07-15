<?php

namespace Tests\Feature\Regression;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesVisibilityBoundariesRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_keeps_record_finance_without_dashboard_profitability_or_management_access(): void
    {
        $organization = TestData::organization();

        $sales = TestData::user($organization, ['role' => User::ROLE_SALES]);
        $adminOperations = TestData::user($organization, ['role' => User::ROLE_ADMIN_OPERATIONS]);
        $finance = TestData::user($organization, ['role' => User::ROLE_FINANCE]);
        $vendor = TestData::user($organization, ['role' => User::ROLE_VENDOR]);

        $this->assertTrue($sales->canViewRecordFinance());
        $this->assertTrue($sales->canSeeSalesFinance());
        $this->assertTrue($sales->canSeeRentalFinance());
        $this->assertFalse($sales->canViewFinanceDashboard());
        $this->assertFalse($sales->canViewProfitability());
        $this->assertFalse($sales->canViewManagementAnalytics());
        $this->assertFalse($sales->canViewSensitiveBusinessAnalytics());

        $this->assertTrue($adminOperations->canViewRecordFinance());
        $this->assertTrue($adminOperations->canViewManagementAnalytics());
        $this->assertFalse($adminOperations->canViewProfitability());

        $this->assertTrue($finance->canViewRecordFinance());
        $this->assertTrue($finance->canViewFinanceDashboard());
        $this->assertTrue($finance->canViewProfitability());

        $this->assertFalse($vendor->canViewRecordFinance());
        $this->assertFalse($vendor->canViewFinanceDashboard());
        $this->assertFalse($vendor->canViewProfitability());
    }

    public function test_sales_role_cannot_open_general_reports_even_if_reports_module_is_assigned(): void
    {
        $organization = TestData::organization();

        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Sales With Legacy Reports',
            'slug' => User::ROLE_SALES,
            'description' => 'Legacy Sales role with stale reports permission.',
            'permissions' => Role::normalizePermissions([
                'reports' => ['read'],
            ]),
            'is_system' => false,
            'is_active' => true,
        ]);

        $sales = TestData::user($organization, [
            'role' => User::ROLE_SALES,
            'role_id' => $role->id,
        ]);

        $this->assertTrue($sales->canAccessModule('reports', 'read'));
        $this->assertFalse($sales->canAccessGeneralReports());

        $this->actingAs($sales)
            ->get(route('reports.index'))
            ->assertForbidden();

        $this->actingAs($sales)
            ->get(route('reports.export.csv'))
            ->assertForbidden();
    }

    public function test_non_sales_report_reader_still_uses_existing_report_permission(): void
    {
        $organization = TestData::organization();

        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Report Reader',
            'slug' => 'report_reader',
            'description' => 'Can read reports.',
            'permissions' => Role::normalizePermissions([
                'reports' => ['read'],
            ]),
            'is_system' => false,
            'is_active' => true,
        ]);

        $reader = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);

        $this->assertTrue($reader->canAccessGeneralReports());
    }
}
