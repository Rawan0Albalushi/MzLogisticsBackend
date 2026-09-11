<?php

return [
    'default_gateway' => env('DEFAULT_PAYMENT_GATEWAY', 'thawani'),

    'frontend_url' => env('CUSTOMER_APP_URL', env('FRONTEND_URL', env('APP_URL', 'http://localhost:8080'))),

    'callback_url' => env('PAYMENT_CALLBACK_URL', env('APP_URL', 'http://localhost:8000')),

    'app_scheme' => env('CUSTOMER_APP_SCHEME', 'mzlogistics'),

    'gateways' => [
        'thawani' => [
            'public_key' => env('THAWANI_PUBLIC_KEY'),
            'secret_key' => env('THAWANI_SECRET_KEY'),
            'mode' => env('THAWANI_MODE', 'test'),
            'max_amount_omr' => 5000,
            'max_unit_amount_baisa' => 5_000_000,
        ],
    ],
];
