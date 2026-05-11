<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Prime Healers OS') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&family=manrope:600,700,800&display=swap" rel="stylesheet" />

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="ph-welcome-body">
    <main class="ph-welcome-main">
        <section class="ph-welcome-card">
            <div class="ph-welcome-branding">
                <div class="ph-brand-lockup">
                    <span class="ph-brand-badge" aria-hidden="true">
                        <x-application-logo class="guest-logo ph-brand-logo-hero" />
                    </span>
                    <div class="ph-brand-wordmark">
                        <div class="ph-brand-heading">Prime Healers OS</div>
                        <div class="ph-brand-subtitle">Rental, sales, and care operations</div>
                    </div>
                </div>
            </div>

            <div class="ph-welcome-copy">
                <div class="ph-welcome-kicker">
                    Rental Operations Platform
                </div>
                <h1>Run your rental business from one calm workspace.</h1>
                <p>
                    Manage rentals, sales, inventory, deliveries, invoices, and payments from a single control center.
                </p>
                <div class="ph-welcome-actions">
                    @auth
                        <a href="{{ route('dashboard') }}" class="ph-welcome-button ph-welcome-button--primary">Open Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="ph-welcome-button ph-welcome-button--primary">Log In</a>
                        @if (!$internalSingleOrgMode && Route::has('register'))
                            <a href="{{ route('register') }}" class="ph-welcome-button ph-welcome-button--secondary">Create Account</a>
                        @endif
                    @endauth
                </div>
            </div>
        </section>
    </main>
</body>
</html>
