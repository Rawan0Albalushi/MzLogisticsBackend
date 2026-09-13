<?php

namespace App\Services;

use App\Enums\OrganizationStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserType;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class QuotationService
{
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
            [
                'reference' => $existing?->reference ?? ReferenceGenerator::next('QTN', Quotation::class),
                'created_by' => $user->id,
                'total_price' => $payload['total_price'],
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
            ]
        );

        AuditLogger::record('quotation.submitted', $quotation, [], $quotation->toArray(), $user);

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
        AuditLogger::record('quotation.withdrawn', $quotation, [], ['status' => $quotation->status->value], $user);

        return $quotation->fresh();
    }

    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Quotation::query()
            ->with(['shipmentRequest.customerOrganization', 'providerOrganization'])
            ->latest();

        if ($user->user_type === UserType::Provider) {
            $query->where('provider_organization_id', $user->organization_id);
        } elseif ($user->user_type === UserType::Customer) {
            $query->whereHas('shipmentRequest', function ($builder) use ($user) {
                $builder->where('customer_organization_id', $user->organization_id);
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
            \App\Support\ListFilters::search(
                $query,
                $filters['search'],
                ['reference'],
                [
                    'shipmentRequest' => ['reference', 'pickup_city', 'delivery_city', 'cargo_type'],
                    'providerOrganization' => ['name', 'name_ar'],
                ],
            );
        }

        \App\Support\ListFilters::dateRange($query, $filters, 'created_at');

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }
}
