<?php

namespace Tests\Feature\Regression;

use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class NotificationPreferencesAndIndexRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization();
        $this->user = TestData::user($organization);
        $this->actingAs($this->user);
    }

    public function test_notification_preferences_accept_frontend_payload_and_persist_variant(): void
    {
        $this->postJson(route('notifications.preferences'), [
            'sound_enabled' => true,
            'voice_enabled' => true,
            'sound_variant' => 'soft',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sound_enabled', true)
            ->assertJsonPath('voice_enabled', true)
            ->assertJsonPath('sound_variant', 'soft');

        $fresh = $this->user->fresh();
        $this->assertTrue((bool) $fresh->notification_sound_enabled);
        $this->assertTrue((bool) $fresh->notification_voice_enabled);
        $this->assertSame('soft', $fresh->notification_sound_variant);
    }

    public function test_notifications_index_renders_real_view_all_page(): void
    {
        app(NotificationCenterService::class)->notifyUser($this->user, [
            'type' => 'pickup_assigned',
            'title' => 'New pickup assigned',
            'message' => 'Mr. Ramesh, Indiranagar',
            'action_url' => route('dashboard'),
            'priority' => 'high',
        ]);

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('All Notifications')
            ->assertSee('New pickup assigned')
            ->assertSee('Mark all as read');
    }
}
