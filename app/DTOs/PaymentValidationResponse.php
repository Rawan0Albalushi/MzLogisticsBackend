<?php

namespace App\DTOs;

class PaymentValidationResponse
{
    /**
     * @param  array<string, mixed>  $gatewayData
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly string $status,
        public readonly array $gatewayData,
        public readonly ?string $errorMessage = null,
    ) {}
}
