@auth
    @php
        $applicationName = (string) config('version.application_name', config('app.name', 'Prime Healers OS'));
        $version = (string) config('version.version', 'v1.0.0-rc1');
        $build = (string) config('version.build', '20260707');
        $releaseDate = (string) config('version.release_date', 'Not available');
        $environmentRaw = (string) config('version.environment', config('app.env', 'local'));
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
        $gitCommit = config('version.git_commit') ?: 'Not available';
        $branch = config('version.branch') ?: 'Not available';
        $databaseDriver = (string) config('database.default', 'Not available');
        $appDebug = config('app.debug') ? 'true' : 'false';
        $appEnv = (string) config('app.env', 'production');
        $whatsNew = collect(config('version.whats_new', []))->filter()->values();
        $tooltip = "PHOS\n\nVersion:\n{$version}\n\nBuild:\n{$build}\n\nEnvironment:\n{$environmentLabel}\n\nClick for details.";
    @endphp

    <style>
        .phos-release-badge{position:fixed;right:12px;bottom:10px;z-index:2147483000;display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:7px;max-width:calc(100vw - 24px);padding:6px 10px;border:1px solid rgba(148,163,184,.36);border-radius:999px;background:rgba(255,255,255,.92);box-shadow:0 8px 24px rgba(15,23,42,.12);color:#64748b;font-size:10.5px;font-weight:800;line-height:1.12;text-align:left;cursor:pointer;backdrop-filter:blur(12px);transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
        .phos-release-badge:hover,.phos-release-badge:focus-visible{transform:translateY(-1px);border-color:rgba(79,70,229,.35);box-shadow:0 14px 32px rgba(15,23,42,.16);outline:0}
        .phos-release-badge__chip{display:inline-flex;align-items:center;gap:4px;justify-content:center;min-width:34px;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.05em}
        .phos-release-badge__chip::before{content:"";width:6px;height:6px;border-radius:999px;background:currentColor}
        .phos-release-badge.is-local .phos-release-badge__chip{color:#c2410c;background:#fff7ed}
        .phos-release-badge.is-uat .phos-release-badge__chip{color:#1d4ed8;background:#eff6ff}
        .phos-release-badge.is-production .phos-release-badge__chip{color:#15803d;background:#f0fdf4}
        .phos-release-badge__meta{display:grid;gap:1px;min-width:0}
        .phos-release-badge__version{color:#334155;font-weight:900;white-space:nowrap}
        .phos-release-badge__build{color:#94a3b8;font-size:10px;white-space:nowrap}
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
        @media (max-width:767px){.phos-release-badge{bottom:calc(86px + env(safe-area-inset-bottom));right:10px;padding:5px 8px;font-size:10px}.phos-release-badge__build{display:none}.phos-release-modal{padding:10px;align-items:end}.phos-release-card{width:100%;max-height:calc(100vh - 116px);margin-bottom:calc(80px + env(safe-area-inset-bottom));border-radius:16px;overflow-y:auto}.phos-release-row{grid-template-columns:105px minmax(0,1fr)}}
    </style>

    <button type="button" class="phos-release-badge is-{{ $environmentTone }}" data-phos-release-open aria-haspopup="dialog" aria-controls="phosReleaseModal" title="{{ $tooltip }}">
        <span class="phos-release-badge__chip">{{ $environmentLabel }}</span>
        <span class="phos-release-badge__meta">
            <span class="phos-release-badge__version">PHOS {{ $version }}</span>
            <span class="phos-release-badge__build">Build {{ $build }}</span>
        </span>
    </button>

    <div id="phosReleaseModal" class="phos-release-modal" data-phos-release-modal hidden role="dialog" aria-modal="true" aria-labelledby="phosReleaseTitle">
        <section class="phos-release-card" data-phos-release-panel tabindex="-1">
            <header class="phos-release-card__header">
                <div>
                    <h2 id="phosReleaseTitle" class="phos-release-card__title">{{ $applicationName }}</h2>
                    <p class="phos-release-card__subtitle">Build information</p>
                </div>
                <button type="button" class="phos-release-card__close" data-phos-release-close aria-label="Close build information">&times;</button>
            </header>
            <div class="phos-release-card__body">
                <div class="phos-release-grid">
                    <div class="phos-release-row"><span>Version</span><span>{{ $version }}</span></div>
                    <div class="phos-release-row"><span>Build</span><span>{{ $build }}</span></div>
                    <div class="phos-release-row"><span>Environment</span><span>{{ $environmentLabel }}</span></div>
                    <div class="phos-release-row"><span>Release Date</span><span>{{ $releaseDate }}</span></div>
                    <div class="phos-release-row"><span>Git Commit</span><span>{{ $gitCommit }}</span></div>
                    <div class="phos-release-row"><span>Branch</span><span>{{ $branch }}</span></div>
                    <div class="phos-release-row"><span>Laravel</span><span>{{ app()->version() }}</span></div>
                    <div class="phos-release-row"><span>PHP</span><span>{{ PHP_VERSION }}</span></div>
                    <div class="phos-release-row"><span>Database</span><span>{{ $databaseDriver }}</span></div>
                    <div class="phos-release-row"><span>APP_DEBUG</span><span>{{ $appDebug }}</span></div>
                    <div class="phos-release-row"><span>APP_ENV</span><span>{{ $appEnv }}</span></div>
                </div>
                <div class="phos-release-whats-new">
                    <h3>What's New</h3>
                    <ul>
                        @foreach($whatsNew as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const trigger = document.querySelector('[data-phos-release-open]');
            const modal = document.querySelector('[data-phos-release-modal]');
            const panel = modal?.querySelector('[data-phos-release-panel]');
            const closeButton = modal?.querySelector('[data-phos-release-close]');

            if (!trigger || !modal) {
                return;
            }

            const openReleaseModal = function () {
                modal.hidden = false;
                panel?.focus({ preventScroll: true });
            };

            const closeReleaseModal = function () {
                modal.hidden = true;
                trigger.focus({ preventScroll: true });
            };

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                modal.hidden ? openReleaseModal() : closeReleaseModal();
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
        });
    </script>
@endauth
