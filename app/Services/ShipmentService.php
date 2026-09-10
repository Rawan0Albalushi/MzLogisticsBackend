<?php

namespace App\Services;

use App\Enums\ShipmentStatus;
use App\Enums\UserType;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ShipmentService
{
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

        AuditLogger::record('shipment.created', $shipment, [], $shipment->toArray(), $user);

        return $shipment;
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
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhere('cargo_type', 'like', "%{$search}%")
                    ->orWhere('pickup_city', 'like', "%{$search}%")
                    ->orWhere('delivery_city', 'like', "%{$search}%");
            });
        }

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributes(array $payload): array
    {
        return [
            'cargo_type' => $payload['cargo_type'],
            'cargo_description' => $payload['cargo_description'] ?? null,
            'weight_tons' => $payload['weight_tons'],
            'volume_cbm' => $payload['volume_cbm'] ?? null,
            'quantity' => $payload['quantity'],
            'quantity_unit' => $payload['quantity_unit'] ?? 'ton',
            'pickup_address' => $payload['pickup_address'],
            'pickup_city' => $payload['pickup_city'],
            'pickup_lat' => $payload['pickup_lat'] ?? null,
            'pickup_lng' => $payload['pickup_lng'] ?? null,
            'delivery_address' => $payload['delivery_address'],
            'delivery_city' => $payload['delivery_city'],
            'delivery_lat' => $payload['delivery_lat'] ?? null,
            'delivery_lng' => $payload['delivery_lng'] ?? null,
            'required_date' => $payload['required_date'],
            'notes' => $payload['notes'] ?? null,
        ];
    }
}
