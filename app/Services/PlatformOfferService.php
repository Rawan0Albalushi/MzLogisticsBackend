<?php

namespace App\Services;

use App\Enums\OfferSelectionMode;
use App\Enums\PlatformOfferStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
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

            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->where('shipment_request_id', $shipment->id)
                ->lockForUpdate()
                ->first();

            if (! $quotation || $quotation->status !== QuotationStatus::Submitted) {
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

            PlatformOffer::query()
                ->where('shipment_request_id', $shipment->id)
                ->where('status', PlatformOfferStatus::Published)
                ->update(['status' => PlatformOfferStatus::Withdrawn->value]);

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

    public function withdraw(User $user, PlatformOffer $offer): PlatformOffer
    {
        if ($offer->status !== PlatformOfferStatus::Published) {
            throw ValidationException::withMessages([
                'status' => ['Only a published platform offer can be withdrawn.'],
            ]);
        }

        $offer->forceFill(['status' => PlatformOfferStatus::Withdrawn])->save();
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
}
