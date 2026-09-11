<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;
use App\DTOs\PaymentLinkResponse;
use App\DTOs\PaymentValidationResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ThawaniGateway implements PaymentGatewayInterface
{
    /**
     * @param  array{public_key?: string|null, secret_key?: string|null, mode?: string|null}  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    public function getName(): string
    {
        return 'thawani';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['public_key'] ?? null)
            && filled($this->config['secret_key'] ?? null);
    }

    public function createPaymentLink(array $data): PaymentLinkResponse
    {
        $amountOmr = (float) $data['amount'];
        $unitAmountBaisa = $this->amountToBaisa($amountOmr);
        $description = $this->formatProductName($data['description']);

        $body = [
            'client_reference_id' => uniqid('thawani_'),
            'mode' => 'payment',
            'products' => [[
                'name' => $description,
                'quantity' => 1,
                'unit_amount' => $unitAmountBaisa,
            ]],
            'success_url' => $data['success_url'],
            'cancel_url' => $data['cancel_url'],
            'metadata' => $this->buildMetadata($data),
        ];

        $response = $this->makeRequest('POST', '/checkout/session', $body);
        $sessionId = $response['data']['session_id'] ?? null;

        if (! $sessionId) {
            throw new RuntimeException('Thawani did not return a session_id.');
        }

        $paymentLink = $this->checkoutHost().'/pay/'.$sessionId.'?key='.$this->config['public_key'];

        return new PaymentLinkResponse(
            paymentLink: $paymentLink,
            sessionId: $sessionId,
            gatewayData: $response,
            gatewayName: $this->getName(),
        );
    }

    public function validatePayment(string $sessionId): PaymentValidationResponse
    {
        try {
            $response = $this->makeRequest('GET', '/checkout/session/'.$sessionId);
            $paymentStatus = $response['data']['payment_status'] ?? 'failed';

            return match ($paymentStatus) {
                'paid' => new PaymentValidationResponse(true, 'paid', $response),
                'pending' => new PaymentValidationResponse(false, 'pending', $response),
                'failed' => new PaymentValidationResponse(false, 'failed', $response),
                default => new PaymentValidationResponse(false, 'failed', $response, 'Unknown payment status'),
            };
        } catch (\Throwable $e) {
            Log::error('Thawani payment validation failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return new PaymentValidationResponse(false, 'failed', [], $e->getMessage());
        }
    }

    private function apiBase(): string
    {
        return ($this->config['mode'] ?? 'test') === 'live'
            ? 'https://checkout.thawani.om/api/v1'
            : 'https://uatcheckout.thawani.om/api/v1';
    }

    private function checkoutHost(): string
    {
        return ($this->config['mode'] ?? 'test') === 'live'
            ? 'https://checkout.thawani.om'
            : 'https://uatcheckout.thawani.om';
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function makeRequest(string $method, string $path, ?array $body = null): array
    {
        $url = $this->apiBase().$path;

        Log::debug('Thawani API Request', ['method' => $method, 'path' => $path]);

        $request = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'thawani-api-key' => (string) $this->config['secret_key'],
        ])->timeout(30);

        $response = $method === 'POST'
            ? $request->post($url, $body ?? [])
            : $request->get($url);

        if ($response->failed()) {
            Log::error('Thawani API error: HTTP '.$response->status(), [
                'response' => $response->body(),
            ]);

            throw new RuntimeException('Thawani HTTP '.$response->status().': '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Thawani rejects null values in metadata.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function buildMetadata(array $data): array
    {
        $metadata = [
            'model_type' => (string) $data['model_type'],
            'model_id' => (int) $data['model_id'],
        ];

        if (! empty($data['user_id'])) {
            $metadata['user_id'] = (int) $data['user_id'];
        }

        if (! empty($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $key => $value) {
                if ($value !== null && $value !== '') {
                    $metadata[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
                }
            }
        }

        return $metadata;
    }

    private function amountToBaisa(float $amountOmr): int
    {
        $baisa = (int) round($amountOmr * 1000);
        $min = 1;
        $max = (int) config('payment.gateways.thawani.max_unit_amount_baisa', 5_000_000);

        if ($baisa < $min || $baisa > $max) {
            throw new RuntimeException(
                "Payment amount {$amountOmr} OMR is outside Thawani limits (".($min / 1000).'–'.($max / 1000).' OMR).',
            );
        }

        return $baisa;
    }

    private function formatProductName(string $name): string
    {
        if (mb_strlen($name) <= 39) {
            return $name;
        }

        return '...'.mb_substr($name, 0, 36);
    }
}
