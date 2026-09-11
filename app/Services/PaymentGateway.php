<?php

namespace App\Services;

use App\DTOs\PaymentLinkResponse;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentGateways\ThawaniGateway;
use RuntimeException;

class PaymentGateway
{
    public function usesHostedCheckout(): bool
    {
        return ! config('mz.sandbox_payments') && $this->thawani()->isConfigured();
    }

    public function createCheckout(Payment $payment, array $data): PaymentLinkResponse
    {
        $gateway = $this->thawani();

        if (! $gateway->isConfigured()) {
            throw new RuntimeException('Live payment gateway is not configured.');
        }

        $response = $gateway->createPaymentLink($data);

        $payment->forceFill([
            'status' => PaymentStatus::Processing,
            'gateway' => $gateway->getName(),
            'gateway_reference' => $response->sessionId,
            'gateway_payload' => array_merge($payment->gateway_payload ?? [], [
                'session_id' => $response->sessionId,
                'payment_link' => $response->paymentLink,
                'checkout' => $response->gatewayData,
                'created_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $response;
    }

    /**
     * Verify and capture a payment. Frontend success flags are never trusted.
     */
    public function capture(Payment $payment): Payment
    {
        if ($payment->status === PaymentStatus::Completed) {
            return $payment;
        }

        if ($this->usesHostedCheckout() || $payment->gateway === 'thawani') {
            return $this->captureThawani($payment);
        }

        if (! config('mz.sandbox_payments')) {
            throw new RuntimeException('Live payment gateway is not configured.');
        }

        return $this->captureSandbox($payment);
    }

    public function captureOffline(Payment $payment, string $gateway): Payment
    {
        if ($payment->status === PaymentStatus::Completed) {
            return $payment;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Completed,
            'gateway' => $gateway,
            'gateway_reference' => strtoupper($gateway).'-'.strtoupper(bin2hex(random_bytes(6))),
            'paid_at' => now(),
            'gateway_payload' => [
                'verified' => true,
                'source' => $gateway,
                'captured_at' => now()->toIso8601String(),
            ],
        ])->save();

        return $payment->fresh();
    }

    public function markFailed(Payment $payment, array $payload = []): Payment
    {
        if ($payment->status === PaymentStatus::Completed) {
            return $payment;
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'gateway_payload' => array_merge($payment->gateway_payload ?? [], $payload, [
                'failed_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $payment->fresh();
    }

    public function paymentLink(Payment $payment): ?string
    {
        $payload = $payment->gateway_payload ?? [];

        return is_string($payload['payment_link'] ?? null) ? $payload['payment_link'] : null;
    }

    private function captureThawani(Payment $payment): Payment
    {
        $sessionId = $payment->gateway_reference;

        if (! $sessionId) {
            throw new RuntimeException('Thawani session is missing for this payment.');
        }

        $validation = $this->thawani()->validatePayment($sessionId);

        if (! $validation->isValid) {
            if ($validation->status === 'failed') {
                $this->markFailed($payment, [
                    'validation' => $validation->gatewayData,
                    'error' => $validation->errorMessage,
                ]);
            } else {
                $payment->forceFill([
                    'gateway_payload' => array_merge($payment->gateway_payload ?? [], [
                        'validation' => $validation->gatewayData,
                    ]),
                ])->save();
            }

            return $payment->fresh();
        }

        $payment->forceFill([
            'status' => PaymentStatus::Completed,
            'gateway' => 'thawani',
            'gateway_reference' => $sessionId,
            'paid_at' => now(),
            'gateway_payload' => array_merge($payment->gateway_payload ?? [], $validation->gatewayData, [
                'verified' => true,
                'source' => 'thawani',
                'captured_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $payment->fresh();
    }

    private function captureSandbox(Payment $payment): Payment
    {
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

    private function thawani(): ThawaniGateway
    {
        return new ThawaniGateway(config('payment.gateways.thawani', []));
    }
}
