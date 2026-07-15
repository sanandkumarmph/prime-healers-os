<?php

namespace Tests\Feature\Regression;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestData;
use Tests\TestCase;

class TeamPerformanceDrillDownLinksRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_performance_links_use_supported_destination_filters(): void
    {
        $organization = TestData::organization();
        $admin = TestData::user($organization, [
            'name' => 'Prime Healers Admin',
            'email' => 'team-admin@example.com',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $teamMember = TestData::user($organization, [
            'name' => 'Team Member',
            'email' => 'team-member@example.com',
            'role' => User::ROLE_SALES,
            'is_internal' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard', [
            'team_performance_period' => 'custom_range',
            'team_performance_from_date' => '2026-07-01',
            'team_performance_to_date' => '2026-07-11',
            'team_performance_city' => 'Bengaluru',
        ]));

        $response->assertOk();

        $content = urldecode(html_entity_decode($response->getContent()));
        $salesCreatorKey = Schema::hasColumn('sales', 'created_by_user_id') ? 'created_by_user_id' : 'created_by';
        $invoiceCreatorKey = Schema::hasColumn('invoices', 'created_by_user_id') ? 'created_by_user_id' : 'created_by';

        $this->assertStringContainsString('Team Performance', $content);
        $this->assertStringContainsString('title="Total rentals and sales for Team Member"', $content);
        $this->assertStringContainsString('/rentals?', $content);
        $this->assertStringContainsString('created_by_user_id=' . $teamMember->id, $content);
        $this->assertStringContainsString('/sales?', $content);
        $this->assertStringContainsString($salesCreatorKey . '=' . $teamMember->id, $content);
        $this->assertStringContainsString('/deliveries?', $content);
        $this->assertStringContainsString('staff=user:' . $teamMember->id, $content);
        $this->assertStringContainsString('area=city:Bengaluru', $content);
        $this->assertStringContainsString('/renewal-center?', $content);
        $this->assertStringContainsString('reminder_user_id=' . $teamMember->id, $content);
        $this->assertStringContainsString('renewed_by_user_id=' . $teamMember->id, $content);
        $this->assertStringContainsString('/invoices?', $content);
        $this->assertStringContainsString($invoiceCreatorKey . '=' . $teamMember->id, $content);

        $this->assertStringNotContainsString('team_member_id=', $content);
        $this->assertStringNotContainsString('assigned_user_id=', $content);
        $this->assertStringNotContainsString('Open orders for Team Member', $content);
    }
}
