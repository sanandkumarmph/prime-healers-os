@php
    $primaryActionCandidates = collect($actions ?? [])->filter(fn ($action) => filled($action['label'] ?? null))->values();
    $actions = $primaryActionCandidates->take(1)->values();
    $moreActions = $primaryActionCandidates->skip(1)
        ->merge(collect($moreActions ?? [])->filter(fn ($action) => filled($action['label'] ?? null)))
        ->values();
    $hasBar = $actions->isNotEmpty() || $moreActions->isNotEmpty();
    $label = $label ?? 'Mobile quick actions';
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

@if($hasBar)
    @once
        <style>
            .ph-mobile-action-bar,
            .ph-mobile-action-spacer {
                display:none;
            }

            @media (max-width: 767px) {
                .ph-mobile-action-spacer {
                    display:block;
                    height:102px;
                    pointer-events:none;
                }

                .ph-mobile-action-bar {
                    position:fixed;
                    left:12px;
                    right:12px;
                    bottom:88px;
                    z-index:906;
                    display:flex;
                    align-items:center;
                    gap:6px;
                    padding:6px;
                    border:1px solid #dbe3ef;
                    border-radius:18px;
                    background:rgba(255,255,255,.98);
                    box-shadow:0 16px 40px rgba(15,23,42,.18);
                    backdrop-filter:blur(14px);
                    max-width:calc(100vw - 24px);
                    padding-bottom:calc(4px + env(safe-area-inset-bottom, 0px));
                }

                .ph-mobile-action-bar > a,
                .ph-mobile-action-bar > form,
                .ph-mobile-action-bar > details,
                .ph-mobile-action-bar > button {
                    flex:1 1 0;
                    min-width:0;
                    margin:0;
                }

                .ph-mobile-action-bar details {
                    position:relative;
                }

                .ph-mobile-action-button {
                    width:100%;
                    min-height:40px;
                    display:inline-flex;
                    align-items:center;
                    justify-content:center;
                    gap:6px;
                    padding:8px 10px;
                    border-radius:11px;
                    border:1px solid #cbd5e1;
                    background:#fff;
                    color:#0f172a;
                    text-decoration:none;
                    font-size:12px;
                    font-weight:800;
                    font-family:inherit;
                    white-space:nowrap;
                    overflow:hidden;
                    text-overflow:ellipsis;
                    list-style:none;
                    cursor:pointer;
                }

                .ph-mobile-action-button.is-primary {
                    background:#0f172a;
                    color:#fff;
                    border-color:#0f172a;
                }

                .ph-mobile-action-button.is-danger {
                    background:#fff1f2;
                    color:#991b1b;
                    border-color:#fecaca;
                }

                .ph-mobile-action-menu summary::-webkit-details-marker {
                    display:none;
                }

                .ph-mobile-action-menu[open] .ph-mobile-action-button {
                    border-color:#93c5fd;
                    box-shadow:0 0 0 3px rgba(37,99,235,.10);
                }

                .ph-mobile-action-sheet {
                    position:absolute;
                    right:0;
                    bottom:calc(100% + 8px);
                    width:min(260px, calc(100vw - 24px));
                    max-height:min(60vh, 420px);
                    overflow:auto;
                    display:grid;
                    gap:6px;
                    padding:8px;
                    border:1px solid #dbe3ef;
                    border-radius:16px;
                    background:#fff;
                    box-shadow:0 20px 42px rgba(15,23,42,.18);
                }

                .ph-mobile-action-sheet a,
                .ph-mobile-action-sheet button {
                    width:100%;
                    min-height:36px;
                    display:flex;
                    align-items:center;
                    justify-content:flex-start;
                    gap:6px;
                    padding:8px 10px;
                    border-radius:11px;
                    border:1px solid #e2e8f0;
                    background:#fff;
                    color:#0f172a;
                    text-decoration:none;
                    font-size:13px;
                    font-weight:700;
                    font-family:inherit;
                    cursor:pointer;
                }

                .ph-mobile-action-sheet form {
                    margin:0;
                }

                .ph-mobile-action-sheet .ph-mobile-action-sheet-close {
                    justify-content:center;
                    background:#f8fafc;
                    color:#475569;
                }
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const menus = Array.from(document.querySelectorAll('[data-mobile-action-menu]'));

                if (!menus.length) {
                    return;
                }

                const closeMenu = (menu) => menu?.removeAttribute('open');

                document.addEventListener('click', (event) => {
                    menus.forEach((menu) => {
                        if (!menu.contains(event.target)) {
                            closeMenu(menu);
                        }
                    });

                    if (event.target instanceof HTMLElement && event.target.matches('[data-mobile-action-close]')) {
                        const menu = event.target.closest('[data-mobile-action-menu]');
                        closeMenu(menu);
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        menus.forEach(closeMenu);
                    }
                });
            });
        </script>
    @endonce

    <div class="ph-mobile-action-spacer" aria-hidden="true"></div>

    <div class="ph-mobile-action-bar" aria-label="{{ $label }}">
        @foreach($actions as $index => $action)
            @php
                $variantClasses = 'ph-mobile-action-button' . ($index === 0 ? ' is-primary' : '');
                $method = strtoupper((string) ($action['method'] ?? 'POST'));
            @endphp

            @if(($action['type'] ?? 'link') === 'form')
                <form action="{{ $action['action'] }}" method="{{ in_array($method, ['GET', 'POST'], true) ? $method : 'POST' }}" @if(filled($action['confirm'] ?? null)) onsubmit="return confirm('{{ e($action['confirm']) }}');" @endif>
                    @csrf
                    @if(!in_array($method, ['GET', 'POST'], true))
                        @method($method)
                    @endif
                    <button type="submit" class="{{ $variantClasses }}">{{ $action['label'] }}</button>
                </form>
            @elseif(($action['type'] ?? 'link') === 'button')
                <button type="{{ $action['button_type'] ?? 'button' }}" class="{{ $variantClasses }}" {!! $renderAttributes($action['attributes'] ?? []) !!}>{{ $action['label'] }}</button>
            @else
                <a href="{{ $action['href'] }}" class="{{ $variantClasses }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif>{!! e($action['label']) !!}</a>
            @endif
        @endforeach

        @if($moreActions->isNotEmpty())
            <details class="ph-mobile-action-menu" data-mobile-action-menu>
                <summary class="ph-mobile-action-button ph-mobile-action-button-more" aria-label="{{ $moreLabel }}">More</summary>
                <div class="ph-mobile-action-sheet" role="menu" aria-label="{{ $moreLabel }}">
                    @foreach($moreActions as $action)
                        @php
                            $method = strtoupper((string) ($action['method'] ?? 'POST'));
                            $classes = 'ph-mobile-action-sheet-item' . (!empty($action['danger']) ? ' is-danger' : '');
                        @endphp

                        @if(($action['type'] ?? 'link') === 'form')
                            <form action="{{ $action['action'] }}" method="{{ in_array($method, ['GET', 'POST'], true) ? $method : 'POST' }}" @if(filled($action['confirm'] ?? null)) onsubmit="return confirm('{{ e($action['confirm']) }}');" @endif>
                                @csrf
                                @if(!in_array($method, ['GET', 'POST'], true))
                                    @method($method)
                                @endif
                                <button type="submit" class="{{ $classes }}">{{ $action['label'] }}</button>
                            </form>
                        @elseif(($action['type'] ?? 'link') === 'button')
                            <button type="{{ $action['button_type'] ?? 'button' }}" class="{{ $classes }}" {!! $renderAttributes($action['attributes'] ?? []) !!}>{{ $action['label'] }}</button>
                        @else
                            <a href="{{ $action['href'] }}" class="{{ $classes }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif>{{ $action['label'] }}</a>
                        @endif
                    @endforeach

                    <button type="button" class="ph-mobile-action-sheet-close" data-mobile-action-close>Close</button>
                </div>
            </details>
        @endif
    </div>
@endif
