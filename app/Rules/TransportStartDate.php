<?php

namespace App\Rules;

use App\Models\ShipmentRequest;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TransportStartDate implements ValidationRule
{
    public function __construct(private readonly ?ShipmentRequest $shipment) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $zone = (string) config('mz.business_timezone', 'Asia/Muscat');
            $start = Carbon::parse($value, $zone)->toDateString();
        } catch (\Throwable) {
            return;
        }

        $today = now($zone)->toDateString();
        if ($start < $today) {
            $fail('The first transport date must be today or later.');

            return;
        }

        $required = $this->shipment?->required_date?->toDateString();
        if ($required !== null && $required !== '' && $start < $required) {
            $fail('The first transport date must be on or after the customer required date.');
        }
    }
}
