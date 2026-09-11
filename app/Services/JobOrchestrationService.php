<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JobStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TripStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobOrchestrationService
{
    public function __construct(private readonly PaymentGateway $paymentGateway) {}

    public function acceptQuotation(User $user, Quotation $quotation, string $method = 'card'): TransportJob
    {
        $quotation->load(['shipmentRequest', 'providerOrganization']);

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

        $idempotencyKey = ReferenceGenerator::idempotencyKey('quotation:'.$quotation->id);

        return DB::transaction(function () use ($user, $quotation, $shipment, $method, $idempotencyKey) {
            $existingJob = TransportJob::query()->where('quotation_id', $quotation->id)->first();
            if ($existingJob) {
                return $existingJob->load(['trips', 'shipmentRequest', 'quotation']);
            }

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
                    'method' => $method,
                    'status' => PaymentStatus::Processing,
                    'gateway' => 'sandbox',
                ]
            );

            $this->paymentGateway->capture($payment);

            if ($payment->fresh()->status !== PaymentStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment could not be verified.'],
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
                'amount' => $amount,
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
                'amount' => $providerAmount,
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
                'amount' => $commission,
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
        });
    }
}
