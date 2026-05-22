@php
    $title = collect($breadcrumbItems ?? [])->last()['label'] ?? 'Prime Healers OS';
    $subtitle = $currentUser?->organization?->name ?? 'Rental & inventory operations';
    $mobileBackCrumb = collect($breadcrumbItems ?? [])
        ->slice(0, -1)
        ->reverse()
        ->first(fn ($crumb) => !empty($crumb['href']) && ($crumb['label'] ?? null) !== 'Home');

    if (!$mobileBackCrumb) {
        $mobileBackCrumb = collect($breadcrumbItems ?? [])
            ->first(fn ($crumb) => !empty($crumb['href']));
    }
@endphp

<header class="mobile-topbar">
    <a href="{{ route('dashboard') }}" class="mobile-brand" aria-label="Prime Healers OS home">
        <span class="mobile-brand-badge" aria-hidden="true">
            <x-application-logo class="mobile-brand-logo" />
        </span>
        <span class="mobile-brand-copy">
            <strong>Prime Healers OS</strong>
            <small>Rental, sales, and care operations</small>
        </span>
    </a>
    <button type="button" class="mobile-topbar-search" aria-label="Search shell">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
        <span>{{ $title === 'Prime Healers OS' ? 'Search customers, rentals, invoices...' : $title }}</span>
    </button>
    <div class="mobile-topbar-actions">
        <details
            class="topbar-notification-menu topbar-bell-menu mobile-notification-menu"
            data-in-app-notifications
            @if($topbarNotificationsLatestHref) data-notifications-latest-url="{{ $topbarNotificationsLatestHref }}" @endif
            @if($topbarNotificationsUnreadCountHref) data-notifications-count-url="{{ $topbarNotificationsUnreadCountHref }}" @endif
            @if($topbarNotificationsReadAllHref) data-notifications-read-all-url="{{ $topbarNotificationsReadAllHref }}" @endif
            @if($topbarNotificationsReadVisibleHref) data-notifications-read-visible-url="{{ $topbarNotificationsReadVisibleHref }}" @endif
            @if($topbarNotificationsReadHrefTemplate) data-notifications-read-url-template="{{ $topbarNotificationsReadHrefTemplate }}" @endif
            @if($topbarNotificationsPreferencesHref) data-notifications-preferences-url="{{ $topbarNotificationsPreferencesHref }}" @endif
            data-notification-sound-enabled="{{ $notificationSoundEnabled ? 'true' : 'false' }}"
            data-notification-voice-enabled="{{ $notificationVoiceEnabled ? 'true' : 'false' }}"
            data-notification-sound-variant="{{ $notificationSoundVariant }}"
            data-notification-sound-src="{{ $notificationSoundAsset }}"
        >
            <summary class="topbar-bell-trigger mobile-notification-trigger" aria-label="Open notifications">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
                @if(($topbarNotificationCount ?? 0) > 0)
                    <span class="topbar-bell-badge" data-notification-badge>{{ $topbarNotificationCount > 99 ? '99+' : $topbarNotificationCount }}</span>
                @endif
            </summary>
            <div class="mobile-notification-backdrop" data-notification-backdrop aria-hidden="true"></div>
            <div class="topbar-bell-panel mobile-notification-panel" role="dialog" aria-modal="true" aria-label="Notifications">
                <div class="topbar-bell-head">
                    <div>
                        <strong>Notifications</strong>
                        <span data-notification-subtitle>{{ ($topbarNotificationCount ?? 0) > 0 ? 'Live operational updates for you' : 'No unread notifications right now' }}</span>
                    </div>
                    <div class="topbar-bell-head-actions">
                        @if(($topbarNotificationCount ?? 0) > 0)
                            <span class="rn-badge rn-badge-warning" data-notification-head-count>{{ $topbarNotificationCount }}</span>
                        @endif
                        <button type="button" class="mobile-icon-button mobile-notification-close" data-notification-close aria-label="Close notifications">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12"></path><path d="M18 6 6 18"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="mobile-notification-scroll">
                    @if(($topbarNotifications ?? collect())->isEmpty())
                        <div class="topbar-bell-empty" data-notification-empty>No unread notifications right now. New assignments and operational updates will appear here automatically.</div>
                    @else
                        <div class="topbar-bell-list" data-notification-list>
                            @foreach($topbarNotifications as $notification)
                                @php($priority = $notification['priority'] ?? 'medium')
                                <a
                                    href="{{ $notification['action_url'] ?? '#' }}"
                                    class="topbar-bell-item {{ !empty($notification['is_unread']) ? 'is-unread' : '' }}"
                                    data-notification-item
                                    data-notification-id="{{ $notification['id'] ?? '' }}"
                                    data-notification-priority="{{ $priority }}"
                                >
                                    <div>
                                        <strong>{{ $notification['title'] ?? 'Operational update' }}</strong>
                                        <small>{{ $notification['message'] ?? 'Open for details.' }}</small>
                                        <div class="topbar-bell-meta">
                                            <span class="topbar-bell-priority {{ in_array($priority, ['high', 'urgent'], true) ? 'is-high' : ($priority === 'medium' ? 'is-medium' : '') }}">{{ strtoupper($priority) }}</span>
                                            <span class="topbar-bell-time">{{ $notification['time_ago'] ?? '' }}</span>
                                        </div>
                                    </div>
                                    @if(!empty($notification['is_unread']))
                                        <span class="topbar-bell-count">New</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if(($topbarNotifications ?? collect())->isNotEmpty())
                        <div class="topbar-bell-empty" data-notification-empty hidden>No unread notifications right now. New assignments and operational updates will appear here automatically.</div>
                    @endif

                    <div class="topbar-bell-settings mobile-notification-settings">
                        <div class="topbar-bell-settings-head">
                            <div>
                                <strong>Alert settings</strong>
                                <span>Optional and user-controlled</span>
                            </div>
                        </div>
                        <div class="mobile-notification-helper">
                            Sound and voice alerts work while the app is open. Tap Test Sound once to enable browser audio.
                        </div>
                        <div class="topbar-bell-switches">
                            <label class="topbar-bell-switch">
                                <span class="topbar-bell-switch-copy">
                                    <strong>Sound alerts</strong>
                                    <span>Play a short sound for important new tasks.</span>
                                </span>
                                <span class="topbar-bell-toggle">
                                    <input type="checkbox" data-notification-sound-toggle {{ $notificationSoundEnabled ? 'checked' : '' }}>
                                    <span class="topbar-bell-toggle-track"></span>
                                </span>
                            </label>
                            <label class="topbar-bell-switch">
                                <span class="topbar-bell-switch-copy">
                                    <strong>Voice alerts</strong>
                                    <span>Speak short safe labels like "New pickup assigned".</span>
                                </span>
                                <span class="topbar-bell-toggle">
                                    <input type="checkbox" data-notification-voice-toggle {{ $notificationVoiceEnabled ? 'checked' : '' }}>
                                    <span class="topbar-bell-toggle-track"></span>
                                </span>
                            </label>
                        </div>
                        <div class="topbar-bell-tools">
                            <button type="button" class="topbar-bell-tool-button" data-notification-test-sound>Test Sound</button>
                            <button type="button" class="topbar-bell-tool-button" data-notification-test-voice>Test Voice</button>
                        </div>
                        <div class="topbar-bell-tone-row">
                            <label class="topbar-bell-tone-label">
                                <span>Alert tone</span>
                                <select class="topbar-bell-select" data-notification-sound-variant>
                                    @foreach($notificationSoundOptions as $option)
                                        <option value="{{ $option['value'] }}" @selected($notificationSoundVariant === $option['value'])>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <div class="topbar-bell-settings-hint" data-notification-settings-hint hidden></div>
                    </div>
                </div>

                <div class="mobile-notification-footer">
                    <button
                        type="button"
                        class="topbar-bell-mark-all"
                        data-notification-mark-all
                        @if(($topbarNotificationCount ?? 0) <= 0) hidden @endif
                    >Mark all as read</button>
                    @if(!empty($topbarNotificationsViewAllHref))
                        <div class="topbar-bell-footer">
                            <a href="{{ $topbarNotificationsViewAllHref }}">View all</a>
                        </div>
                    @else
                        <div></div>
                    @endif
                </div>
            </div>
        </details>
        <button type="button" class="mobile-icon-button" data-mobile-more-open aria-label="Open mobile menu">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="5" r="1.5"></circle><circle cx="12" cy="12" r="1.5"></circle><circle cx="12" cy="19" r="1.5"></circle></svg>
        </button>
    </div>
</header>

@if(!empty($mobileBackCrumb['href']) && !empty($mobileBackCrumb['label']) && $title !== ($mobileBackCrumb['label'] ?? null))
    <div class="mobile-back-row">
        <a href="{{ $mobileBackCrumb['href'] }}" class="mobile-back-link" aria-label="Back to {{ $mobileBackCrumb['label'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 18l-6-6 6-6"></path>
                <path d="M21 12H9"></path>
            </svg>
            <span>{{ $mobileBackCrumb['label'] }}</span>
        </a>
    </div>
@endif
