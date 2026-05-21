@props([
    'actions' => [],
    'label' => 'More',
])

@php
    $menuActions = collect($actions)->filter(fn ($action) => filled($action['label'] ?? null))->values();
    $renderAttributes = function (array $attributes = []): string {
        return collect($attributes)
            ->map(function ($value, $key) {
                if (is_bool($value)) {
                    return $value ? $key : null;
                }

                if ($value === null) {
                    return null;
                }

                return sprintf('%s="%s"', $key, e((string) $value));
            })
            ->filter()
            ->implode(' ');
    };
@endphp

@if($menuActions->isNotEmpty())
    <details {{ $attributes->class(['ph-action-dropdown']) }} data-ph-action-dropdown>
        <summary class="ph-action-dropdown__trigger">{{ $label }}</summary>
        <div class="ph-action-dropdown__panel">
            @foreach($menuActions as $action)
                @if(($action['type'] ?? 'link') === 'form')
                    <form action="{{ $action['action'] }}" method="{{ strtoupper($action['method'] ?? 'POST') === 'GET' ? 'GET' : 'POST' }}">
                        @if(strtoupper($action['method'] ?? 'POST') !== 'GET')
                            @csrf
                            @if(!in_array(strtoupper($action['method'] ?? 'POST'), ['POST'], true))
                                @method($action['method'])
                            @endif
                        @endif
                        <button type="submit" class="ph-action-dropdown__item{{ !empty($action['danger']) ? ' is-danger' : '' }}">
                            {{ $action['label'] }}
                        </button>
                    </form>
                @elseif(($action['type'] ?? 'link') === 'button')
                    <button type="button" class="ph-action-dropdown__item{{ !empty($action['danger']) ? ' is-danger' : '' }}" {!! $renderAttributes($action['attributes'] ?? []) !!}>
                        {{ $action['label'] }}
                    </button>
                @else
                    <a
                        href="{{ $action['href'] ?? '#' }}"
                        class="ph-action-dropdown__item{{ !empty($action['danger']) ? ' is-danger' : '' }}"
                        @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif
                        @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif
                    >
                        {{ $action['label'] }}
                    </a>
                @endif
            @endforeach
        </div>
    </details>
@endif

@once
    <style>
        .ph-action-dropdown {
            position: relative;
        }
        .ph-action-dropdown summary {
            list-style: none;
        }
        .ph-action-dropdown summary::-webkit-details-marker {
            display: none;
        }
        .ph-action-dropdown__trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 9px 13px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }
        .ph-action-dropdown[open] .ph-action-dropdown__trigger {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .ph-action-dropdown__panel {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            z-index: 90;
            width: min(280px, calc(100vw - 32px));
            display: grid;
            gap: 6px;
            padding: 10px;
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 42px rgba(15, 23, 42, 0.16);
        }
        .ph-action-dropdown__panel form {
            margin: 0;
        }
        .ph-action-dropdown__item {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            width: 100%;
            min-height: 38px;
            padding: 9px 11px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            color: #334155;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
            font-family: inherit;
            cursor: pointer;
        }
        .ph-action-dropdown__item.is-danger {
            background: #fff1f2;
            border-color: #fecaca;
            color: #b91c1c;
        }
    </style>
    <script>
        (function () {
            function initPhActionDropdowns() {
                const menus = Array.from(document.querySelectorAll('[data-ph-action-dropdown]'));
                if (!menus.length) {
                    return;
                }

                const closeMenus = (except = null) => {
                    menus.forEach((menu) => {
                        if (menu !== except) {
                            menu.removeAttribute('open');
                        }
                    });
                };

                menus.forEach((menu) => {
                    if (menu.dataset.dropdownReady === 'true') {
                        return;
                    }

                    menu.dataset.dropdownReady = 'true';
                    menu.addEventListener('toggle', () => {
                        if (menu.open) {
                            closeMenus(menu);
                        }
                    });
                });

                document.addEventListener('click', (event) => {
                    if (!event.target.closest('[data-ph-action-dropdown]')) {
                        closeMenus();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeMenus();
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPhActionDropdowns, { once: true });
            } else {
                initPhActionDropdowns();
            }
        })();
    </script>
@endonce
