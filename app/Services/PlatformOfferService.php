<?php

namespace App\Services;

use App\Enums\OfferSelectionMode;
use App\Enums\PlatformOfferStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Models\Organization;
use App\Models\PlatformOffer;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformOfferService
{
    public function publish(User $user, ShipmentRequest $shipment, int $quotationId, float $customerPrice): PlatformOffer
    {
        return DB::transaction(function () use ($user, $shipment, $quotationId, $customerPrice) {
            $shipment = $this->lockOpenAdminShipment($shipment);
            $this->replacePublishedOffers($shipment);

            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->where('shipment_request_id', $shipment->id)
                ->lockForUpdate()
                ->first();

            $quotation?->load('providerOrganization');

            if (! $quotation || $quotation->status !== QuotationStatus::Submitted || $quotation->providerOrganization?->isPlatform()) {
                throw ValidationException::withMessages([
                    'quotation_id' => ['Choose a submitted provider quotation for this shipment.'],
                ]);
            }

            if ($quotation->valid_until && $quotation->valid_until->isPast()) {
                throw ValidationException::withMessages([
                    'quotation_id' => ['This provider quotation has expired.'],
                ]);
            }

            $providerPrice = round((float) $quotation->total_price, 3);
            $customerPrice = round($customerPrice, 3);

            if ($customerPrice < $providerPrice) {
                throw ValidationException::withMessages([
                    'customer_price' => ['The customer price must be at least the provider quotation.'],
                ]);
            }

            $offer = PlatformOffer::query()->create([
                'reference' => ReferenceGenerator::next('PLO', PlatformOffer::class),
                'shipment_request_id' => $shipment->id,
                'quotation_id' => $quotation->id,
                'created_by' => $user->id,
                'provider_price' => $providerPrice,
                'customer_price' => $customerPrice,
                'margin_amount' => round($customerPrice - $providerPrice, 3),
                'currency' => $quotation->currency,
                'truck_count' => $quotation->truck_count,
                'truck_type' => is_object($quotation->truck_type) ? $quotation->truck_type->value : $quotation->truck_type,
                'truck_capacity_tons' => $quotation->truck_capacity_tons,
                'trip_count' => $quotation->trip_count,
                'quantity_per_trip' => $quotation->quantity_per_trip,
                'duration_days' => $quotation->duration_days,
                'conditions' => $quotation->conditions,
                'valid_until' => $quotation->valid_until,
                'status' => PlatformOfferStatus::Published,
                'published_at' => now(),
            ]);

            AuditLogger::record('platform_offer.published', $offer, [], $offer->toArray(), $user);

            return $offer->fresh(['quotation.providerOrganization', 'shipmentRequest']);
        });
    }

    /**
     * @param  array{total_price: float|int|string, currency?: string|null, truck_count: int, truck_type: string, truck_capacity_tons: float|int|string, trip_count: int, quantity_per_trip: float|int|string, duration_days: int, additional_costs?: float|int|string|null, conditions?: string|null}  $payload
     */
    public function publishOwned(User $user, ShipmentRequest $shipment, array $payload): PlatformOffer
    {
        return DB::transaction(function () use ($user, $shipment, $payload) {
            $shipment = $this->lockOpenAdminShipment($shipment);
            $this->replacePublishedOffers($shipment);

            $platform = Organization::platform();
            $customerPrice = round((float) $payload['total_price'], 3);
            $existing = Quotation::query()
                ->where('shipment_request_id', $shipment->id)
                ->where('provider_organization_id', $platform->id)
                ->lockForUpdate()
                ->first();

            $quotation = Quotation::query()->updateOrCreate(
                [
                    'shipment_request_id' => $shipment->id,
                    'provider_organization_id' => $platform->id,
                ],
                [
                    'reference' => $existing?->reference ?? ReferenceGenerator::next('QTN', Quotation::class),
                    'created_by' => $user->id,
                    'total_price' => $customerPrice,
                    'currency' => $payload['currency'] ?? config('mz.currency'),
                    'truck_count' => $payload['truck_count'],
                    'truck_type' => $payload['truck_type'],
                    'truck_capacity_tons' => $payload['truck_capacity_tons'],
                    'trip_count' => max((int) $payload['truck_count'], (int) $payload['trip_count']),
                    'quantity_per_trip' => $payload['quantity_per_trip'],
                    'duration_days' => $payload['duration_days'],
                    'additional_costs' => $payload['additional_costs'] ?? 0,
                    'conditions' => $payload['conditions'] ?? null,
                    'valid_until' => now()->addDays((int) config('mz.quotation_validity_days')),
                    'status' => QuotationStatus::Submitted,
                ],
            );

            $offer = PlatformOffer::query()->create([
                'reference' => ReferenceGenerator::next('PLO', PlatformOffer::class),
                'shipment_request_id' => $shipment->id,
                'quotation_id' => $quotation->id,
                'created_by' => $user->id,
                'provider_price' => 0,
                'customer_price' => $customerPrice,
                'margin_amount' => $customerPrice,
                'currency' => $quotation->currency,
                'truck_count' => $quotation->truck_count,
                'truck_type' => is_object($quotation->truck_type) ? $quotation->truck_type->value : $quotation->truck_type,
                'truck_capacity_tons' => $quotation->truck_capacity_tons,
                'trip_count' => $quotation->trip_count,
                'quantity_per_trip' => $quotation->quantity_per_trip,
                'duration_days' => $quotation->duration_days,
                'conditions' => $quotation->conditions,
                'valid_until' => $quotation->valid_until,
                'status' => PlatformOfferStatus::Published,
                'published_at' => now(),
            ]);

            AuditLogger::record('platform_offer.published', $offer, [], $offer->toArray(), $user);

            return $offer->fresh(['quotation.providerOrganization', 'shipmentRequest']);
        });
    }

    public function withdraw(User $user, PlatformOffer $offer): PlatformOffer
    {
        if ($offer->status !== PlatformOfferStatus::Published) {
            throw ValidationException::withMessages([
                'status' => ['Only a published platform offer can be withdrawn.'],
            ]);
        }

        $offer->load('quotation.providerOrganization');
        $offer->forceFill(['status' => PlatformOfferStatus::Withdrawn])->save();
        $this->withdrawPlatformQuotation($offer->quotation, $user);
        AuditLogger::record('platform_offer.withdrawn', $offer, [], ['status' => $offer->status->value], $user);

        return $offer->fresh();
    }

    public function withdrawForQuotation(Quotation $quotation, ?User $user = null): void
    {
        $offers = PlatformOffer::query()
            ->where('quotation_id', $quotation->id)
            ->where('status', PlatformOfferStatus::Published)
            ->get();

        foreach ($offers as $offer) {
            $offer->forceFill(['status' => PlatformOfferStatus::Withdrawn])->save();
            AuditLogger::record('platform_offer.withdrawn', $offer, [], ['reason' => 'quotation.withdrawn'], $user);
        }
    }

    private function lockOpenAdminShipment(ShipmentRequest $shipment): ShipmentRequest
    {
        $shipment = ShipmentRequest::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

        if ($shipment->offer_selection_mode !== OfferSelectionMode::Admin) {
            throw ValidationException::withMessages([
                'shipment' => ['Platform offers can only be published when the shipment uses admin selection.'],
            ]);
        }

        if ($shipment->status !== ShipmentStatus::Published) {
            throw ValidationException::withMessages([
                'shipment' => ['Platform offers can only be published on open shipment requests.'],
            ]);
        }

        return $shipment;
    }

    private function replacePublishedOffers(ShipmentRequest $shipment): void
    {
        $offers = PlatformOffer::query()
            ->where('shipment_request_id', $shipment->id)
            ->where('status', PlatformOfferStatus::Published)
            ->with('quotation.providerOrganization')
            ->lockForUpdate()
            ->get();

        foreach ($offers as $offer) {
            $offer->forceFill(['status' => PlatformOfferStatus::Withdrawn])->save();
            $this->withdrawPlatformQuotation($offer->quotation);
        }
    }

    private function withdrawPlatformQuotation(?Quotation $quotation, ?User $user = null): void
    {
        if (! $quotation || $quotation->status !== QuotationStatus::Submitted) {
            return;
        }

        $quotation->loadMissing('providerOrganization');
        if (! $quotation->providerOrganization?->isPlatform()) {
            return;
        }

        $quotation->forceFill(['status' => QuotationStatus::Withdrawn])->save();
        AuditLogger::record('quotation.withdrawn', $quotation, [], ['status' => $quotation->status->value], $user);
    }
}
