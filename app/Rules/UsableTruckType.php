<?php

namespace App\Rules;

use App\Models\User;
use App\Services\TruckTypeService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UsableTruckType implements ValidationRule
{
    public function __construct(
        private readonly ?User $user,
        private readonly ?string $currentCode = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The selected truck type is invalid.');

            return;
        }

        if (! app(TruckTypeService::class)->isUsable($this->user, $value, $this->currentCode)) {
            $fail('The selected truck type is invalid.');
        }
    }
}
