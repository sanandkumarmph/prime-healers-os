<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Prime Healers OS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html,
        body {
            min-height:100%;
            margin:0;
            background:#f8fafc;
            color:#0f172a;
            font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            overflow:auto;
        }
    </style>
</head>
<body>
    @yield('content')
    @stack('scripts')
</body>
</html>
