<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OperationalInAppNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->payload['type'] ?? 'general',
            'title' => $this->payload['title'] ?? 'Operational update',
            'message' => $this->payload['message'] ?? 'A new operational update is available.',
            'action_url' => $this->payload['action_url'] ?? null,
            'priority' => $this->payload['priority'] ?? 'medium',
            'related_type' => $this->payload['related_type'] ?? null,
            'related_id' => $this->payload['related_id'] ?? null,
            'organization_id' => $this->payload['organization_id'] ?? null,
            'meta' => $this->payload['meta'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
