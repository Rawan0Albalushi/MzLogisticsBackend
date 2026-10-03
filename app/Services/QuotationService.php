<?php

namespace App\Services;

use App\Enums\OfferSelectionMode;
use App\Enums\OrganizationStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ListFilters;
use App\Support\QuotationPricing;
use App\Support\ReferenceGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(private readonly PlatformOfferService $platformOffers) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(User $user, ShipmentRequest $shipment, array $payload): Quotation
    {
        if ($shipment->status !== ShipmentStatus::Published) {
            throw ValidationException::withMessages([
                'shipment' => ['Quotations can only be submitted on published requests.'],
            ]);
        }

        $organization = $user->organization;
        if (! $organization || $organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages([
                'organization' => ['The service provider account is not active.'],
            ]);
        }

        $existing = Quotation::query()
            ->where('shipment_request_id', $shipment->id)
            ->where('provider_organization_id', $user->organization_id)
            ->first();

        if ($existing && $existing->status !== QuotationStatus::Withdrawn) {
            throw ValidationException::withMessages([
                'quotation' => ['Your company already submitted a quotation for this request.'],
            ]);
        }

        $quotation = Quotation::query()->updateOrCreate(
            [
                'shipment_request_id' => $shipment->id,
                'provider_organization_id' => $user->organization_id,
            ],
            $this->quotationValues($user, $payload, $existing, false),
        );

        AuditLogger::record('quotation.submitted', $quotation, [], $quotation->toArray(), $user);

        return $quotation->fresh(['providerOrganization', 'shipmentRequest']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitOnBehalf(User $admin, ShipmentRequest $shipment, array $payload): Quotation
    {
        if ($shipment->status !== ShipmentStatus::Published) {
            throw ValidationException::withMessages([
                'shipment' => ['Quotations can only be submitted on published requests.'],
            ]);
        }

        $organization = Organization::query()->find($payload['provider_organization_id']);
        if (! $organization || ! $organization->isProvider() || $organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages([
                'provider_organization_id' => ['Choose an active service provider.'],
            ]);
        }

        $existing = Quotation::query()
            ->where('shipment_request_id', $shipment->id)
            ->where('provider_organization_id', $organization->id)
            ->first();

        if ($existing && $existing->status !== QuotationStatus::Withdrawn) {
            throw ValidationException::withMessages([
                'provider_organization_id' => ['This provider already has a quotation for this request.'],
            ]);
        }

        $quotation = Quotation::query()->updateOrCreate(
            [
                'shipment_request_id' => $shipment->id,
                'provider_organization_id' => $organization->id,
            ],
            $this->quotationValues($admin, $payload, $existing, true),
        );

        AuditLogger::record('quotation.submitted_on_behalf', $quotation, [], $quotation->toArray(), $admin);

        return $quotation->fresh(['providerOrganization', 'shipmentRequest']);
    }

    public function withdraw(User $user, Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted quotations can be withdrawn.'],
            ]);
        }

        $quotation->forceFill(['status' => QuotationStatus::Withdrawn])->save();
        $this->platformOffers->withdrawForQuotation($quotation, $user);
        AuditLogger::record('quotation.withdrawn', $quotation, [], ['status' => $quotation->status->value], $user);

        return $quotation->fresh();
    }

    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Quotation::query()
            ->fromServiceProviders()
            ->with(['shipmentRequest.customerOrganization', 'providerOrganization'])
            ->latest();

        if ($user->user_type === UserType::Provider) {
            $query->where('provider_organization_id', $user->organization_id);
        } elseif ($user->user_type === UserType::Customer) {
            $query->whereHas('shipmentRequest', function ($builder) use ($user) {
                $builder->where('customer_organization_id', $user->organization_id)
                    ->where(function ($mode) {
                        $mode->whereNull('offer_selection_mode')
                            ->orWhere('offer_selection_mode', OfferSelectionMode::Customer->value);
                    });
            });
        } elseif ($user->user_type === UserType::Driver) {
            $query->whereRaw('1 = 0');
        }

        if (! empty($filters['shipment_request_id'])) {
            $query->where('shipment_request_id', $filters['shipment_request_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            ListFilters::search(
                $query,
                $filters['search'],
                ['reference'],
                [
                    'shipmentRequest' => ['reference', 'pickup_city', 'delivery_city', 'cargo_type'],
                    'providerOrganization' => ['name', 'name_ar'],
                ],
            );
        }

        ListFilters::dateRange($query, $filters, 'created_at');

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function quotationValues(User $actor, array $payload, ?Quotation $existing, bool $onBehalf): array
    {
        $pricing = QuotationPricing::resolve($payload);

        return [
            'reference' => $existing?->reference ?? ReferenceGenerator::next('QTN', Quotation::class),
            'created_by' => $actor->id,
            'submitted_on_behalf' => $onBehalf,
            'total_price' => $pricing['total_price'],
            'price_per_trip' => $pricing['price_per_trip'],
            'currency' => $payload['currency'] ?? config('mz.currency'),
            'truck_count' => $payload['truck_count'],
            'truck_type' => $payload['truck_type'],
            'truck_capacity_tons' => $payload['truck_capacity_tons'],
            'trip_count' => $pricing['trip_count'],
            'quantity_per_trip' => $payload['quantity_per_trip'],
            'duration_days' => $payload['duration_days'],
            'transport_start_date' => $payload['transport_start_date'],
            'additional_costs' => $payload['additional_costs'] ?? 0,
            'conditions' => $payload['conditions'] ?? null,
            'valid_until' => now()->addDays((int) config('mz.quotation_validity_days')),
            'status' => QuotationStatus::Submitted,
        ];
    }
}
