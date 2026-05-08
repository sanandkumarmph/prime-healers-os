@php
    $name = $name ?? 'country_code';
    $pickerId = $pickerId ?? uniqid('country_code_', false);
    $value = old($name, $value ?? \App\Support\PhoneNumber::DEFAULT_CODE);
    $options = $options ?? \App\Support\PhoneNumber::countryCodeOptions();
    $dividerColor = $dividerColor ?? '#cbd5e1';
    $width = $width ?? '84px';
    $menuWidth = $menuWidth ?? '420px';
@endphp

@once
    <style>
        .country-code-picker {
            position: relative;
            flex: 0 0 var(--country-code-width, 84px);
            min-width: var(--country-code-width, 84px);
            max-width: var(--country-code-width, 84px);
            border-right: 1px solid var(--country-code-divider, #cbd5e1);
            background: #ffffff;
            overflow: visible;
        }

        .country-code-picker__trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            padding: 10px 10px 10px 12px;
            border: none;
            background: transparent;
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
            outline: none;
            text-align: left;
            appearance: none;
            -webkit-appearance: none;
            box-shadow: none;
        }

        .country-code-picker__trigger:focus {
            box-shadow: inset 0 0 0 2px rgba(59, 130, 246, 0.12);
        }

        .country-code-picker__caret {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            color: #4f46e5;
            pointer-events: none;
            transition: transform 0.18s ease;
        }

        .country-code-picker.is-open .country-code-picker__caret {
            transform: translateY(-50%) rotate(180deg);
        }

        .country-code-picker__menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            width: min(var(--country-code-menu-width, 280px), 92vw);
            border: 1px solid #d7def0;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
            z-index: 2000;
            overflow: hidden;
        }

        .country-code-picker__menu[hidden] {
            display: none !important;
        }

        .country-code-picker__search-wrap {
            position: relative;
            padding: 10px 10px 8px;
            background: #ffffff;
        }

        .country-code-picker__search {
            width: 100%;
            padding: 11px 12px 11px 38px;
            border: 1px solid #bfd0ff;
            border-radius: 10px;
            font-size: 14px;
            color: #0f172a;
            outline: none;
            box-sizing: border-box;
        }

        .country-code-picker__search:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .country-code-picker__search-icon {
            position: absolute;
            top: 50%;
            left: 22px;
            width: 16px;
            height: 16px;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }

        .country-code-picker__options {
            max-height: 320px;
            overflow-y: auto;
            padding: 2px 6px 8px;
        }

        .country-code-picker__option {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 14px;
            padding: 11px 16px;
            border: none;
            background: #ffffff;
            color: #0f172a;
            text-align: left;
            cursor: pointer;
            border-radius: 10px;
            font-size: 15px;
        }

        .country-code-picker__option:hover,
        .country-code-picker__option.is-active {
            background: #4a86e8;
            color: #ffffff;
        }

        .country-code-picker__option-code {
            flex: 0 0 52px;
            font-weight: 700;
        }

        .country-code-picker__option-label {
            flex: 1 1 auto;
            color: #475569;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .country-code-picker__option:hover .country-code-picker__option-label,
        .country-code-picker__option.is-active .country-code-picker__option-label {
            color: #ffffff;
        }

        .country-code-picker__empty {
            padding: 12px;
            color: #64748b;
            font-size: 13px;
        }
    </style>
@endonce

<div
    class="country-code-picker"
    data-country-code-picker
    style="--country-code-divider: {{ $dividerColor }}; --country-code-width: {{ $width }}; --country-code-menu-width: {{ $menuWidth }};"
>
    <input
        type="hidden"
        id="{{ $pickerId }}"
        name="{{ $name }}"
        value="{{ $value }}"
        data-country-code-value
    >
    <button
        type="button"
        id="{{ $pickerId }}_trigger"
        class="country-code-picker__trigger"
        data-country-code-trigger
        aria-haspopup="listbox"
        aria-expanded="false"
    >
        <span data-country-code-label>{{ $value }}</span>
    <span class="country-code-picker__caret" aria-hidden="true">
        <svg viewBox="0 0 12 12" width="12" height="12" fill="none">
            <path d="M2.25 4.5L6 8.25L9.75 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </span>
    </button>

    <div class="country-code-picker__menu" data-country-code-menu hidden>
        <div class="country-code-picker__search-wrap">
            <span class="country-code-picker__search-icon" aria-hidden="true">
                <svg viewBox="0 0 16 16" width="16" height="16" fill="none">
                    <circle cx="7" cy="7" r="4.5" stroke="currentColor" stroke-width="1.4"></circle>
                    <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"></path>
                </svg>
            </span>
            <input
                type="text"
                class="country-code-picker__search"
                data-country-code-search
                placeholder="Search"
                autocomplete="off"
            >
        </div>
        <div class="country-code-picker__options" role="listbox">
            @foreach($options as $code => $label)
                @php($countryName = preg_replace('/\s*\(\+\d+\)\s*$/', '', $label))
                <button
                    type="button"
                    class="country-code-picker__option{{ $value === $code ? ' is-active' : '' }}"
                    data-country-code-option
                    data-value="{{ $code }}"
                    data-search="{{ strtolower($code . ' ' . $countryName) }}"
                >
                    <span class="country-code-picker__option-code">{{ $code }}</span>
                    <span class="country-code-picker__option-label">{{ $countryName }}</span>
                </button>
            @endforeach
            <div class="country-code-picker__empty" data-country-code-empty hidden>No matching country code.</div>
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            if (window.__countryCodePickerInitialized) {
                return;
            }

            window.__countryCodePickerInitialized = true;

            function closeAll(except) {
                document.querySelectorAll('[data-country-code-picker]').forEach(function (picker) {
                    if (picker === except) {
                        return;
                    }

                    const trigger = picker.querySelector('[data-country-code-trigger]');
                    const menu = picker.querySelector('[data-country-code-menu]');

                    if (menu) {
                        menu.hidden = true;
                    }

                    if (trigger) {
                        trigger.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            function bindPicker(picker) {
                if (!picker || picker.dataset.bound === 'true') {
                    return;
                }

                picker.dataset.bound = 'true';

                const trigger = picker.querySelector('[data-country-code-trigger]');
                const triggerLabel = picker.querySelector('[data-country-code-label]');
                const valueInput = picker.querySelector('[data-country-code-value]');
                const menu = picker.querySelector('[data-country-code-menu]');
                const search = picker.querySelector('[data-country-code-search]');
                const emptyState = picker.querySelector('[data-country-code-empty]');
                const options = Array.from(picker.querySelectorAll('[data-country-code-option]'));

                const syncActive = function () {
                    const currentValue = String(valueInput.value || '').trim();

                    if (triggerLabel) {
                        triggerLabel.textContent = currentValue || '{{ \App\Support\PhoneNumber::DEFAULT_CODE }}';
                    }

                    options.forEach(function (option) {
                        option.classList.toggle('is-active', option.dataset.value === currentValue);
                    });
                };

                const filterOptions = function (query) {
                    const normalized = String(query || '').trim().toLowerCase();
                    let visibleCount = 0;

                    options.forEach(function (option) {
                        const visible = normalized === '' || option.dataset.search.includes(normalized);
                        option.hidden = !visible;
                        if (visible) {
                            visibleCount += 1;
                        }
                    });

                    if (emptyState) {
                        emptyState.hidden = visibleCount !== 0;
                    }
                };

                const openMenu = function () {
                    closeAll(picker);
                    menu.hidden = false;
                    picker.classList.add('is-open');
                    trigger.setAttribute('aria-expanded', 'true');
                    syncActive();
                    filterOptions(search.value || '');
                    window.requestAnimationFrame(function () {
                        search.focus();
                        search.select();
                    });
                };

                const closeMenu = function () {
                    menu.hidden = true;
                    picker.classList.remove('is-open');
                    trigger.setAttribute('aria-expanded', 'false');
                };

                trigger.addEventListener('click', function () {
                    if (menu.hidden) {
                        openMenu();
                    } else {
                        closeMenu();
                    }
                });

                trigger.addEventListener('keydown', function (event) {
                    if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        openMenu();
                    }
                });

                search.addEventListener('input', function () {
                    filterOptions(search.value);
                });

                options.forEach(function (option) {
                    option.addEventListener('click', function () {
                        valueInput.value = option.dataset.value || '';
                        picker.classList.remove('is-open');
                        valueInput.dispatchEvent(new Event('input', { bubbles: true }));
                        valueInput.dispatchEvent(new Event('change', { bubbles: true }));
                        trigger.dispatchEvent(new Event('input', { bubbles: true }));
                        trigger.dispatchEvent(new Event('change', { bubbles: true }));
                        syncActive();
                        closeMenu();
                    });
                });

                valueInput.addEventListener('change', syncActive);
                syncActive();
            }

            document.addEventListener('click', function (event) {
                const picker = event.target.closest('[data-country-code-picker]');

                if (!picker) {
                    closeAll(null);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAll(null);
                }
            });

            const init = function () {
                document.querySelectorAll('[data-country-code-picker]').forEach(bindPicker);
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init, { once: true });
            } else {
                init();
            }
        })();
    </script>
@endonce
