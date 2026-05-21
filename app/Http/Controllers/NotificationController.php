<?php

namespace App\Http\Controllers;

use App\Services\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    private const SOUND_VARIANTS = ['default', 'soft', 'chime'];

    public function __construct(private readonly NotificationCenterService $notifications)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user, 403);

        return view('notifications.index', [
            'notificationsPage' => $this->notifications->paginateFor($user, 20),
            'notificationUnreadCount' => $this->notifications->unreadCountFor($user),
        ]);
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

    public function markRead(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        abort_unless($this->notifications->markAsRead($user, $notification), 404);

        if (!$request->expectsJson()) {
            return redirect($request->input('redirect_to', route('notifications.index')));
        }

        return response()->json([
            'success' => true,
            'unread_count' => $this->notifications->unreadCountFor($user),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $updated = $this->notifications->markAllAsRead($user);

        if (!$request->expectsJson()) {
            return redirect($request->input('redirect_to', route('notifications.index')));
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    public function markVisibleRead(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'notification_ids' => ['required', 'array', 'min:1'],
            'notification_ids.*' => ['required', 'string'],
        ]);

        $updated = $this->notifications->markManyAsRead($user, $validated['notification_ids']);

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'unread_count' => $this->notifications->unreadCountFor($user),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'sound_enabled' => ['nullable', 'boolean'],
            'voice_enabled' => ['nullable', 'boolean'],
            'sound_alerts_enabled' => ['nullable', 'boolean'],
            'voice_alerts_enabled' => ['nullable', 'boolean'],
            'sound_variant' => ['nullable', 'string', Rule::in(self::SOUND_VARIANTS)],
        ]);

        $soundEnabled = array_key_exists('sound_enabled', $validated)
            ? (bool) $validated['sound_enabled']
            : (array_key_exists('sound_alerts_enabled', $validated)
                ? (bool) $validated['sound_alerts_enabled']
                : (bool) $user->notification_sound_enabled);

        $voiceEnabled = array_key_exists('voice_enabled', $validated)
            ? (bool) $validated['voice_enabled']
            : (array_key_exists('voice_alerts_enabled', $validated)
                ? (bool) $validated['voice_alerts_enabled']
                : (bool) $user->notification_voice_enabled);

        $soundVariant = $this->normalizeSoundVariant($validated['sound_variant'] ?? $user->notification_sound_variant);

        $user->forceFill([
            'notification_sound_enabled' => $soundEnabled,
            'notification_voice_enabled' => $voiceEnabled,
            'notification_sound_variant' => $soundVariant,
        ])->save();

        return response()->json([
            'success' => true,
            'sound_enabled' => (bool) $user->notification_sound_enabled,
            'voice_enabled' => (bool) $user->notification_voice_enabled,
            'sound_alerts_enabled' => (bool) $user->notification_sound_enabled,
            'voice_alerts_enabled' => (bool) $user->notification_voice_enabled,
            'sound_variant' => $this->normalizeSoundVariant($user->notification_sound_variant),
        ]);
    }

    private function normalizeSoundVariant(?string $variant): string
    {
        $value = strtolower(trim((string) $variant));

        return in_array($value, self::SOUND_VARIANTS, true) ? $value : 'default';
    }
}
