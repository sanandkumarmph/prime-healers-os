<?php

return [
    'engine' => env('PDF_ENGINE', 'browsershot'),
    'node_binary' => env('PDF_NODE_BINARY', 'node'),
    'node_module_path' => env('PDF_NODE_MODULE_PATH', base_path('node_modules')),
    'browser_path' => env('PDF_BROWSER_PATH'),
    'disable_sandbox' => env('PDF_DISABLE_SANDBOX', DIRECTORY_SEPARATOR !== '\\'),
    'temp_path' => env('PDF_TEMP_PATH', storage_path('app/pdf-runtime/tmp')),
    'user_data_dir' => env('PDF_USER_DATA_DIR', storage_path('app/pdf-runtime/profile')),
    'currency_symbol' => env('PDF_CURRENCY_SYMBOL', "\u{20B9}"),
    'currency_fallback' => env('PDF_CURRENCY_FALLBACK', 'Rs.'),
    'browsershot_view' => 'invoices.print',
    'dompdf_view' => 'invoices.pdf-dompdf',
    'browsershot_margin_mm' => (float) env('PDF_BROWSERSHOT_MARGIN_MM', 14),
    'browsershot_scale' => (float) env('PDF_BROWSERSHOT_SCALE', 0.9),
    'debug_runtime' => (bool) env('PDF_DEBUG_RUNTIME', false),
    'debug_runtime_marker' => env('PDF_DEBUG_RUNTIME_MARKER', 'PDF_RUNTIME_MARKER_2026_05_15_MARGIN_FIX'),
    'environment' => [
        'windows' => [],
        'linux' => [
            'HOME' => storage_path('app/pdf-runtime/home'),
            'TMPDIR' => storage_path('app/pdf-runtime/tmp'),
            'TMP' => storage_path('app/pdf-runtime/tmp'),
            'TEMP' => storage_path('app/pdf-runtime/tmp'),
            'XDG_CONFIG_HOME' => storage_path('app/pdf-runtime/xdg-config'),
            'XDG_CACHE_HOME' => storage_path('app/pdf-runtime/xdg-cache'),
            'XDG_RUNTIME_DIR' => storage_path('app/pdf-runtime/xdg-runtime'),
        ],
        'macos' => [],
    ],
    'chromium_arguments' => [
        'common' => [],
        'windows' => [],
        'linux' => [
            'disable-dev-shm-usage',
            'disable-gpu',
            'no-zygote',
            'single-process',
            'disable-software-rasterizer',
        ],
        'macos' => [],
    ],
    'fallback_browser_paths' => [
        'windows' => [
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        ],
        'linux' => [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
        ],
        'macos' => [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ],
    ],
];
