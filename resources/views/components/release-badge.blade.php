@auth
    @php
        $applicationName = (string) config('version.application_name', config('app.name', 'Prime Healers OS'));
        $version = (string) config('version.version', 'v1.0.0-rc1');
        $build = (string) config('version.build', '20260707');
        $releaseDate = (string) config('version.release_date', 'Not available');
        $environmentRaw = (string) config('app.env', 'local');
        $environmentKey = strtolower($environmentRaw);
        $environmentTone = match ($environmentKey) {
            'production', 'prod' => 'production',
            'uat', 'staging' => 'uat',
            default => 'local',
        };
        $environmentLabel = match ($environmentTone) {
            'production' => 'PRODUCTION',
            'uat' => 'UAT',
            default => 'LOCAL',
        };
        $isProductionRelease = $environmentTone === 'production';
        $isLocalRelease = $environmentTone === 'local';
        $gitCommit = config('version.git_commit');
        $branch = config('version.branch');
        $databaseDriver = (string) config('database.default', 'Not available');
        $appDebug = config('app.debug') ? 'true' : 'false';
        $appEnv = (string) config('app.env', 'production');
        $company = (string) config('version.company', 'Prime Healers');
        $supportEmail = config('version.support_email');
        $website = config('version.website');
        $whatsNew = collect(config('version.whats_new', []))->filter()->values();
        $badgeText = match ($environmentTone) {
            'production' => $version,
            'uat' => "{$version} • UAT",
            default => "LOCAL • {$version}",
        };
        $mobilePrimaryText = $isProductionRelease ? $version : $environmentLabel;
        $mobileSecondaryText = $isProductionRelease ? '' : $version;
        $tooltip = $isProductionRelease
            ? "PHOS\n\nVersion:\n{$version}\n\nClick for details."
            : "PHOS\n\nVersion:\n{$version}\n\nBuild:\n{$build}\n\nEnvironment:\n{$environmentLabel}\n\nClick for details.";
    @endphp
    <style>
        .phos-release-badge{position:fixed;right:8px;bottom:8px;z-index:2147483000;display:inline-flex;align-items:center;justify-content:center;max-width:90px;height:20px;min-width:36px;padding:0 6px;border:1px solid rgba(148,163,184,.3);border-radius:999px;background:rgba(255,255,255,.78);box-shadow:0 1px 2px rgba(15,23,42,.1);color:#64748b;font-size:9px;font-weight:850;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;opacity:.7;backdrop-filter:blur(8px);transition:opacity .16s ease,transform .16s ease,border-color .16s ease}
        .phos-release-badge:hover,.phos-release-badge:focus-visible{opacity:1;transform:translateY(-1px);border-color:rgba(79,70,229,.32);outline:0}
        .phos-release-badge.is-left{left:8px;right:auto}
        .phos-release-badge.is-local{color:#c2410c;background:rgba(255,247,237,.78)}
        .phos-release-badge.is-uat{color:#1d4ed8;background:rgba(239,246,255,.78)}
        .phos-release-badge.is-production{color:#15803d;background:rgba(240,253,244,.78)}
        .phos-release-badge__desktop{display:inline;min-width:0;overflow:hidden;text-overflow:ellipsis}
        .phos-release-badge__mobile{display:none}
        .phos-release-modal[hidden]{display:none!important}
        .phos-release-modal{position:fixed;inset:0;z-index:2147483001;display:grid;place-items:end;padding:16px;background:rgba(15,23,42,.18)}
        .phos-release-card{width:min(390px,calc(100vw - 32px));margin-bottom:30px;border:1px solid rgba(203,213,225,.9);border-radius:18px;background:rgba(255,255,255,.97);box-shadow:0 24px 60px rgba(15,23,42,.22);overflow:hidden;color:#0f172a;backdrop-filter:blur(14px)}
        .phos-release-card__header{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 14px;border-bottom:1px solid #e2e8f0}
        .phos-release-card__title{font-size:14px;font-weight:900;margin:0}
        .phos-release-card__subtitle{margin:3px 0 0;color:#64748b;font-size:11px;font-weight:800}
        .phos-release-card__close{width:30px;height:30px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#475569;font-size:16px;font-weight:900;cursor:pointer}
        .phos-release-card__body{display:grid;gap:12px;padding:12px 14px 14px}
        .phos-release-grid{display:grid;gap:7px}
        .phos-release-row{display:grid;grid-template-columns:118px minmax(0,1fr);gap:10px;align-items:start;font-size:12px;line-height:1.35}
        .phos-release-row span:first-child{color:#64748b;font-weight:850;text-transform:uppercase;font-size:10.5px;letter-spacing:.04em}
        .phos-release-row span:last-child{color:#0f172a;font-weight:800;overflow-wrap:anywhere}
        .phos-release-whats-new{border-top:1px solid #e2e8f0;padding-top:10px}
        .phos-release-whats-new h3{margin:0 0 8px;font-size:12px;font-weight:950;color:#0f172a}
        .phos-release-whats-new ul{display:grid;gap:5px;margin:0;padding:0;list-style:none}
        .phos-release-whats-new li{display:flex;align-items:center;gap:7px;color:#475569;font-size:11.5px;font-weight:800}
        .phos-release-whats-new li::before{content:"";width:5px;height:5px;border-radius:999px;background:#4f46e5;flex:0 0 auto}
        @media (max-width:767px){
            .phos-release-badge{right:8px;bottom:calc(88px + env(safe-area-inset-bottom, 0px));width:auto;max-width:70px;height:18px;min-width:36px;padding:0 5px;font-size:8px;opacity:.55;box-shadow:0 1px 2px rgba(15,23,42,.08)}
            .phos-release-badge.is-left{left:8px;right:auto}
            .phos-release-badge.is-hidden-mobile{opacity:0;visibility:hidden;pointer-events:none;transform:translateY(4px)}
            .phos-release-badge__desktop{display:none}
            .phos-release-badge__mobile{display:inline-flex;align-items:center;gap:3px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .phos-release-badge__mobile small{font-size:8px;font-weight:850;opacity:.86;min-width:0;overflow:hidden;text-overflow:ellipsis}
            .phos-release-modal{padding:10px;align-items:end}.phos-release-card{width:100%;max-height:calc(100vh - 132px);margin-bottom:calc(90px + env(safe-area-inset-bottom, 0px));border-radius:16px;overflow-y:auto}.phos-release-row{grid-template-columns:105px minmax(0,1fr)}
        }
    </style>

    <button type="button" class="phos-release-badge is-{{ $environmentTone }}" data-phos-release-open aria-haspopup="dialog" aria-controls="phosReleaseModal" title="{{ $tooltip }}">
        <span class="phos-release-badge__desktop">{{ $badgeText }}</span>
        <span class="phos-release-badge__mobile">
            <span>{{ $mobilePrimaryText }}</span>
            @if($mobileSecondaryText !== '')
                <small>{{ $mobileSecondaryText }}</small>
            @endif
        </span>
    </button>

    <div id="phosReleaseModal" class="phos-release-modal" data-phos-release-modal hidden role="dialog" aria-modal="true" aria-labelledby="phosReleaseTitle">
        <section class="phos-release-card" data-phos-release-panel tabindex="-1">
            <header class="phos-release-card__header">
                <div>
                    <h2 id="phosReleaseTitle" class="phos-release-card__title">{{ $applicationName }}</h2>
                    <p class="phos-release-card__subtitle">{{ $isProductionRelease ? 'Release information' : 'Build information' }}</p>
                </div>
                <button type="button" class="phos-release-card__close" data-phos-release-close aria-label="Close build information">&times;</button>
            </header>
            <div class="phos-release-card__body">
                <div class="phos-release-grid">
                    <div class="phos-release-row"><span>Version</span><span>{{ $version }}</span></div>
                    <div class="phos-release-row"><span>Release Date</span><span>{{ $releaseDate }}</span></div>
                    @if($isProductionRelease)
                        <div class="phos-release-row"><span>Company</span><span>{{ $company }}</span></div>
                        <div class="phos-release-row"><span>Copyright</span><span>&copy; {{ now()->year }} {{ $company }}. All rights reserved.</span></div>
                        @if($supportEmail)
                            <div class="phos-release-row"><span>Support</span><span>{{ $supportEmail }}</span></div>
                        @endif
                        @if($website)
                            <div class="phos-release-row"><span>Website</span><span>{{ $website }}</span></div>
                        @endif
                    @else
                        <div class="phos-release-row"><span>Build</span><span>{{ $build }}</span></div>
                        <div class="phos-release-row"><span>Environment</span><span>{{ $environmentLabel }}</span></div>
                        @if($gitCommit)
                            <div class="phos-release-row"><span>Git Commit</span><span>{{ $gitCommit }}</span></div>
                        @endif
                        @if($branch)
                            <div class="phos-release-row"><span>Branch</span><span>{{ $branch }}</span></div>
                        @endif
                        @if($isLocalRelease)
                            <div class="phos-release-row"><span>Laravel</span><span>{{ app()->version() }}</span></div>
                            <div class="phos-release-row"><span>PHP</span><span>{{ PHP_VERSION }}</span></div>
                            <div class="phos-release-row"><span>Database</span><span>{{ $databaseDriver }}</span></div>
                            <div class="phos-release-row"><span>APP_ENV</span><span>{{ $appEnv }}</span></div>
                            <div class="phos-release-row"><span>APP_DEBUG</span><span>{{ $appDebug }}</span></div>
                        @endif
                    @endif
                </div>
                @unless($isProductionRelease)
                    <div class="phos-release-whats-new">
                        <h3>What's New</h3>
                        <ul>
                            @foreach($whatsNew as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endunless
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const badge = document.querySelector('.phos-release-badge');
            const triggers = Array.from(document.querySelectorAll('[data-phos-release-open]'));
            const modal = document.querySelector('[data-phos-release-modal]');
            const panel = modal?.querySelector('[data-phos-release-panel]');
            const closeButton = modal?.querySelector('[data-phos-release-close]');
            let lastTrigger = triggers[0] || null;
            let hideTimer = null;
            let pressTimer = null;

            if (!triggers.length || !modal || !badge) {
                return;
            }

            const isMobile = function () {
                return window.matchMedia('(max-width: 767px)').matches;
            };

            const showMobileBadge = function () {
                if (!isMobile()) return;
                badge.classList.remove('is-hidden-mobile');
                window.clearTimeout(hideTimer);
                hideTimer = window.setTimeout(function () {
                    if (modal.hidden) {
                        badge.classList.add('is-hidden-mobile');
                    }
                }, 5000);
            };

            const avoidOverlap = function () {
                badge.classList.remove('is-left');
                const badgeRect = badge.getBoundingClientRect();
                const selectors = ['.mobile-fab','.dashboard-mobile-fab','.mobile-sticky-actions','.mobile-bottom-nav','.floating-action-button','.fab','[class*="sticky"][class*="action"]'];
                const collides = selectors.some(function (selector) {
                    return Array.from(document.querySelectorAll(selector)).some(function (node) {
                        if (node === badge || node.offsetParent === null) return false;
                        const rect = node.getBoundingClientRect();
                        return !(badgeRect.right < rect.left || badgeRect.left > rect.right || badgeRect.bottom < rect.top || badgeRect.top > rect.bottom);
                    });
                });
                if (collides) {
                    badge.classList.add('is-left');
                }
            };

            const openReleaseModal = function (trigger) {
                lastTrigger = trigger || lastTrigger;
                badge.classList.remove('is-hidden-mobile');
                window.clearTimeout(hideTimer);
                modal.hidden = false;
                panel?.focus({ preventScroll: true });
            };

            const closeReleaseModal = function () {
                modal.hidden = true;
                lastTrigger?.focus({ preventScroll: true });
                showMobileBadge();
            };

            triggers.forEach(function (trigger) {
                trigger.addEventListener('click', function (event) {
                    event.preventDefault();
                    modal.hidden ? openReleaseModal(trigger) : closeReleaseModal();
                });
            });

            closeButton?.addEventListener('click', function (event) {
                event.preventDefault();
                closeReleaseModal();
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeReleaseModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeReleaseModal();
                }
            });

            const logoCandidates = Array.from(document.querySelectorAll('img[alt*="Prime"], img[alt*="PHOS"], .brand-logo, .app-logo, [data-phos-logo]'));
            logoCandidates.forEach(function (logo) {
                logo.addEventListener('pointerdown', function () {
                    window.clearTimeout(pressTimer);
                    pressTimer = window.setTimeout(showMobileBadge, 650);
                });
                ['pointerup','pointerleave','pointercancel'].forEach(function (eventName) {
                    logo.addEventListener(eventName, function () { window.clearTimeout(pressTimer); });
                });
            });

            showMobileBadge();
            window.setTimeout(avoidOverlap, 250);
            window.addEventListener('resize', avoidOverlap, { passive: true });
            window.addEventListener('scroll', avoidOverlap, { passive: true });
        });
    </script>
@endauth
