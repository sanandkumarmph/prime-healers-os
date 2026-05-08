<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Rentnexis') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('images/rentnexis-favicon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/rentnexis-favicon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            .auth-shell-body {
                margin: 0;
                min-height: 100vh;
                background: #f8fafc;
                color: #0f172a;
                font-family: Inter, "Segoe UI", Arial, sans-serif;
                overflow-x: hidden;
            }
            .auth-shell-stage {
                min-height: 100vh;
                background:
                    radial-gradient(circle at top left, rgba(37,99,235,0.18), transparent 38%),
                    radial-gradient(circle at top right, rgba(56,189,248,0.16), transparent 34%),
                    linear-gradient(180deg, #0b1220 0%, #06142e 58%, #0f172a 100%);
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
                grid-template-columns: minmax(0, 1.22fr) minmax(420px, 1fr);
            }
            .auth-hero-panel {
                display: flex;
                flex-direction: column;
                overflow: hidden;
                border-radius: 28px;
                border: 1px solid rgba(255,255,255,.10);
                background: linear-gradient(180deg, rgba(11,18,32,.98) 0%, rgba(6,20,46,.98) 100%);
                color: #fff;
                box-shadow: 0 24px 60px rgba(2,6,23,.28);
            }
            .auth-brand-head {
                padding: 40px 44px;
            }
            .auth-brand-link {
                display: inline-flex;
                align-items: center;
                gap: 16px;
                text-decoration: none;
                color: #fff;
            }
            .auth-brand-link .rn-brand-logo {
                width: 56px;
                height: 56px;
                max-width: 56px;
                max-height: 56px;
                flex-basis: 56px;
                filter: drop-shadow(0 10px 30px rgba(56,189,248,0.25));
            }
            .auth-brand-title {
                margin: 0;
                font-size: 2rem;
                line-height: 1;
                font-weight: 700;
                letter-spacing: -0.04em;
            }
            .auth-brand-copy {
                margin: 8px 0 0;
                font-size: 14px;
                color: #cbd5e1;
            }
            .auth-hero-copy {
                flex: 1 1 auto;
                padding: 44px;
                border-top: 1px solid rgba(255,255,255,.08);
                background: #ffffff;
                color: #0f172a;
            }
            .auth-badge {
                display: inline-flex;
                align-items: center;
                padding: 6px 12px;
                border-radius: 999px;
                border: 1px solid #e2e8f0;
                background: #f8fafc;
                color: #64748b;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .16em;
            }
            .auth-hero-content {
                max-width: 560px;
                display: grid;
                gap: 18px;
            }
            .auth-hero-content h1 {
                margin: 0;
                font-size: 2.15rem;
                line-height: 1.15;
                letter-spacing: -0.04em;
                font-weight: 700;
                color: #0f172a;
            }
            .auth-hero-content p {
                margin: 0;
                font-size: 15px;
                line-height: 1.7;
                color: #475569;
            }
            .auth-form-panel {
                overflow: hidden;
                border-radius: 28px;
                border: 1px solid #e2e8f0;
                background: #ffffff;
                box-shadow: 0 30px 80px rgba(15,23,42,.12);
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
            .auth-form-mobile-brand {
                display: none;
                padding: 24px 24px 0;
                border-bottom: 1px solid #e2e8f0;
            }
            .auth-form-mobile-brand .auth-brand-link {
                color: #0f172a;
            }
            .auth-form-mobile-brand .auth-brand-link .rn-brand-logo {
                width: 48px;
                height: 48px;
                max-width: 48px;
                max-height: 48px;
                flex-basis: 48px;
                filter: none;
            }
            .auth-form-mobile-brand .auth-brand-copy {
                color: #64748b;
                font-size: 12px;
            }
            .auth-form-host {
                padding: 32px 40px;
            }
            .auth-form-host form {
                display: grid;
                gap: 16px;
            }
            .auth-form-host label {
                display: block;
                font-size: 14px;
                font-weight: 600;
                color: #334155;
            }
            .auth-form-host input:not([type="checkbox"]) {
                width: 100%;
                min-height: 44px;
                margin-top: 6px;
                padding: 10px 12px;
                border: 1px solid #cbd5e1;
                border-radius: 10px;
                background: #fff;
                color: #0f172a;
                box-sizing: border-box;
            }
            .auth-form-host input:not([type="checkbox"]):focus {
                outline: none;
                border-color: #60a5fa;
                box-shadow: 0 0 0 4px rgba(37,99,235,.10);
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
                color: #2563eb;
                text-decoration: none;
            }
            .auth-form-host a:hover {
                text-decoration: underline;
            }
            .auth-form-host button[type="submit"] {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 42px;
                padding: 10px 16px;
                border: 1px solid transparent;
                border-radius: 10px;
                background: #0f172a;
                color: #fff;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .08em;
                cursor: pointer;
            }
            .auth-form-host button[type="submit"]:hover {
                background: #1e293b;
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
                .auth-form-mobile-brand .auth-brand-link {
                    gap: 10px;
                }
                .auth-form-mobile-brand .auth-brand-link .rn-brand-logo,
                .auth-form-mobile-brand .auth-brand-link img[src*="logo-rentnexis"] {
                    width: auto !important;
                    height: auto !important;
                    max-width: 180px !important;
                    max-height: 64px !important;
                    object-fit: contain !important;
                    flex: 0 0 auto;
                }
                .auth-form-mobile-brand .auth-brand-title {
                    font-size: 1.25rem;
                }
                .auth-form-mobile-brand .auth-brand-copy {
                    margin-top: 4px;
                    font-size: 11px;
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
                                <x-application-logo class="rn-brand-logo" style="width:56px;height:auto;max-width:220px;margin:0;filter:drop-shadow(0 10px 30px rgba(56,189,248,0.25));" />
                                <div>
                                    <p class="auth-brand-title">Rentnexis</p>
                                    <p class="auth-brand-copy">Smarter Rental Operations</p>
                                </div>
                            </a>
                        </div>

                        <div class="auth-hero-copy">
                            <div class="auth-hero-content">
                                <span class="auth-badge">Daily Operations Platform</span>
                                <h1>Run your rental business from one calm workspace.</h1>
                                <p>Manage rentals, inventory, deliveries, invoices, payments, and customers with a cleaner daily control panel.</p>
                            </div>
                        </div>
                    </section>

                    <section class="auth-form-panel">
                        <div class="auth-form-mobile-brand">
                            <a href="/" class="auth-brand-link">
                                <x-application-logo class="login-logo" style="width:auto;height:auto;max-width:220px;max-height:64px;margin:0;" />
                                <div>
                                    <p class="auth-brand-title" style="font-size:1.7rem; color:#0f172a;">Rentnexis</p>
                                    <p class="auth-brand-copy" style="margin:8px 0 0;font-size:12px;color:#64748b;">Smarter Rental Operations</p>
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
