<div class="mobile-more-backdrop" data-mobile-more-backdrop aria-hidden="true">
    <section class="mobile-more-sheet" role="dialog" aria-modal="true" aria-label="More navigation">
        <div class="mobile-more-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <x-application-logo style="width:34px; height:auto;" />
                <div style="display:grid; gap:2px;">
                    <strong>Prime Healers OS</strong>
                    <span style="font-size:11px; color:#64748b;">Rental, sales, and care operations</span>
                </div>
            </div>
            <button type="button" class="mobile-icon-button" data-mobile-more-close aria-label="Close mobile menu">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12"></path><path d="M18 6 6 18"></path></svg>
            </button>
        </div>

        <div class="mobile-more-grid">
            @foreach(($mobileMoreItems ?? []) as $item)
                @continue(empty($item['visible']))

                @if(!empty($item['href']))
                    <a href="{{ $item['href'] }}" class="mobile-more-link {{ !empty($item['active']) ? 'is-active' : '' }}">
                        <span class="mobile-more-icon">{!! $navIcon($item['icon'] ?? 'settings') !!}</span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @else
                    <span class="mobile-more-link is-disabled">
                        <span class="mobile-more-icon">{!! $navIcon($item['icon'] ?? 'settings') !!}</span>
                        <span>{{ $item['label'] }}</span>
                    </span>
                @endif
            @endforeach
        </div>

        <div class="mobile-more-footer">
            <div class="mobile-more-user">
                <div class="mobile-more-user-avatar">{{ $userInitials ?? 'RX' }}</div>
                <div style="min-width:0;">
                    <strong>{{ $currentUser?->name ?: 'Prime Healers OS User' }}</strong>
                    <span>{{ $userRoleLabel ?? 'User' }}</span>
                </div>
            </div>

            <div class="mobile-more-actions">
                @if(!empty($profileHref))
                    <a href="{{ $profileHref }}" class="mobile-more-action">
                        <span class="mobile-more-icon" aria-hidden="true" style="color:#1d4ed8; background:#dbeafe; border-color:#93c5fd;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21a8 8 0 0 0-16 0"></path>
                                <circle cx="12" cy="8" r="4"></circle>
                            </svg>
                        </span>
                        <span>Profile</span>
                    </a>
                @endif

                @if(!empty($logoutHref))
                    <form method="POST" action="{{ $logoutHref }}" id="mobileLogoutForm">
                        @csrf
                        <button type="button" class="mobile-more-logout" onclick="document.getElementById('mobileLogoutForm')?.requestSubmit();">
                            <span class="mobile-more-icon" aria-hidden="true" style="color:#b91c1c; background:#fee2e2; border-color:#fca5a5;">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                    <path d="M16 17l5-5-5-5"></path>
                                    <path d="M21 12H9"></path>
                                </svg>
                            </span>
                            <span>Logout</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const backdrop = document.querySelector('[data-mobile-more-backdrop]');
        const openButtons = document.querySelectorAll('[data-mobile-more-open]');
        const closeButtons = document.querySelectorAll('[data-mobile-more-close]');
        const bodyLock = window.rentnexisModalLock;

        function openMoreMenu() {
            backdrop?.classList.add('is-open');
            backdrop?.setAttribute('aria-hidden', 'false');
            if (bodyLock && typeof bodyLock.lock === 'function') {
                bodyLock.lock();
            }
        }

        function closeMoreMenu() {
            backdrop?.classList.remove('is-open');
            backdrop?.setAttribute('aria-hidden', 'true');
            if (bodyLock && typeof bodyLock.unlock === 'function') {
                bodyLock.unlock();
            }
        }

        openButtons.forEach((button) => button.addEventListener('click', openMoreMenu));
        closeButtons.forEach((button) => button.addEventListener('click', closeMoreMenu));

        backdrop?.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                closeMoreMenu();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMoreMenu();
            }
        });
    });
</script>
