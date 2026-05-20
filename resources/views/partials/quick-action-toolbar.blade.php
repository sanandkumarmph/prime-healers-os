@php
    $primaryActions = collect($actions ?? [])->filter(fn ($action) => filled($action['label'] ?? null))->values();
    $secondaryActions = collect($moreActions ?? [])->filter(fn ($action) => filled($action['label'] ?? null))->values();
    $infoItems = collect($infoItems ?? [])->filter(fn ($item) => filled($item['label'] ?? null) && filled($item['value'] ?? null))->values();
    $toolbarLabel = $label ?? 'Quick actions';
    $moreLabel = $moreLabel ?? 'More actions';
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

@if($primaryActions->isNotEmpty() || $secondaryActions->isNotEmpty() || $infoItems->isNotEmpty())
    @once
        <style>
            .ph-quick-actions {
                display:grid;
                gap:12px;
                padding:14px 16px;
                border:1px solid #dbe3ef;
                border-radius:18px;
                background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
                box-shadow:0 12px 28px rgba(15,23,42,.05);
            }

            .ph-quick-actions-head {
                display:flex;
                justify-content:space-between;
                align-items:flex-start;
                gap:12px;
                flex-wrap:wrap;
            }

            .ph-quick-actions-title {
                display:grid;
                gap:4px;
            }

            .ph-quick-actions-title strong {
                color:#0f172a;
                font-size:16px;
                line-height:1.2;
            }

            .ph-quick-actions-title span {
                color:#64748b;
                font-size:12px;
                line-height:1.5;
            }

            .ph-quick-actions-info {
                display:grid;
                grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
                gap:10px;
            }

            .ph-quick-actions-info-card {
                display:grid;
                gap:5px;
                min-width:0;
                padding:11px 13px;
                border-radius:14px;
                border:1px solid #e2e8f0;
                background:#fff;
            }

            .ph-quick-actions-info-card span {
                color:#64748b;
                font-size:11px;
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:.05em;
            }

            .ph-quick-actions-info-card strong {
                color:#0f172a;
                font-size:13px;
                line-height:1.55;
                overflow-wrap:anywhere;
            }

            .ph-quick-actions-info-card a {
                color:#2563eb;
                font-size:12px;
                font-weight:800;
                text-decoration:none;
            }

            .ph-quick-actions-info-card a:hover {
                text-decoration:underline;
            }

            .ph-quick-actions-row {
                display:flex;
                gap:8px;
                flex-wrap:wrap;
                align-items:center;
            }

            .ph-quick-actions-row > * {
                margin:0;
            }

            .ph-quick-action-btn {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                gap:6px;
                min-height:40px;
                padding:9px 13px;
                border-radius:12px;
                border:1px solid #cbd5e1;
                background:#fff;
                color:#0f172a;
                text-decoration:none;
                font-size:13px;
                font-weight:800;
                font-family:inherit;
                cursor:pointer;
                white-space:nowrap;
            }

            .ph-quick-action-btn.is-primary {
                background:#0f172a;
                border-color:#0f172a;
                color:#fff;
                box-shadow:0 12px 24px rgba(15,23,42,.12);
            }

            .ph-quick-action-btn.is-accent {
                background:#eff6ff;
                border-color:#bfdbfe;
                color:#1d4ed8;
            }

            .ph-quick-action-btn.is-danger {
                background:#fff1f2;
                border-color:#fecaca;
                color:#b91c1c;
            }

            .ph-quick-action-menu {
                position:relative;
            }

            .ph-quick-action-menu summary {
                list-style:none;
            }

            .ph-quick-action-menu summary::-webkit-details-marker {
                display:none;
            }

            .ph-quick-action-menu[open] summary {
                background:#eff6ff;
                border-color:#bfdbfe;
                color:#1d4ed8;
            }

            .ph-quick-action-panel {
                position:absolute;
                right:0;
                top:calc(100% + 8px);
                width:min(280px, calc(100vw - 40px));
                display:grid;
                gap:6px;
                padding:10px;
                border-radius:16px;
                border:1px solid #dbe3ef;
                background:#fff;
                box-shadow:0 20px 42px rgba(15,23,42,.16);
                z-index:70;
            }

            .ph-quick-action-panel a,
            .ph-quick-action-panel button {
                width:100%;
                justify-content:flex-start;
                min-height:38px;
                padding:9px 11px;
                border-radius:12px;
                border:1px solid #e2e8f0;
                background:#fff;
                color:#334155;
                font-size:12px;
                font-weight:800;
                text-decoration:none;
                font-family:inherit;
                cursor:pointer;
            }

            .ph-quick-action-panel form {
                margin:0;
            }

            .ph-quick-action-panel .is-danger {
                background:#fff1f2;
                border-color:#fecaca;
                color:#b91c1c;
            }

            @media (max-width: 767px) {
                .ph-quick-actions {
                    display:none;
                }
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const menus = Array.from(document.querySelectorAll('[data-quick-action-menu]'));

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
                    menu.addEventListener('toggle', () => {
                        if (menu.open) {
                            closeMenus(menu);
                        }
                    });
                });

                document.addEventListener('click', (event) => {
                    if (!event.target.closest('[data-quick-action-menu]')) {
                        closeMenus();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeMenus();
                    }
                });
            });
        </script>
    @endonce

    <section class="ph-quick-actions" aria-label="{{ $toolbarLabel }}">
        <div class="ph-quick-actions-head">
            <div class="ph-quick-actions-title">
                <strong>{{ $toolbarLabel }}</strong>
                <span>Fast operational actions without leaving this page.</span>
            </div>
        </div>

        @if($infoItems->isNotEmpty())
            <div class="ph-quick-actions-info">
                @foreach($infoItems as $item)
                    <div class="ph-quick-actions-info-card">
                        <span>{{ $item['label'] }}</span>
                        <strong>{{ $item['value'] }}</strong>
                        @if(!empty($item['href']))
                            <a href="{{ $item['href'] }}" @if(!empty($item['target'])) target="{{ $item['target'] }}" @endif @if(!empty($item['rel'])) rel="{{ $item['rel'] }}" @endif>{{ $item['linkLabel'] ?? 'Open' }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="ph-quick-actions-row">
            @foreach($primaryActions as $index => $action)
                @php
                    $type = $action['type'] ?? 'link';
                    $method = strtoupper((string) ($action['method'] ?? 'POST'));
                    $buttonClasses = 'ph-quick-action-btn'
                        . ($index === 0 ? ' is-primary' : '')
                        . (!empty($action['accent']) ? ' is-accent' : '')
                        . (!empty($action['danger']) ? ' is-danger' : '');
                @endphp

                @if($type === 'form')
                    <form action="{{ $action['action'] }}" method="{{ in_array($method, ['GET', 'POST'], true) ? $method : 'POST' }}" @if(filled($action['confirm'] ?? null)) onsubmit="return confirm('{{ e($action['confirm']) }}');" @endif>
                        @csrf
                        @if(!in_array($method, ['GET', 'POST'], true))
                            @method($method)
                        @endif
                        <button type="submit" class="{{ $buttonClasses }}">{{ $action['label'] }}</button>
                    </form>
                @elseif($type === 'button')
                    <button type="{{ $action['button_type'] ?? 'button' }}" class="{{ $buttonClasses }}" {!! $renderAttributes($action['attributes'] ?? []) !!}>{{ $action['label'] }}</button>
                @else
                    <a href="{{ $action['href'] }}" class="{{ $buttonClasses }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif>{{ $action['label'] }}</a>
                @endif
            @endforeach

            @if($secondaryActions->isNotEmpty())
                <details class="ph-quick-action-menu" data-quick-action-menu>
                    <summary class="ph-quick-action-btn">{{ $moreLabel }}</summary>
                    <div class="ph-quick-action-panel">
                        @foreach($secondaryActions as $action)
                            @php
                                $type = $action['type'] ?? 'link';
                                $method = strtoupper((string) ($action['method'] ?? 'POST'));
                                $panelClasses = !empty($action['danger']) ? 'is-danger' : '';
                            @endphp

                            @if($type === 'form')
                                <form action="{{ $action['action'] }}" method="{{ in_array($method, ['GET', 'POST'], true) ? $method : 'POST' }}" @if(filled($action['confirm'] ?? null)) onsubmit="return confirm('{{ e($action['confirm']) }}');" @endif>
                                    @csrf
                                    @if(!in_array($method, ['GET', 'POST'], true))
                                        @method($method)
                                    @endif
                                    <button type="submit" class="{{ $panelClasses }}">{{ $action['label'] }}</button>
                                </form>
                            @elseif($type === 'button')
                                <button type="{{ $action['button_type'] ?? 'button' }}" class="{{ $panelClasses }}" {!! $renderAttributes($action['attributes'] ?? []) !!}>{{ $action['label'] }}</button>
                            @else
                                <a href="{{ $action['href'] }}" class="{{ $panelClasses }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif>{{ $action['label'] }}</a>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    </section>
@endif
