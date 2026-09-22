<?php

namespace App\Support;

final class PhoneNumber
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 8 && preg_match('/^[79]\d{7}$/', $digits) === 1) {
            $digits = '968'.$digits;
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    public static function digits(string $normalized): string
    {
        return preg_replace('/\D+/', '', $normalized) ?? '';
    }
}
