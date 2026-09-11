<?php

namespace App\DTOs;

use App\Models\Payment;
use App\Models\TransportJob;

class QuotationAcceptanceResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly bool $requiresCheckout,
        public readonly ?TransportJob $job = null,
        public readonly ?string $paymentLink = null,
        public readonly ?string $sessionId = null,
    ) {}
}
