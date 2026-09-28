<?php

namespace App\Services;

use App\DTOs\InvoicePaymentResult;
use App\DTOs\QuotationAcceptanceResult;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JobStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlatformOfferStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TripStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PlatformOffer;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BillingAllocator;
use App\Support\PaymentTerms;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobOrchestrationService
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly PaymentMethodService $paymentMethods,
        private readonly WalletLedgerService $walletLedger,
    ) {}

    public function acceptPlatformOffer(User $user, PlatformOffer $offer, ?string $method = null, ?string $callbackBaseUrl = null): QuotationAcceptanceResult
    {
        $offer->load(['quotation.shipmentRequest', 'quotation.providerOrganization', 'shipmentRequest']);

        return $this->acceptQuotation($user, $offer->quotation, $method, $callbackBaseUrl, $offer);
    }

    public function confirmPlatformOffer(User $user, PlatformOffer $offer): QuotationAcceptanceResult
    {
        $offer->load(['quotation.shipmentRequest', 'quotation.providerOrganization']);

        return DB::transaction(function () use ($user, $offer) {
            $quotation = Quotation::query()->whereKey($offer->quotation_id)->lockForUpdate()->firstOrFail();
            $shipment = ShipmentRequest::query()->whereKey($quotation->shipment_request_id)->lockForUpdate()->firstOrFail();
            $quotation->setRelation('shipmentRequest', $shipment);
            $quotation->load('providerOrganization');
            $offer = $this->lockPlatformOffer($offer, $quotation);

            $existing = $this->existingAcceptance($quotation);
            if ($existing) {
                return $existing;
            }

            if ($quotation->status !== QuotationStatus::Submitted) {
                throw ValidationException::withMessages([
                    'quotation' => ['This quotation cannot be accepted.'],
                ]);
            }

            if ($shipment->status !== ShipmentStatus::Published) {
                throw ValidationException::withMessages([
                    'shipment' => ['This shipment is no longer open for award.'],
                ]);
            }

            $job = $this->createAwardedJob($quotation, $shipment, $user, null, false, $offer);
            $terms = PaymentTerms::fromShipment($shipment);

            if ($terms->isPrepaid()) {
                Invoice::query()
                    ->where('transport_job_id', $job->id)
                    ->where('type', InvoiceType::Customer)
                    ->whereNull('due_at')
                    ->update(['due_at' => now()]);
            }

            AuditLogger::record('platform_offer.confirmed_on_behalf', $offer, [], [
                'customer_organization_id' => $shipment->customer_organization_id,
                'job' => $job->reference,
            ], $user);

            return new QuotationAcceptanceResult(
                payment: null,
                requiresCheckout: false,
                job: $job,
                paymentDeferred: ! $terms->isPrepaid(),
            );
        });
    }

    public function acceptQuotation(User $user, Quotation $quotation, ?string $method = null, ?string $callbackBaseUrl = null, ?PlatformOffer $platformOffer = null): QuotationAcceptanceResult
    {
        $quotation->load(['shipmentRequest', 'providerOrganization']);

        $existing = $this->existingAcceptance($quotation);
        if ($existing) {
            return $existing;
        }

        $this->assertQuotationCanBeAwarded($user, $quotation, $platformOffer);
        $shipment = $quotation->shipmentRequest;
        $terms = PaymentTerms::fromShipment($shipment);

        if ($terms->isDeferred()) {
            return DB::transaction(function () use ($user, $quotation, $shipment, $platformOffer) {
                $quotation = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
                $shipment = ShipmentRequest::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                $quotation->setRelation('shipmentRequest', $shipment);
                $quotation->loadMissing('providerOrganization');
                $platformOffer = $this->lockPlatformOffer($platformOffer, $quotation);

                $existing = $this->existingAcceptance($quotation);
                if ($existing) {
                    return $existing;
                }

                $this->assertQuotationCanBeAwarded($user, $quotation, $platformOffer);

                $job = $this->createAwardedJob($quotation, $shipment, $user, null, false, $platformOffer);

                return new QuotationAcceptanceResult(
                    payment: null,
                    requiresCheckout: false,
                    job: $job,
                    paymentDeferred: true,
                );
            });
        }

        $paymentMethod = $this->paymentMethods->resolveActive($method);
        $idempotencyKey = $platformOffer
            ? ReferenceGenerator::idempotencyKey('platform-offer:'.$platformOffer->id)
            : ReferenceGenerator::idempotencyKey('quotation:'.$quotation->id);

        return DB::transaction(function () use ($user, $quotation, $shipment, $paymentMethod, $idempotencyKey, $callbackBaseUrl, $platformOffer) {
            $quotation = Quotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $shipment = ShipmentRequest::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $quotation->setRelation('shipmentRequest', $shipment);
            $quotation->loadMissing('providerOrganization');
            $platformOffer = $this->lockPlatformOffer($platformOffer, $quotation);

            $existing = $this->existingAcceptance($quotation);
            if ($existing) {
                return $existing;
            }

            $this->assertQuotationCanBeAwarded($user, $quotation, $platformOffer);
            $this->assertNoConflictingCheckout($shipment, $quotation);

            $payment = $this->createOrResumePayment($quotation, $shipment, $user, $paymentMethod, $idempotencyKey, null, $platformOffer);

            if ($payment->status === PaymentStatus::Completed) {
                $job = $this->fulfillPaidQuotation($payment, $user, $platformOffer);

                return new QuotationAcceptanceResult(
                    payment: $payment->fresh(),
                    requiresCheckout: false,
                    job: $job,
                );
            }

            if ($paymentMethod->isThawani() && $this->paymentGateway->usesHostedCheckout()) {
                $checkout = $this->paymentGateway->createCheckout($payment, [
                    'user_id' => $user->id,
                    'model_type' => Payment::class,
                    'model_id' => $payment->id,
                    'amount' => (float) $payment->amount,
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

            $this->captureWithMethod($payment, $paymentMethod);

            if ($payment->fresh()->status !== PaymentStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment could not be verified.'],
                ]);
            }

            $job = $this->fulfillPaidQuotation($payment->fresh(), $user, $platformOffer);

            return new QuotationAcceptanceResult(
                payment: $payment->fresh(),
                requiresCheckout: false,
                job: $job,
            );
        });
    }

    public function payCustomerInvoice(User $user, Invoice $invoice, ?string $method = null, ?string $callbackBaseUrl = null): InvoicePaymentResult
    {
        $invoice->load(['transportJob.quotation.providerOrganization', 'transportJob.shipmentRequest']);
        $job = $invoice->transportJob;

        if ($invoice->status === InvoiceStatus::Paid) {
            $payment = $invoice->payment
                ?? Payment::query()->where('invoice_id', $invoice->id)->first()
                ?? Payment::query()->where('quotation_id', $job?->quotation_id)->firstOrFail();

            return new InvoicePaymentResult(
                payment: $payment,
                requiresCheckout: false,
                job: $job?->load(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization', 'invoices']),
            );
        }

        $this->assertInvoicePayable($user, $invoice);
        $quotation = $job->quotation;
        $shipment = $job->shipmentRequest;
        $paymentMethod = $this->paymentMethods->resolveActive($method);
        $idempotencyKey = ReferenceGenerator::idempotencyKey('invoice:'.$invoice->id);

        return DB::transaction(function () use ($user, $invoice, $job, $quotation, $shipment, $paymentMethod, $idempotencyKey, $callbackBaseUrl) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertInvoicePayable($user, $invoice->load(['transportJob.quotation.providerOrganization', 'transportJob.shipmentRequest']));

            $payment = $this->createOrResumePayment($quotation, $shipment, $user, $paymentMethod, $idempotencyKey, $invoice);

            if ($payment->status === PaymentStatus::Completed) {
                $this->settleCompletedPayment($payment, $job, $user, $invoice);

                return new InvoicePaymentResult(
                    payment: $payment->fresh(),
                    requiresCheckout: false,
                    job: $job->fresh(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization', 'invoices']),
                );
            }

            if ($paymentMethod->isThawani() && $this->paymentGateway->usesHostedCheckout()) {
                $checkout = $this->paymentGateway->createCheckout($payment, [
                    'user_id' => $user->id,
                    'model_type' => Payment::class,
                    'model_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'currency' => $quotation->currency,
                    'description' => 'MZ '.$invoice->reference,
                    'success_url' => $this->callbackUrl('payment.success', $payment->id, $callbackBaseUrl),
                    'cancel_url' => $this->callbackUrl('payment.cancel', $payment->id, $callbackBaseUrl),
                    'metadata' => [
                        'invoice_id' => $invoice->id,
                        'quotation_id' => $quotation->id,
                        'shipment_request_id' => $shipment->id,
                        'payment_reference' => $payment->reference,
                    ],
                ]);

                return new InvoicePaymentResult(
                    payment: $payment->fresh(),
                    requiresCheckout: true,
                    paymentLink: $checkout->paymentLink,
                    sessionId: $checkout->sessionId,
                    job: $job,
                );
            }

            $this->captureWithMethod($payment, $paymentMethod);

            if ($payment->fresh()->status !== PaymentStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => ['Payment could not be verified.'],
                ]);
            }

            $this->settleCompletedPayment($payment->fresh(), $job, $user, $invoice);

            return new InvoicePaymentResult(
                payment: $payment->fresh(),
                requiresCheckout: false,
                job: $job->fresh(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization', 'invoices']),
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

            if ($captured->invoice_id) {
                $invoice = Invoice::query()->with(['transportJob.quotation.providerOrganization'])->findOrFail($captured->invoice_id);
                $job = $invoice->transportJob;
                $this->settleCompletedPayment($captured, $job, $user, $invoice);

                return $job->fresh(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization', 'invoices']);
            }

            return $this->fulfillPaidQuotation($captured, $user);
        });
    }

    public function openDeferredInvoices(TransportJob $job): void
    {
        $job->loadMissing('shipmentRequest');
        $terms = PaymentTerms::fromShipment($job->shipmentRequest);

        if (! $terms->isDeferred() || $terms->isPerTrip() || $job->status !== JobStatus::Completed) {
            return;
        }

        Invoice::query()
            ->where('transport_job_id', $job->id)
            ->where('type', InvoiceType::Customer)
            ->where('status', InvoiceStatus::Issued)
            ->whereNull('trip_id')
            ->whereNull('due_at')
            ->update([
                'due_at' => now()->addDays($terms->dueDays),
            ]);
    }

    public function openDeliveredTripInvoice(Trip $trip): void
    {
        if ($trip->status !== TripStatus::Completed) {
            return;
        }

        $trip->loadMissing('transportJob.shipmentRequest');
        $job = $trip->transportJob;
        $terms = PaymentTerms::fromShipment($job->shipmentRequest);

        if (! $terms->isPerTrip()) {
            return;
        }

        Invoice::query()
            ->where('transport_job_id', $job->id)
            ->where('trip_id', $trip->id)
            ->where('type', InvoiceType::Customer)
            ->where('status', InvoiceStatus::Issued)
            ->whereNull('due_at')
            ->update([
                'due_at' => now()->addDays($terms->dueDays),
            ]);
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

    private function fulfillPaidQuotation(Payment $payment, ?User $user, ?PlatformOffer $offer = null): TransportJob
    {
        $payment->load(['quotation.shipmentRequest', 'quotation.providerOrganization']);
        $quotation = $payment->quotation;
        $shipment = $quotation->shipmentRequest;
        $offer = $this->resolveOpenPlatformOffer($quotation, $offer);

        $existingJob = TransportJob::query()->where('quotation_id', $quotation->id)->first();
        if ($existingJob) {
            $this->settleCompletedPayment($payment, $existingJob, $user);

            return $existingJob->load(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization', 'invoices']);
        }

        $job = $this->createAwardedJob($quotation, $shipment, $user, $payment, paid: true, offer: $offer);
        $this->walletLedger->creditPendingEarning($payment, $job, $user);

        return $job;
    }

    private function createAwardedJob(
        Quotation $quotation,
        ShipmentRequest $shipment,
        ?User $user,
        ?Payment $payment,
        bool $paid,
        ?PlatformOffer $offer = null,
    ): TransportJob {
        $offer = $this->resolveOpenPlatformOffer($quotation, $offer);
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

        $amounts = $this->amountsFor($quotation, $payment, $offer);

        $job = TransportJob::query()->create([
            'reference' => ReferenceGenerator::next('JOB', TransportJob::class),
            'shipment_request_id' => $shipment->id,
            'quotation_id' => $quotation->id,
            'customer_organization_id' => $shipment->customer_organization_id,
            'provider_organization_id' => $quotation->provider_organization_id,
            'total_price' => $offer ? $amounts['amount'] : $quotation->total_price,
            'provider_price' => $offer ? $amounts['provider_amount'] : null,
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

        $this->createJobInvoices(
            $job->fresh('trips'),
            $quotation,
            $shipment,
            $payment,
            $paid,
            PaymentTerms::fromShipment($shipment),
            $amounts,
            $offer,
        );

        $quotation->forceFill(['status' => QuotationStatus::Accepted])->save();
        $shipment->quotations()
            ->where('id', '!=', $quotation->id)
            ->where('status', QuotationStatus::Submitted)
            ->update(['status' => QuotationStatus::Rejected]);

        $shipment->forceFill([
            'status' => ShipmentStatus::Awarded,
            'awarded_quotation_id' => $quotation->id,
        ])->save();

        if ($offer && $offer->status !== PlatformOfferStatus::Accepted) {
            $offer->forceFill([
                'status' => PlatformOfferStatus::Accepted,
                'accepted_at' => now(),
            ])->save();
        }

        AuditLogger::record('quotation.accepted', $quotation, [], ['job' => $job->reference], $user);
        AuditLogger::record('job.created', $job, [], $job->toArray(), $user);
        if ($payment && $paid) {
            AuditLogger::record('payment.processed', $payment, [], ['status' => PaymentStatus::Completed->value], $user);
        }

        return $job->fresh(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization', 'invoices']);
    }

    private function settleCompletedPayment(Payment $payment, TransportJob $job, ?User $user, ?Invoice $invoice = null): void
    {
        if ($invoice) {
            $invoice->forceFill([
                'payment_id' => $payment->id,
                'status' => InvoiceStatus::Paid,
            ])->save();

            Invoice::query()
                ->where('transport_job_id', $job->id)
                ->where('trip_id', $invoice->trip_id)
                ->where('type', InvoiceType::Commission)
                ->where('status', InvoiceStatus::Issued)
                ->update([
                    'payment_id' => $payment->id,
                    'status' => InvoiceStatus::Paid,
                ]);
        } else {
            Invoice::query()
                ->where('transport_job_id', $job->id)
                ->whereNull('payment_id')
                ->update(['payment_id' => $payment->id]);

            Invoice::query()
                ->where('transport_job_id', $job->id)
                ->whereIn('type', [InvoiceType::Customer, InvoiceType::Commission])
                ->where('status', InvoiceStatus::Issued)
                ->update(['status' => InvoiceStatus::Paid]);
        }

        $this->walletLedger->creditPendingEarning($payment, $job, $user);

        $tripComplete = $invoice?->trip_id
            && Trip::query()->whereKey($invoice->trip_id)->where('status', TripStatus::Completed)->exists();

        if ($job->status === JobStatus::Completed || $tripComplete) {
            $this->walletLedger->releasePaymentEarning($payment, $job, $user);
        }
    }

    /**
     * @param  array{amount: float, commission_amount: float, provider_amount: float}  $amounts
     */
    private function createJobInvoices(
        TransportJob $job,
        Quotation $quotation,
        ShipmentRequest $shipment,
        ?Payment $payment,
        bool $paid,
        PaymentTerms $terms,
        array $amounts,
        ?PlatformOffer $offer = null,
    ): void {
        $invoiceStatus = $paid ? InvoiceStatus::Paid : InvoiceStatus::Issued;
        $dueAt = $paid ? now() : null;

        if ($terms->isPerTrip() && $job->trips->isNotEmpty()) {
            $weights = $job->trips->map(fn (Trip $trip) => (float) $trip->planned_quantity)->all();
            $slices = $offer
                ? $this->markupSlices((float) $offer->customer_price, (float) $offer->provider_price, $weights)
                : BillingAllocator::split(
                    (float) $quotation->total_price,
                    $quotation->providerOrganization->commissionRate(),
                    $weights,
                );

            foreach ($job->trips as $index => $trip) {
                $slice = $slices[$index];
                $this->storeInvoiceSet(
                    $job,
                    $quotation,
                    $shipment,
                    $payment,
                    $invoiceStatus,
                    $dueAt,
                    $slice,
                    $trip->id,
                );
            }

            return;
        }

        $this->storeInvoiceSet($job, $quotation, $shipment, $payment, $invoiceStatus, $dueAt, $amounts);
    }

    /**
     * @param  array{amount: float, commission_amount: float, provider_amount: float}  $amounts
     */
    private function storeInvoiceSet(
        TransportJob $job,
        Quotation $quotation,
        ShipmentRequest $shipment,
        ?Payment $payment,
        InvoiceStatus $invoiceStatus,
        mixed $dueAt,
        array $amounts,
        ?int $tripId = null,
    ): void {
        Invoice::query()->create([
            'reference' => ReferenceGenerator::next('INV', Invoice::class),
            'organization_id' => $shipment->customer_organization_id,
            'transport_job_id' => $job->id,
            'trip_id' => $tripId,
            'payment_id' => $payment?->id,
            'type' => InvoiceType::Customer,
            'amount' => $amounts['amount'],
            'currency' => $quotation->currency,
            'status' => $invoiceStatus,
            'issued_at' => now(),
            'due_at' => $dueAt,
        ]);

        $quotation->loadMissing('providerOrganization');
        if (! $quotation->providerOrganization?->isPlatform()) {
            Invoice::query()->create([
                'reference' => ReferenceGenerator::next('INV', Invoice::class),
                'organization_id' => $quotation->provider_organization_id,
                'transport_job_id' => $job->id,
                'trip_id' => $tripId,
                'payment_id' => $payment?->id,
                'type' => InvoiceType::Provider,
                'amount' => $amounts['provider_amount'],
                'currency' => $quotation->currency,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
            ]);
        }

        Invoice::query()->create([
            'reference' => ReferenceGenerator::next('INV', Invoice::class),
            'organization_id' => $quotation->provider_organization_id,
            'transport_job_id' => $job->id,
            'trip_id' => $tripId,
            'payment_id' => $payment?->id,
            'type' => InvoiceType::Commission,
            'amount' => $amounts['commission_amount'],
            'currency' => $quotation->currency,
            'status' => $invoiceStatus,
            'issued_at' => now(),
            'due_at' => $dueAt,
        ]);
    }

    private function existingAcceptance(Quotation $quotation): ?QuotationAcceptanceResult
    {
        $existingJob = TransportJob::query()->where('quotation_id', $quotation->id)->first();
        if (! $existingJob) {
            return null;
        }

        $payment = Payment::query()->where('quotation_id', $quotation->id)->first();
        $terms = PaymentTerms::fromShipment($existingJob->shipmentRequest ?? $quotation->shipmentRequest);

        return new QuotationAcceptanceResult(
            payment: $payment,
            requiresCheckout: false,
            job: $existingJob->load(['trips', 'shipmentRequest', 'quotation', 'customerOrganization', 'providerOrganization']),
            paymentDeferred: $terms->isDeferred() && ($payment === null || $payment->status !== PaymentStatus::Completed),
        );
    }

    private function createOrResumePayment(
        Quotation $quotation,
        ShipmentRequest $shipment,
        User $user,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
        ?Invoice $invoice = null,
        ?PlatformOffer $platformOffer = null,
    ): Payment {
        $split = $this->quoteAmounts($quotation, $platformOffer, $invoice);
        $amount = $split['amount'];
        $commission = $split['commission_amount'];
        $providerAmount = $split['provider_amount'];

        $payment = Payment::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'reference' => ReferenceGenerator::next('PAY', Payment::class),
                'shipment_request_id' => $shipment->id,
                'quotation_id' => $quotation->id,
                'invoice_id' => $invoice?->id,
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

        if ($payment->status === PaymentStatus::Failed) {
            $payment->forceFill([
                'status' => PaymentStatus::Processing,
                'method' => $paymentMethod->code,
                'gateway' => $this->gatewayFor($paymentMethod),
            ])->save();
        } elseif ($payment->status !== PaymentStatus::Completed && $payment->method !== $paymentMethod->code) {
            $payment->forceFill([
                'method' => $paymentMethod->code,
                'gateway' => $this->gatewayFor($paymentMethod),
            ])->save();
        }

        return $payment;
    }

    /**
     * @return array{amount: float, commission_amount: float, provider_amount: float}
     */
    private function amountsFor(Quotation $quotation, ?Payment $payment, ?PlatformOffer $offer = null, ?Invoice $invoice = null): array
    {
        if ($payment) {
            return [
                'amount' => (float) $payment->amount,
                'commission_amount' => (float) $payment->commission_amount,
                'provider_amount' => (float) $payment->provider_amount,
            ];
        }

        return $this->quoteAmounts($quotation, $offer, $invoice);
    }

    /**
     * @return array{amount: float, commission_amount: float, provider_amount: float}
     */
    private function quoteAmounts(Quotation $quotation, ?PlatformOffer $offer, ?Invoice $invoice): array
    {
        $quotation->loadMissing('shipmentRequest', 'providerOrganization');

        if ($invoice && $quotation->shipmentRequest?->usesAdminOfferSelection()) {
            return $this->amountsFromInvoices($invoice);
        }

        $offer = $this->resolveOpenPlatformOffer($quotation, $offer);
        if ($offer) {
            return [
                'amount' => (float) $offer->customer_price,
                'commission_amount' => (float) $offer->margin_amount,
                'provider_amount' => (float) $offer->provider_price,
            ];
        }

        $amount = $invoice ? (float) $invoice->amount : (float) $quotation->total_price;
        $commission = round($amount * $quotation->providerOrganization->commissionRate(), 3);

        return [
            'amount' => $amount,
            'commission_amount' => $commission,
            'provider_amount' => round($amount - $commission, 3),
        ];
    }

    /**
     * @return array{amount: float, commission_amount: float, provider_amount: float}
     */
    private function amountsFromInvoices(Invoice $invoice): array
    {
        $scope = Invoice::query()
            ->where('transport_job_id', $invoice->transport_job_id)
            ->when(
                $invoice->trip_id,
                fn ($query) => $query->where('trip_id', $invoice->trip_id),
                fn ($query) => $query->whereNull('trip_id'),
            );

        return [
            'amount' => (float) $invoice->amount,
            'commission_amount' => round((float) (clone $scope)->where('type', InvoiceType::Commission)->value('amount'), 3),
            'provider_amount' => round((float) (clone $scope)->where('type', InvoiceType::Provider)->value('amount'), 3),
        ];
    }

    /**
     * @param  list<float>  $weights
     * @return list<array{amount: float, commission_amount: float, provider_amount: float}>
     */
    private function markupSlices(float $customerAmount, float $providerAmount, array $weights): array
    {
        $customerSlices = BillingAllocator::split($customerAmount, 0, $weights);
        $providerSlices = BillingAllocator::split($providerAmount, 0, $weights);
        $slices = [];

        foreach ($customerSlices as $index => $customerSlice) {
            $providerSliceAmount = $providerSlices[$index]['amount'];
            $slices[] = [
                'amount' => $customerSlice['amount'],
                'provider_amount' => $providerSliceAmount,
                'commission_amount' => round($customerSlice['amount'] - $providerSliceAmount, 3),
            ];
        }

        return $slices;
    }

    private function resolveOpenPlatformOffer(Quotation $quotation, ?PlatformOffer $offer): ?PlatformOffer
    {
        if ($offer) {
            return $offer;
        }

        $quotation->loadMissing('shipmentRequest');
        if (! $quotation->shipmentRequest?->usesAdminOfferSelection()) {
            return null;
        }

        return PlatformOffer::query()
            ->where('quotation_id', $quotation->id)
            ->whereIn('status', [PlatformOfferStatus::Published, PlatformOfferStatus::Accepted])
            ->latest('id')
            ->first();
    }

    private function lockPlatformOffer(?PlatformOffer $offer, Quotation $quotation): ?PlatformOffer
    {
        if (! $offer) {
            return null;
        }

        $locked = PlatformOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
        $this->assertPlatformOfferStillValid($locked, $quotation);

        return $locked;
    }

    private function assertPlatformOfferStillValid(PlatformOffer $offer, Quotation $quotation): void
    {
        if ($offer->status === PlatformOfferStatus::Accepted) {
            return;
        }

        if ($offer->status !== PlatformOfferStatus::Published || (int) $offer->quotation_id !== (int) $quotation->id) {
            throw ValidationException::withMessages([
                'offer' => ['This platform offer is no longer available.'],
            ]);
        }

        if ($quotation->status !== QuotationStatus::Submitted) {
            throw ValidationException::withMessages([
                'offer' => ['The provider quotation is no longer open.'],
            ]);
        }

        $quotation->loadMissing('providerOrganization');
        $expectedPrice = $quotation->providerOrganization?->isPlatform()
            ? (float) $offer->customer_price
            : (float) $offer->provider_price;

        if (round((float) $quotation->total_price, 3) !== round($expectedPrice, 3)) {
            throw ValidationException::withMessages([
                'offer' => ['The provider quotation changed. Publish a new platform offer.'],
            ]);
        }

        if ($quotation->valid_until && $quotation->valid_until->isPast()) {
            $offer->forceFill(['status' => PlatformOfferStatus::Expired])->save();

            throw ValidationException::withMessages([
                'offer' => ['This platform offer has expired.'],
            ]);
        }
    }

    private function captureWithMethod(Payment $payment, PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->isCash()) {
            $this->paymentGateway->captureOffline($payment, 'cash');

            return;
        }

        $this->paymentGateway->capture($payment);
    }

    private function assertInvoicePayable(User $user, Invoice $invoice): void
    {
        if ($invoice->type !== InvoiceType::Customer) {
            throw ValidationException::withMessages([
                'invoice' => ['Only customer invoices can be paid here.'],
            ]);
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'invoice' => ['This invoice has already been paid.'],
            ]);
        }

        if ($invoice->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages([
                'invoice' => ['This invoice cannot be paid.'],
            ]);
        }

        if ($invoice->organization_id !== $user->organization_id) {
            throw ValidationException::withMessages([
                'invoice' => ['You can only pay invoices issued to your organization.'],
            ]);
        }

        $job = $invoice->transportJob;
        $unitComplete = $invoice->trip_id
            ? Trip::query()->whereKey($invoice->trip_id)->where('status', TripStatus::Completed)->exists()
            : ($job && $job->status === JobStatus::Completed);

        if (! $unitComplete || $invoice->due_at === null) {
            throw ValidationException::withMessages([
                'invoice' => ['This invoice becomes payable after the shipment is delivered.'],
            ]);
        }
    }

    private function assertQuotationCanBeAwarded(User $user, Quotation $quotation, ?PlatformOffer $offer = null): void
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

        if ($shipment->usesAdminOfferSelection() && $offer === null) {
            throw ValidationException::withMessages([
                'quotation' => ['Accept the platform offer for this shipment.'],
            ]);
        }

        if ($offer) {
            $this->assertPlatformOfferStillValid($offer, $quotation);
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
