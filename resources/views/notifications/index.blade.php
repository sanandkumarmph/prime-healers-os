@extends('layouts.app')

@section('content')
<style>
    .notifications-page {
        display: grid;
        gap: 12px;
    }
    .notifications-summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }
    .notifications-panel-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
        flex-wrap: wrap;
    }
    .notifications-panel-head p {
        margin: 0;
        color: #64748b;
        font-size: 11.5px;
        line-height: 1.45;
        max-width: 620px;
    }
    .notifications-list {
        display: grid;
        gap: 8px;
    }
    .notifications-item {
        display: grid;
        gap: 8px;
        padding: 10px 11px;
        border-radius: 14px;
        border: 1px solid #dbe3ef;
        background: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }
    .notifications-item.is-unread {
        border-color: #bfdbfe;
        background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    }
    .notifications-item-head {
        display: grid;
        gap: 4px;
        min-width: 0;
    }
    .notifications-item-head strong {
        color: #0f172a;
        font-size: 13px;
        line-height: 1.35;
    }
    .notifications-item-head small {
        color: #64748b;
        font-size: 11px;
        line-height: 1.45;
    }
    .notifications-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .notifications-actions {
        display: flex;
        justify-content: flex-end;
        gap: 6px;
        flex-wrap: wrap;
    }
    @media (max-width: 767px) {
        .notifications-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .notifications-summary-grid > *:last-child {
            grid-column: span 2;
        }
        .notifications-panel-head {
            margin-bottom: 8px;
        }
        .notifications-panel-head p {
            font-size: 11px;
        }
        .notifications-actions {
            justify-content: stretch;
        }
        .notifications-actions > * {
            flex: 1 1 0;
        }
    }
</style>

<div class="notifications-page">
    <x-operational-page-header
        title="Notifications"
        subtitle="Assignments, follow-ups, and field updates for your account."
    />

    <div class="notifications-summary-grid">
        <x-summary-card
            title="Unread"
            :value="$notificationUnreadCount"
            tone="warning"
            caption="Needs action"
        />
        <x-summary-card
            title="Total"
            :value="$notificationsPage->total()"
            tone="neutral"
            caption="Stored alerts"
        />
        <x-summary-card
            title="Page Size"
            :value="$notificationsPage->perPage()"
            tone="neutral"
            caption="Items per page"
        />
    </div>

    <x-operational-card title="All Notifications">
        <div class="notifications-panel-head">
            <p>Open a notification to jump to the related record. Unread items stay highlighted.</p>
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
                    <article class="notifications-item {{ !empty($notification['is_unread']) ? 'is-unread' : '' }}">
                        <div class="notifications-item-head">
                            <strong>{{ $notification['title'] ?? 'Operational update' }}</strong>
                            <small>{{ $notification['message'] ?? 'Open for details.' }}</small>
                            <div class="notifications-meta">
                                <span class="topbar-bell-priority {{ in_array($priority, ['high', 'urgent'], true) ? 'is-high' : ($priority === 'medium' ? 'is-medium' : '') }}">
                                    {{ strtoupper($priority) }}
                                </span>
                                <span class="topbar-bell-time">{{ $notification['time_ago'] ?? '' }}</span>
                            </div>
                        </div>
                        <div class="notifications-actions">
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
