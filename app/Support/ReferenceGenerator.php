<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ReferenceGenerator
{
    public static function next(string $prefix, string $modelClass, string $column = 'reference'): string
    {
        /** @var class-string<Model> $modelClass */
        $year = now()->format('Y');
        $latest = $modelClass::query()
            ->where($column, 'like', "{$prefix}-{$year}-%")
            ->orderByDesc($column)
            ->value($column);

        $sequence = 1;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }

    public static function idempotencyKey(string $seed): string
    {
        return hash('sha256', $seed);
    }

    public static function otp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function tokenName(): string
    {
        return 'mz-'.Str::lower(Str::random(8));
    }
}
