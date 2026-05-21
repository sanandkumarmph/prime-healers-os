@extends('layouts.app')

@section('content')
<div class="space-y-5">
    <x-operational-page-header
        title="Notifications"
        subtitle="Assignments, follow-ups, and operational alerts for your account."
    />

    <div class="dashboard-grid dashboard-grid-3">
        <x-summary-card
            title="Unread"
            :value="$notificationUnreadCount"
            tone="warning"
            caption="Needs attention"
        />
        <x-summary-card
            title="Total"
            :value="$notificationsPage->total()"
            tone="neutral"
            caption="Stored notifications"
        />
        <x-summary-card
            title="Page Size"
            :value="$notificationsPage->perPage()"
            tone="neutral"
            caption="Latest items shown per page"
        />
    </div>

    <x-operational-card title="All Notifications">
        <div class="rn-actions-row" style="justify-content:space-between; gap:.75rem; margin-bottom:1rem;">
            <p class="rn-muted" style="margin:0;">Open a notification to go to the related record. Unread items are highlighted.</p>
            @if($notificationUnreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ route('notifications.index') }}">
                    <button type="submit" class="rn-btn rn-btn-secondary">Mark all as read</button>
                </form>
            @endif
        </div>

        @if($notificationsPage->count() === 0)
            <x-empty-state
                title="No notifications yet"
                message="New task assignments, renewals, payment updates, and operational alerts will appear here."
            />
        @else
            <div class="topbar-bell-list topbar-bell-list-page">
                @foreach($notificationsPage as $notification)
                    @php
                        $priority = strtolower((string) ($notification['priority'] ?? 'medium'));
                        $actionUrl = $notification['action_url'] ?: route('notifications.index');
                    @endphp
                    <article class="topbar-bell-item {{ !empty($notification['is_unread']) ? 'is-unread' : '' }}">
                        <div>
                            <strong>{{ $notification['title'] ?? 'Operational update' }}</strong>
                            <small>{{ $notification['message'] ?? 'Open for details.' }}</small>
                            <div class="topbar-bell-meta">
                                <span class="topbar-bell-priority {{ in_array($priority, ['high', 'urgent'], true) ? 'is-high' : ($priority === 'medium' ? 'is-medium' : '') }}">
                                    {{ strtoupper($priority) }}
                                </span>
                                <span class="topbar-bell-time">{{ $notification['time_ago'] ?? '' }}</span>
                            </div>
                        </div>
                        <div class="rn-actions-row" style="justify-content:flex-end; gap:.5rem; flex-wrap:wrap;">
                            @if(!empty($notification['is_unread']))
                                <form method="POST" action="{{ route('notifications.read', $notification['id']) }}">
                                    @csrf
                                    <input type="hidden" name="redirect_to" value="{{ route('notifications.index') }}">
                                    <button type="submit" class="rn-btn rn-btn-secondary" style="padding:.48rem .75rem;">Mark read</button>
                                </form>
                                <form method="POST" action="{{ route('notifications.read', $notification['id']) }}">
                                    @csrf
                                    <input type="hidden" name="redirect_to" value="{{ $actionUrl }}">
                                    <button type="submit" class="rn-btn rn-btn-primary" style="padding:.48rem .75rem;">Open</button>
                                </form>
                            @else
                                <a href="{{ $actionUrl }}" class="rn-btn rn-btn-primary" style="padding:.48rem .75rem;">Open</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $notificationsPage->links() }}
            </div>
        @endif
    </x-operational-card>
</div>
@endsection
