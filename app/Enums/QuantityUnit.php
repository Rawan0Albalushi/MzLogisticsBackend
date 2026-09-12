<?php

namespace App\Enums;

enum QuantityUnit: string
{
    case Tons = 'tons';
    case Pallets = 'pallets';
    case Units = 'units';

    public static function tryNormalize(mixed $value): ?self
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'ton', 'tons' => self::Tons,
            'pallet', 'pallets' => self::Pallets,
            'unit', 'units' => self::Units,
            default => null,
        };
    }

    public static function normalize(mixed $value): self
    {
        return self::tryNormalize($value) ?? self::Tons;
    }
}
