<?php

namespace App\Contracts;

use App\DTOs\PaymentLinkResponse;
use App\DTOs\PaymentValidationResponse;

interface PaymentGatewayInterface
{
    public function getName(): string;

    public function isConfigured(): bool;

    /**
     * @param  array{
     *     model_type: class-string,
     *     model_id: int,
     *     amount: float,
     *     currency?: string,
     *     description: string,
     *     success_url: string,
     *     cancel_url: string,
     *     user_id?: int|null,
     *     metadata?: array<string, mixed>
     * }  $data
     */
    public function createPaymentLink(array $data): PaymentLinkResponse;

    public function validatePayment(string $sessionId): PaymentValidationResponse;
}
