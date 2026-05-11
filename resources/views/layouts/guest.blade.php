<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Prime Healers OS') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/prime-healers-favicon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&family=manrope:600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            .auth-shell-body {
                margin: 0;
                min-height: 100vh;
                background: var(--ph-color-bg);
                color: var(--ph-color-text);
                font-family: var(--ph-font-body);
                overflow-x: hidden;
            }
            .auth-shell-stage {
                min-height: 100vh;
                background:
                    radial-gradient(circle at 14% 10%, rgba(255,255,255,0.10), transparent 24%),
                    linear-gradient(180deg, #263A8C 0%, #203178 52%, #17245F 100%);
            }
            .auth-shell-wrap {
                position: relative;
                max-width: 1200px;
                min-height: 100vh;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 32px 16px;
                box-sizing: border-box;
            }
            .auth-shell-grid {
                width: 100%;
                display: grid;
                gap: 24px;
                align-items: stretch;
                grid-template-columns: minmax(0, 1.1fr) minmax(420px, 468px);
            }
            .auth-hero-panel {
                display: flex;
                flex-direction: column;
                overflow: hidden;
                border-radius: 28px;
                border: 1px solid rgba(255,255,255,.12);
                background:
                    radial-gradient(circle at 14% 10%, rgba(255,255,255,.10), transparent 24%),
                    linear-gradient(180deg, rgba(38,58,140,.98) 0%, rgba(32,49,120,.98) 52%, rgba(23,36,95,.98) 100%);
                color: #fff;
                box-shadow: 0 24px 54px rgba(18,29,74,.18);
            }
            .auth-brand-head {
                padding: 48px 44px 34px;
            }
            .auth-brand-link {
                display: inline-grid;
                gap: 18px;
                text-decoration: none;
                color: #fff;
                min-width: 0;
            }
            .auth-brand-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 auto;
                width: fit-content;
                padding: 16px 18px;
                border-radius: 24px;
                border: 1px solid rgba(223,231,243,.92);
                background: rgba(255,255,255,.98);
                box-shadow:
                    0 16px 34px rgba(18,29,74,.16),
                    inset 0 1px 0 rgba(255,255,255,.88);
            }
            .auth-brand-link .auth-brand-logo {
                width: auto;
                height: auto;
                max-width: 182px;
                max-height: 58px;
                flex-basis: auto;
                object-fit: contain;
                filter: none;
            }
            .auth-brand-title {
                margin: 0;
                font-size: clamp(2rem, 4vw, 2.7rem);
                line-height: 0.97;
                font-weight: 800;
                letter-spacing: -0.045em;
                font-family: var(--ph-font-heading);
            }
            .auth-brand-copy {
                margin: 6px 0 0;
                max-width: 26rem;
                font-size: 14px;
                line-height: 1.6;
                color: rgba(255,255,255,.78);
            }
            .auth-hero-copy {
                flex: 1 1 auto;
                padding: 34px 44px 44px;
                border-top: 1px solid rgba(255,255,255,.10);
                color: #ffffff;
            }
            .auth-badge {
                display: inline-flex;
                align-items: center;
                padding: 6px 12px;
                border-radius: 999px;
                border: 1px solid rgba(255,255,255,.14);
                background: rgba(255,255,255,.10);
                color: rgba(255,255,255,.82);
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .16em;
            }
            .auth-hero-content {
                max-width: 540px;
                display: grid;
                gap: 16px;
            }
            .auth-hero-content h1 {
                margin: 0;
                font-size: clamp(2rem, 4vw, 2.55rem);
                line-height: 1.08;
                letter-spacing: -0.04em;
                font-weight: 800;
                color: #ffffff;
                font-family: var(--ph-font-heading);
            }
            .auth-hero-content p {
                margin: 0;
                font-size: 15px;
                line-height: 1.7;
                color: rgba(255,255,255,.78);
            }
            .auth-form-panel {
                overflow: hidden;
                border-radius: 28px;
                border: 1px solid var(--ph-color-border);
                background: #ffffff;
                box-shadow: 0 28px 56px rgba(18,29,74,.12);
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
            .auth-form-mobile-brand {
                display: none;
                padding: 28px 28px 0;
                border-bottom: 1px solid var(--ph-color-border);
            }
            .auth-form-mobile-brand .auth-brand-link {
                color: #0f172a;
                gap: 14px;
            }
            .auth-form-mobile-brand .auth-brand-badge {
                padding: 12px 14px;
                border-radius: 20px;
                border-color: rgba(194,208,222,.95);
                background: #ffffff;
                box-shadow: var(--ph-shadow-soft);
                backdrop-filter: none;
            }
            .auth-form-mobile-brand .auth-brand-link .login-logo {
                width: auto;
                height: auto;
                max-width: 156px;
                max-height: 48px;
                flex-basis: auto;
                object-fit: contain;
                filter: none;
            }
            .auth-form-mobile-brand .auth-brand-title {
                color: var(--ph-color-text);
                font-size: clamp(1.45rem, 4vw, 1.8rem);
                font-family: var(--ph-font-heading);
                line-height: 1;
            }
            .auth-form-mobile-brand .auth-brand-copy {
                color: var(--ph-color-text-soft);
                font-size: 12px;
                margin-top: 4px;
            }
            .auth-form-host {
                padding: 30px 40px 36px;
            }
            .auth-form-title {
                margin: 0 0 6px;
                font-family: var(--ph-font-heading);
                font-size: 1.6rem;
                line-height: 1.05;
                letter-spacing: -0.04em;
                color: var(--ph-color-text);
            }
            .auth-form-copy {
                margin: 0 0 20px;
                color: var(--ph-color-text-soft);
                font-size: 14px;
                line-height: 1.6;
            }
            .auth-form-host form {
                display: grid;
                gap: 16px;
            }
            .auth-form-host label {
                display: block;
                font-size: 11px;
                font-weight: 800;
                color: var(--ph-color-text-soft);
                letter-spacing: .08em;
                text-transform: uppercase;
                font-family: var(--ph-font-heading);
            }
            .auth-form-host input:not([type="checkbox"]) {
                width: 100%;
                min-height: 46px;
                margin-top: 6px;
                padding: 11px 13px;
                border: 1px solid var(--ph-color-border);
                border-radius: 14px;
                background: #fff;
                color: var(--ph-color-text);
                box-sizing: border-box;
                box-shadow: none;
            }
            .auth-form-host input:not([type="checkbox"]):focus {
                outline: none;
                border-color: rgba(23,119,189,.55);
                box-shadow: 0 0 0 4px rgba(23,119,189,.10);
            }
            .auth-form-host .text-sm.text-gray-600,
            .auth-form-host .text-sm.text-gray-700,
            .auth-form-host .text-sm.text-gray-400 {
                color: var(--ph-color-text-soft) !important;
            }
            .auth-form-host .font-medium.text-sm.text-green-600,
            .auth-form-host .font-medium.text-sm.text-green-400 {
                padding: 10px 12px;
                border: 1px solid rgba(14,159,75,.16);
                border-radius: 12px;
                background: var(--ph-color-success-soft);
                color: var(--ph-color-success) !important;
            }
            .auth-form-host .mt-2 {
                margin-top: 8px !important;
            }
            .auth-form-host .text-sm.text-red-600,
            .auth-form-host .text-sm.text-red-500,
            .auth-form-host .text-sm.text-red-400 {
                color: var(--ph-color-danger) !important;
            }
            .auth-form-host .block.mt-4,
            .auth-form-host .mt-4 {
                margin-top: 0 !important;
            }
            .auth-form-host .mb-4 {
                margin-bottom: 0 !important;
            }
            .auth-form-host .inline-flex.items-center {
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .auth-form-host input[type="checkbox"] {
                width: 16px;
                height: 16px;
                border-radius: 5px;
                border: 1px solid var(--ph-color-border-strong);
                accent-color: var(--ph-color-primary);
            }
            .auth-form-host .ms-2 {
                margin-inline-start: 0 !important;
            }
            .auth-form-host .ms-3 {
                margin-inline-start: 12px !important;
            }
            .auth-form-host .flex.items-center.justify-end {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                flex-wrap: wrap;
                gap: 12px;
            }
            .auth-form-host a {
                color: var(--ph-color-primary);
                text-decoration: none;
            }
            .auth-form-host a:hover {
                text-decoration: underline;
            }
            .auth-submit {
                width: 100%;
                min-height: 44px;
                justify-content: center;
                font-size: 12px;
                letter-spacing: .08em;
                text-transform: uppercase;
            }
            .auth-form-host button[type="submit"] {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 42px;
                padding: 10px 16px;
                border: 1px solid transparent;
                border-radius: 14px;
                background: var(--ph-color-primary);
                color: #fff;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .08em;
                cursor: pointer;
                box-shadow: 0 14px 28px rgba(23,119,189,.18);
            }
            .auth-form-host button[type="submit"]:hover {
                background: var(--ph-color-primary-strong);
            }
            @media (max-width: 1023px) {
                .auth-shell-grid {
                    grid-template-columns: 1fr;
                }
                .auth-hero-panel {
                    display: none;
                }
                .auth-form-mobile-brand {
                    display: block;
                }
                .auth-form-host {
                    padding: 24px;
                }
            }
            @media (max-width: 640px) {
                .auth-shell-wrap {
                    padding: 16px 12px;
                }
                .auth-shell-grid {
                    gap: 14px;
                }
                .auth-form-panel {
                    width: 100%;
                    max-width: 100%;
                    border-radius: 20px;
                }
                .auth-form-host {
                    padding: 18px 16px 20px;
                }
                .auth-form-mobile-brand {
                    display: block;
                    padding: 18px 16px 0;
                    border-bottom: 0;
                }
                .auth-brand-head {
                    padding: 36px 24px 24px;
                }
                .auth-brand-link {
                    gap: 14px;
                }
                .auth-brand-badge {
                    padding: 12px 14px;
                    border-radius: 20px;
                }
                .auth-form-mobile-brand .auth-brand-badge {
                    padding: 9px 11px;
                    border-radius: 16px;
                }
                .auth-form-mobile-brand .auth-brand-link .login-logo,
                .auth-form-mobile-brand .auth-brand-link img[src*="logo-"] {
                    width: auto !important;
                    height: auto !important;
                    max-width: 118px !important;
                    max-height: 36px !important;
                    object-fit: contain !important;
                    flex: 0 0 auto;
                }
                .auth-form-mobile-brand .auth-brand-title {
                    font-size: 1.35rem;
                }
                .auth-form-mobile-brand .auth-brand-copy {
                    margin-top: 4px;
                    font-size: 11px;
                }
                .auth-form-title {
                    font-size: 1.4rem;
                }
                .auth-form-host .flex.items-center.justify-end {
                    justify-content: stretch;
                }
                .auth-form-host button[type="submit"] {
                    width: 100%;
                }
                .auth-form-host .ms-3 {
                    margin-inline-start: 0 !important;
                }
                .auth-form-host a {
                    width: 100%;
                    text-align: left;
                }
            }
        </style>
    </head>
    <body class="auth-shell-body">
        <div class="auth-shell-stage">
            <div class="auth-shell-wrap">
                <div class="auth-shell-grid">
                    <section class="auth-hero-panel">
                        <div class="auth-brand-head">
                            <a href="/" class="auth-brand-link">
                                <span class="auth-brand-badge" aria-hidden="true">
                                    <x-application-logo class="auth-brand-logo" />
                                </span>
                                <div>
                                    <p class="auth-brand-title">Prime Healers OS</p>
                                    <p class="auth-brand-copy">Rental, sales, dispatch &amp; care operations</p>
                                </div>
                            </a>
                        </div>

                        <div class="auth-hero-copy">
                            <div class="auth-hero-content">
                                <span class="auth-badge">Internal Access</span>
                                <h1>Secure internal access for Prime Healers team.</h1>
                                <p>Sign in to manage rentals, sales, dispatch, inventory, invoicing, and care operations from one trusted daily workspace.</p>
                            </div>
                        </div>
                    </section>

                    <section class="auth-form-panel">
                        <div class="auth-form-mobile-brand">
                            <a href="/" class="auth-brand-link">
                                <span class="auth-brand-badge" aria-hidden="true">
                                    <x-application-logo class="login-logo" />
                                </span>
                                <div>
                                    <p class="auth-brand-title">Prime Healers OS</p>
                                    <p class="auth-brand-copy">Rental, sales, dispatch &amp; care operations</p>
                                </div>
                            </a>
                        </div>

                        <div class="auth-form-host">
                            {{ $slot }}
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </body>
</html>
