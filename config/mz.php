<?php

return [
    'currency' => env('MZ_CURRENCY', 'OMR'),
    'commission_rate' => (float) env('MZ_COMMISSION_RATE', 0.10),
    'quotation_validity_days' => (int) env('MZ_QUOTATION_VALIDITY_DAYS', 7),
    'sandbox_payments' => (bool) env('MZ_SANDBOX_PAYMENTS', true),
    'default_locale' => env('MZ_DEFAULT_LOCALE', 'ar'),
    'payment_due_days_max' => (int) env('MZ_PAYMENT_DUE_DAYS_MAX', 730),
];
