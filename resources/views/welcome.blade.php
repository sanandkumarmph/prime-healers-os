<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Rentnexis') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/rentnexis-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body style="margin:0; min-height:100vh; display:grid; place-items:center; background:#f8fafc; color:#0f172a; font-family:Inter,Segoe UI,Arial,sans-serif;">
    <main style="width:min(100%, 720px); padding:32px 20px;">
        <section style="border:1px solid #e2e8f0; border-radius:24px; background:#ffffff; box-shadow:0 18px 50px rgba(15,23,42,.08); padding:32px;">
            <div style="display:flex; align-items:center; gap:16px; margin-bottom:20px;">
                <img
                    src="{{ asset('images/logo-rentnexis.png') }}"
                    alt="Rentnexis - Smarter Rental Operations"
                    style="width:52px; height:52px; object-fit:contain; border-radius:14px; flex:0 0 52px;"
                >
                <div>
                    <div style="font-size:28px; font-weight:800; letter-spacing:-0.04em; line-height:1;">Rentnexis</div>
                    <div style="margin-top:4px; font-size:14px; color:#64748b;">Smarter Rental Operations</div>
                </div>
            </div>

            <div style="display:grid; gap:14px;">
                <div style="display:inline-flex; align-items:center; width:max-content; padding:6px 10px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase;">
                    Rental Operations Platform
                </div>
                <h1 style="margin:0; font-size:34px; line-height:1.1; letter-spacing:-0.04em;">Run your rental business from one calm workspace.</h1>
                <p style="margin:0; font-size:15px; line-height:1.7; color:#475569;">
                    Manage rentals, sales, inventory, deliveries, invoices, and payments from a single control center.
                </p>
                <div style="display:flex; flex-wrap:wrap; gap:12px; padding-top:8px;">
                    @auth
                        <a href="{{ route('dashboard') }}" style="display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 18px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:700;">Open Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" style="display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 18px; border-radius:12px; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:700;">Log In</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" style="display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 18px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; text-decoration:none; font-weight:700;">Create Account</a>
                        @endif
                    @endauth
                </div>
            </div>
        </section>
    </main>
</body>
</html>
