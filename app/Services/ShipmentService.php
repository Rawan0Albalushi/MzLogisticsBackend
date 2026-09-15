<?php

namespace App\Services;

use App\Enums\QuantityUnit;
use App\Enums\ShipmentStatus;
use App\Enums\UserType;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ListFilters;
use App\Support\ReferenceGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ShipmentService
{
    public function __construct(private readonly PaymentContractService $paymentContracts) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $user, array $payload): ShipmentRequest
    {
        $status = ($payload['publish'] ?? false) ? ShipmentStatus::Published : ShipmentStatus::Draft;

        $shipment = ShipmentRequest::query()->create([
            ...$this->attributes($payload),
            'reference' => ReferenceGenerator::next('SHP', ShipmentRequest::class),
            'customer_organization_id' => $user->organization_id,
            'created_by' => $user->id,
            'status' => $status,
            'published_at' => $status === ShipmentStatus::Published ? now() : null,
        ]);

        $this->paymentContracts->snapshotOnto($shipment, $payload);

        AuditLogger::record('shipment.created', $shipment, [], $shipment->toArray(), $user);

        return $shipment->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $user, ShipmentRequest $shipment, array $payload): ShipmentRequest
    {
        if (! in_array($shipment->status, [ShipmentStatus::Draft, ShipmentStatus::Published], true)) {
            throw ValidationException::withMessages([
                'status' => ['This shipment request can no longer be edited.'],
            ]);
        }

        $shipment->fill($this->attributes($payload));
        $shipment->save();
        $this->paymentContracts->snapshotOnto($shipment, $payload);

        AuditLogger::record('shipment.updated', $shipment, [], $shipment->toArray(), $user);

        return $shipment->fresh();
    }

    public function publish(User $user, ShipmentRequest $shipment): ShipmentRequest
    {
        if ($shipment->status !== ShipmentStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => ['Only draft shipment requests can be published.'],
            ]);
        }

        $shipment->forceFill([
            'status' => ShipmentStatus::Published,
            'published_at' => now(),
        ])->save();

        $this->paymentContracts->snapshotOnto($shipment);

        AuditLogger::record('shipment.published', $shipment, [], ['status' => $shipment->status->value], $user);

        return $shipment->fresh();
    }

    public function cancel(User $user, ShipmentRequest $shipment): ShipmentRequest
    {
        if ($shipment->status === ShipmentStatus::Awarded) {
            throw ValidationException::withMessages([
                'status' => ['An awarded shipment cannot be cancelled from this action.'],
            ]);
        }

        $shipment->forceFill(['status' => ShipmentStatus::Cancelled])->save();
        AuditLogger::record('shipment.cancelled', $shipment, [], ['status' => $shipment->status->value], $user);

        return $shipment->fresh();
    }

    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = ShipmentRequest::query()
            ->with(['customerOrganization', 'quotations.providerOrganization'])
            ->latest();

        if ($user->user_type === UserType::Customer) {
            $query->where('customer_organization_id', $user->organization_id);
        } elseif ($user->user_type === UserType::Provider) {
            $query->where('status', ShipmentStatus::Published);
        } elseif ($user->user_type === UserType::Driver) {
            $query->whereRaw('1 = 0');
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            ListFilters::search(
                $query,
                $filters['search'],
                ['reference', 'cargo_type', 'pickup_city', 'delivery_city'],
                ['customerOrganization' => ['name', 'name_ar', 'email']],
            );
        }

        if (! empty($filters['city'])) {
            ListFilters::city($query, $filters['city'], ['pickup_city', 'delivery_city']);
        }

        ListFilters::dateRange($query, $filters, 'required_date');

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributes(array $payload): array
    {
        $unit = QuantityUnit::normalize($payload['quantity_unit'] ?? QuantityUnit::Tons->value);
        $weight = $payload['weight_tons'];

        return [
            'cargo_type' => $payload['cargo_type'],
            'cargo_description' => $payload['cargo_description'] ?? null,
            'weight_tons' => $weight,
            'volume_cbm' => $payload['volume_cbm'] ?? null,
            'quantity' => $unit === QuantityUnit::Tons ? $weight : $payload['quantity'],
            'quantity_unit' => $unit->value,
            'pickup_address' => $payload['pickup_address'] ?? '',
            'pickup_city' => $payload['pickup_city'],
            'pickup_lat' => $payload['pickup_lat'] ?? null,
            'pickup_lng' => $payload['pickup_lng'] ?? null,
            'delivery_address' => $payload['delivery_address'] ?? '',
            'delivery_city' => $payload['delivery_city'],
            'delivery_lat' => $payload['delivery_lat'] ?? null,
            'delivery_lng' => $payload['delivery_lng'] ?? null,
            'required_date' => $payload['required_date'],
            'notes' => $payload['notes'] ?? null,
        ];
    }
}
