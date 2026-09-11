<?php

namespace App\DTOs;

class PaymentLinkResponse
{
    /**
     * @param  array<string, mixed>  $gatewayData
     */
    public function __construct(
        public readonly string $paymentLink,
        public readonly string $sessionId,
        public readonly array $gatewayData,
        public readonly string $gatewayName,
    ) {}
}
