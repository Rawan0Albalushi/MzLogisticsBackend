<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use RuntimeException;

class PaymentGateway
{
    /**
     * Verify and capture a payment. Frontend success flags are never trusted.
     */
    public function capture(Payment $payment): Payment
    {
        if ($payment->status === PaymentStatus::Completed) {
            return $payment;
        }

        if (! config('mz.sandbox_payments')) {
            throw new RuntimeException('Live payment gateway is not configured.');
        }

        $payment->forceFill([
            'status' => PaymentStatus::Completed,
            'gateway' => 'sandbox',
            'gateway_reference' => 'SND-'.strtoupper(bin2hex(random_bytes(6))),
            'paid_at' => now(),
            'gateway_payload' => [
                'verified' => true,
                'source' => 'server_sandbox',
                'captured_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $payment->fresh();
    }
}
