<?php

namespace App\Services;

use App\DTOs\QuotationAcceptanceResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JobStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TripStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobOrchestrationService
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly PaymentMethodService $paymentMethods,
    ) {}

    public function acceptQuotation(User $user, Quotation $quotation, ?string $method = null, ?string $callbackBaseUrl = null): QuotationAcceptanceResult
    {
        $quotation->load(['shipmentRequest', 'providerOrganization']);

        $existingJob = TransportJob::query()->where('quotation_id', $quotation->id)->first();
        if ($existingJob) {
            $payment = Payment::query()->where('quotation_id', $quotation->id)->firstOrFail();

            return new QuotationAcceptanceResult(
                payment: $payment,
                requiresCheckout: false,
                job: $existingJob->load(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization']),
            );
        }

        $this->assertQuotationCanBeAwarded($user, $quotation);

        $paymentMethod = $this->paymentMethods->resolveActive($method);
        $shipment = $quotation->shipmentRequest;
        $idempotencyKey = ReferenceGenerator::idempotencyKey('quotation:'.$quotation->id);

        return DB::transaction(function () use ($user, $quotation, $shipment, $paymentMethod, $idempotencyKey, $callbackBaseUrl) {
            $quotation = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $shipment = ShipmentRequest::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $quotation->setRelation('shipmentRequest', $shipment);
            $quotation->loadMissing('providerOrganization');

            $existingJob = TransportJob::query()->where('quotation_id', $quotation->id)->first();
            if ($existingJob) {
                $payment = Payment::query()->where('quotation_id', $quotation->id)->firstOrFail();

                return new QuotationAcceptanceResult(
                    payment: $payment,
                    requiresCheckout: false,
                    job: $existingJob->load(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization']),
                );
            }

            $this->assertQuotationCanBeAwarded($user, $quotation);
            $this->assertNoConflictingCheckout($shipment, $quotation);

            $commissionRate = $quotation->providerOrganization->commissionRate();
            $amount = (float) $quotation->total_price;
            $commission = round($amount * $commissionRate, 3);
            $providerAmount = round($amount - $commission, 3);

            $payment = Payment::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'reference' => ReferenceGenerator::next('PAY', Payment::class),
                    'shipment_request_id' => $shipment->id,
                    'quotation_id' => $quotation->id,
                    'payer_organization_id' => $user->organization_id,
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'provider_amount' => $providerAmount,
                    'currency' => $quotation->currency,
                    'method' => $paymentMethod->code,
                    'status' => PaymentStatus::Processing,
                    'gateway' => $this->gatewayFor($paymentMethod),
                ]
            );

            if ($payment->status === PaymentStatus::Completed) {
                $job = $this->fulfillPaidQuotation($payment, $user);

                return new QuotationAcceptanceResult(
                    payment: $payment->fresh(),
                    requiresCheckout: false,
                    job: $job,
                );
            }

            if ($payment->status === PaymentStatus::Failed) {
                $payment->forceFill([
                    'status' => PaymentStatus::Processing,
                    'method' => $paymentMethod->code,
                    'gateway' => $this->gatewayFor($paymentMethod),
                ])->save();
            } elseif ($payment->method !== $paymentMethod->code) {
                $payment->forceFill([
                    'method' => $paymentMethod->code,
                    'gateway' => $this->gatewayFor($paymentMethod),
                ])->save();
            }

            if ($paymentMethod->isThawani() && $this->paymentGateway->usesHostedCheckout()) {
                $checkout = $this->paymentGateway->createCheckout($payment, [
                    'user_id' => $user->id,
                    'model_type' => Payment::class,
                    'model_id' => $payment->id,
                    'amount' => $amount,
                    'currency' => $quotation->currency,
                    'description' => 'MZ '.$quotation->reference,
                    'success_url' => $this->callbackUrl('payment.success', $payment->id, $callbackBaseUrl),
                    'cancel_url' => $this->callbackUrl('payment.cancel', $payment->id, $callbackBaseUrl),
                    'metadata' => [
                        'quotation_id' => $quotation->id,
                        'shipment_request_id' => $shipment->id,
                        'payment_reference' => $payment->reference,
                    ],
                ]);

                return new QuotationAcceptanceResult(
                    payment: $payment->fresh(),
                    requiresCheckout: true,
                    paymentLink: $checkout->paymentLink,
                    sessionId: $checkout->sessionId,
                );
            }

            if ($paymentMethod->isCash()) {
                $this->paymentGateway->captureOffline($payment, 'cash');
            } else {
                $this->paymentGateway->capture($payment);
            }

            if ($payment->fresh()->status !== PaymentStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment could not be verified.'],
                ]);
            }

            $job = $this->fulfillPaidQuotation($payment->fresh(), $user);

            return new QuotationAcceptanceResult(
                payment: $payment->fresh(),
                requiresCheckout: false,
                job: $job,
            );
        });
    }

    public function completePaidQuotation(Payment $payment, ?User $user = null): TransportJob
    {
        return DB::transaction(function () use ($payment, $user) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $captured = $this->paymentGateway->capture($payment);

            if ($captured->status !== PaymentStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment could not be verified.'],
                ]);
            }

            return $this->fulfillPaidQuotation($captured, $user);
        });
    }

    public function cancelCheckout(Payment $payment): Payment
    {
        if ($payment->status === PaymentStatus::Completed) {
            return $payment;
        }

        return $this->paymentGateway->markFailed($payment, [
            'source' => 'checkout_cancel',
        ]);
    }

    private function fulfillPaidQuotation(Payment $payment, ?User $user): TransportJob
    {
        $payment->load(['quotation.shipmentRequest', 'quotation.providerOrganization']);
        $quotation = $payment->quotation;
        $shipment = $quotation->shipmentRequest;

        $existingJob = TransportJob::query()->where('quotation_id', $quotation->id)->first();
        if ($existingJob) {
            return $existingJob->load(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization']);
        }

        if (! in_array($quotation->status, [QuotationStatus::Submitted, QuotationStatus::Accepted], true)) {
            throw ValidationException::withMessages([
                'quotation' => ['This quotation cannot be awarded.'],
            ]);
        }

        if ($shipment->status === ShipmentStatus::Awarded && $shipment->awarded_quotation_id !== $quotation->id) {
            throw ValidationException::withMessages([
                'shipment' => ['This shipment has already been awarded.'],
            ]);
        }

        $job = TransportJob::query()->create([
            'reference' => ReferenceGenerator::next('JOB', TransportJob::class),
            'shipment_request_id' => $shipment->id,
            'quotation_id' => $quotation->id,
            'customer_organization_id' => $shipment->customer_organization_id,
            'provider_organization_id' => $quotation->provider_organization_id,
            'total_price' => $quotation->total_price,
            'total_quantity' => $shipment->quantity,
            'delivered_quantity' => 0,
            'currency' => $quotation->currency,
            'status' => JobStatus::PendingDispatch,
        ]);

        $tripCount = $quotation->dispatchTripCount();
        for ($index = 1; $index <= $tripCount; $index++) {
            Trip::query()->create([
                'reference' => ReferenceGenerator::next('TRP', Trip::class),
                'transport_job_id' => $job->id,
                'sequence' => $index,
                'planned_quantity' => $quotation->quantity_per_trip,
                'status' => TripStatus::Unassigned,
                'pickup_address' => $shipment->pickup_address,
                'pickup_city' => $shipment->pickup_city,
                'pickup_lat' => $shipment->pickup_lat,
                'pickup_lng' => $shipment->pickup_lng,
                'delivery_address' => $shipment->delivery_address,
                'delivery_city' => $shipment->delivery_city,
                'delivery_lat' => $shipment->delivery_lat,
                'delivery_lng' => $shipment->delivery_lng,
            ]);
        }

        Invoice::query()->create([
            'reference' => ReferenceGenerator::next('INV', Invoice::class),
            'organization_id' => $shipment->customer_organization_id,
            'transport_job_id' => $job->id,
            'payment_id' => $payment->id,
            'type' => InvoiceType::Customer,
            'amount' => $payment->amount,
            'currency' => $quotation->currency,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
        ]);

        Invoice::query()->create([
            'reference' => ReferenceGenerator::next('INV', Invoice::class),
            'organization_id' => $quotation->provider_organization_id,
            'transport_job_id' => $job->id,
            'payment_id' => $payment->id,
            'type' => InvoiceType::Provider,
            'amount' => $payment->provider_amount,
            'currency' => $quotation->currency,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);

        Invoice::query()->create([
            'reference' => ReferenceGenerator::next('INV', Invoice::class),
            'organization_id' => $quotation->provider_organization_id,
            'transport_job_id' => $job->id,
            'payment_id' => $payment->id,
            'type' => InvoiceType::Commission,
            'amount' => $payment->commission_amount,
            'currency' => $quotation->currency,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
        ]);

        $quotation->forceFill(['status' => QuotationStatus::Accepted])->save();
        $shipment->quotations()
            ->where('id', '!=', $quotation->id)
            ->where('status', QuotationStatus::Submitted)
            ->update(['status' => QuotationStatus::Rejected]);

        $shipment->forceFill([
            'status' => ShipmentStatus::Awarded,
            'awarded_quotation_id' => $quotation->id,
        ])->save();

        AuditLogger::record('quotation.accepted', $quotation, [], ['job' => $job->reference], $user);
        AuditLogger::record('job.created', $job, [], $job->toArray(), $user);
        AuditLogger::record('payment.processed', $payment, [], ['status' => PaymentStatus::Completed->value], $user);

        return $job->fresh(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization']);
    }

    private function assertQuotationCanBeAwarded(User $user, Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Submitted) {
            throw ValidationException::withMessages([
                'quotation' => ['This quotation cannot be accepted.'],
            ]);
        }

        $shipment = $quotation->shipmentRequest;
        if ($shipment->status !== ShipmentStatus::Published) {
            throw ValidationException::withMessages([
                'shipment' => ['This shipment is no longer open for award.'],
            ]);
        }

        if ($shipment->customer_organization_id !== $user->organization_id) {
            throw ValidationException::withMessages([
                'shipment' => ['You can only accept quotations on your own shipment requests.'],
            ]);
        }
    }

    private function assertNoConflictingCheckout(ShipmentRequest $shipment, Quotation $quotation): void
    {
        $otherProcessing = Payment::query()
            ->where('shipment_request_id', $shipment->id)
            ->where('quotation_id', '!=', $quotation->id)
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])
            ->exists();

        if ($otherProcessing) {
            throw ValidationException::withMessages([
                'payment' => ['Another payment is already in progress for this shipment.'],
            ]);
        }
    }

    private function callbackUrl(string $routeName, int $paymentId, ?string $callbackBaseUrl): string
    {
        $path = route($routeName, ['payment_id' => $paymentId], false);
        $base = $this->sanitizeCallbackBase($callbackBaseUrl);

        return $base.$path;
    }

    private function sanitizeCallbackBase(?string $base): string
    {
        $fallback = rtrim((string) config('payment.callback_url', config('app.url')), '/');
        $candidate = trim((string) $base);

        if ($candidate === '') {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $fallback;
        }

        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            return $fallback;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    private function gatewayFor(PaymentMethod $method): string
    {
        if ($method->isCash()) {
            return 'cash';
        }

        return $this->paymentGateway->usesHostedCheckout() ? 'thawani' : 'sandbox';
    }
}
