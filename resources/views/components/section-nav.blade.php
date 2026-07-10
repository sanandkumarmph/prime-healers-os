@props([
    'items' => [],
    'label' => 'Page sections',
])

@php
    $navItems = collect($items)
        ->filter(fn ($item) => filled($item['id'] ?? null) && filled($item['label'] ?? null))
        ->values();
@endphp

@if($navItems->isNotEmpty())
    <div {{ $attributes->class(['ph-section-nav-shell']) }} data-section-nav>
        <nav class="ph-section-nav" aria-label="{{ $label }}">
            @foreach($navItems as $item)
                <a
                    href="#{{ $item['id'] }}"
                    class="ph-section-nav-link{{ $loop->first ? ' is-active' : '' }}"
                    data-section-nav-link
                    data-target-id="{{ $item['id'] }}"
                    @if($loop->first) aria-current="true" @endif
                >
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
@endif

@once
    <style>
        .ph-section-nav-shell {
            --ph-section-nav-top: 96px;
            position: relative;
            top: auto;
            z-index: 1;
            margin: 0 0 14px;
            pointer-events: none;
        }
        .ph-section-nav-shell.ph-section-nav-static {
            position: static;
            top: auto;
            z-index: auto;
        }
        .ph-section-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px;
            overflow-x: auto;
            border: 1px solid rgba(203, 213, 225, 0.92);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.05);
            backdrop-filter: blur(16px);
            scrollbar-width: none;
            -ms-overflow-style: none;
            pointer-events: auto;
        }
        .ph-section-nav::-webkit-scrollbar {
            display: none;
        }
        .ph-section-nav-link {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 8px 12px;
            border-radius: 999px;
            color: #52657d;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
        }
        .ph-section-nav-link:hover {
            background: #eff6ff;
            color: #0f4f84;
        }
        .ph-section-nav-link:focus-visible {
            outline: 2px solid #1777BD;
            outline-offset: 2px;
        }
        .ph-section-nav-link.is-active {
            background: #1777BD;
            color: #fff;
            box-shadow: 0 8px 18px rgba(23, 119, 189, 0.18);
        }
        .section-nav-target {
            scroll-margin-top: calc(var(--ph-section-nav-top, 96px) + 64px);
        }
        @media (max-width: 1024px) {
            .ph-section-nav-shell {
                --ph-section-nav-top: 84px;
            }
            .ph-section-nav {
                padding: 6px;
            }
            .ph-section-nav-link {
                min-height: 32px;
                padding: 7px 11px;
                font-size: 12px;
            }
        }
        @media (max-width: 768px) {
            .ph-section-nav-shell {
                --ph-section-nav-top: 74px;
                position: relative;
                top: auto;
                z-index: 1;
                margin-bottom: 14px;
            }
            .ph-section-nav-shell.ph-section-nav-static-mobile {
                position: static;
                top: auto;
                z-index: auto;
            }
        }
    </style>
    <script>
        (function () {
            function initSectionNavs() {
                document.querySelectorAll('[data-section-nav]').forEach(function (nav) {
                    if (nav.dataset.sectionNavReady === 'true') {
                        return;
                    }

                    const links = Array.from(nav.querySelectorAll('[data-section-nav-link]'));
                    const targets = links.map(function (link) {
                        return document.getElementById(link.dataset.targetId || '');
                    }).filter(Boolean);

                    if (!links.length || !targets.length) {
                        return;
                    }

                    nav.dataset.sectionNavReady = 'true';

                    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                    function setActive(targetId) {
                        links.forEach(function (link) {
                            const isActive = link.dataset.targetId === targetId;
                            link.classList.toggle('is-active', isActive);

                            if (isActive) {
                                link.setAttribute('aria-current', 'true');
                            } else {
                                link.removeAttribute('aria-current');
                            }
                        });
                    }

                    links.forEach(function (link) {
                        link.addEventListener('click', function (event) {
                            const target = document.getElementById(link.dataset.targetId || '');

                            if (!target) {
                                return;
                            }

                            event.preventDefault();
                            target.scrollIntoView({
                                behavior: prefersReducedMotion ? 'auto' : 'smooth',
                                block: 'start',
                            });
                            setActive(target.id);

                            if (window.history?.replaceState) {
                                window.history.replaceState(null, '', '#' + target.id);
                            }
                        });
                    });

                    const initialTargetId = window.location.hash
                        ? window.location.hash.replace('#', '')
                        : (targets[0]?.id || null);

                    if (initialTargetId) {
                        setActive(initialTargetId);
                    }

                    const observer = new IntersectionObserver(function (entries) {
                        const visibleEntries = entries
                            .filter(function (entry) { return entry.isIntersecting; })
                            .sort(function (left, right) { return right.intersectionRatio - left.intersectionRatio; });

                        if (visibleEntries[0]) {
                            setActive(visibleEntries[0].target.id);
                        }
                    }, {
                        rootMargin: '-140px 0px -55% 0px',
                        threshold: [0.2, 0.45, 0.75],
                    });

                    targets.forEach(function (target) {
                        observer.observe(target);
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initSectionNavs, { once: true });
            } else {
                initSectionNavs();
            }
        })();
    </script>
@endonce
