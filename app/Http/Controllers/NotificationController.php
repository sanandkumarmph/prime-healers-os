<?php

namespace App\Http\Controllers;

use App\Services\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenterService $notifications)
    {
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $user ? $this->notifications->unreadCountFor($user) : 0,
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $user ? $this->notifications->unreadCountFor($user) : 0,
            'notifications' => $user ? $this->notifications->latestFor($user, 8)->all() : [],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless($this->notifications->markAsRead($user, $notification), 404);

        return response()->json([
            'success' => true,
            'unread_count' => $this->notifications->unreadCountFor($user),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $updated = $this->notifications->markAllAsRead($user);

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'sound_alerts_enabled' => ['required', 'boolean'],
            'voice_alerts_enabled' => ['required', 'boolean'],
        ]);

        $user->forceFill([
            'notification_sound_enabled' => (bool) $validated['sound_alerts_enabled'],
            'notification_voice_enabled' => (bool) $validated['voice_alerts_enabled'],
        ])->save();

        return response()->json([
            'success' => true,
            'sound_alerts_enabled' => (bool) $user->notification_sound_enabled,
            'voice_alerts_enabled' => (bool) $user->notification_voice_enabled,
        ]);
    }
}
