<?php

return [
    'currency' => env('MZ_CURRENCY', 'OMR'),
    'commission_rate' => (float) env('MZ_COMMISSION_RATE', 0.10),
    'quotation_validity_days' => (int) env('MZ_QUOTATION_VALIDITY_DAYS', 7),
    'sandbox_payments' => (bool) env('MZ_SANDBOX_PAYMENTS', true),
    'allow_test_otp' => filter_var(
        env('MZ_ALLOW_TEST_OTP', env('APP_ENV') === 'local' || env('APP_ENV') === 'testing'),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'test_otp' => (string) env('MZ_TEST_OTP', '123456'),
    'default_locale' => env('MZ_DEFAULT_LOCALE', 'ar'),
    'payment_due_days_max' => (int) env('MZ_PAYMENT_DUE_DAYS_MAX', 730),
    'driver_activation' => [
        'expires_days' => (int) env('DRIVER_ACTIVATION_EXPIRES_DAYS', 7),
        'invite_base_url' => env('DRIVER_INVITE_BASE_URL', 'mzdriver://activate'),
        'technical_email_domain' => env('DRIVER_TECHNICAL_EMAIL_DOMAIN', 'drivers.mz.local'),
        'import_max_rows' => (int) env('DRIVER_IMPORT_MAX_ROWS', 200),
    ],
    'fleet_import_max_rows' => (int) env('FLEET_IMPORT_MAX_ROWS', 200),
    'whatsapp' => [
        'driver_invite_enabled' => filter_var(env('WHATSAPP_DRIVER_INVITE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'api_url' => env('WHATSAPP_API_URL'),
        'api_token' => env('WHATSAPP_API_TOKEN'),
        'from' => env('WHATSAPP_FROM'),
    ],
];
