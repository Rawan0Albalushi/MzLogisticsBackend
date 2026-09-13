<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\JobStatus;
use App\Enums\TripStatus;
use App\Enums\TruckStatus;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\ProofOfDelivery;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\Truck;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TripService
{
    public function __construct(private readonly WalletLedgerService $walletLedger) {}

    /**
     * @param  array{truck_id: int, driver_id: int}  $payload
     */
    public function assign(User $user, Trip $trip, array $payload): Trip
    {
        if (! in_array($trip->status, [TripStatus::Unassigned, TripStatus::Assigned], true)) {
            throw ValidationException::withMessages([
                'status' => ['This trip can no longer be reassigned.'],
            ]);
        }

        $truck = Truck::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($payload['truck_id']);

        $driver = User::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_type', UserType::Driver)
            ->findOrFail($payload['driver_id']);

        $profile = $driver->driverProfile;
        if (! $profile || $profile->status === DriverStatus::Inactive) {
            throw ValidationException::withMessages([
                'driver_id' => ['The selected driver is not available.'],
            ]);
        }

        if ($truck->status === TruckStatus::Maintenance || $truck->status === TruckStatus::Inactive) {
            throw ValidationException::withMessages([
                'truck_id' => ['The selected truck is not available.'],
            ]);
        }

        if ((float) $truck->capacity_tons + 0.0001 < (float) $trip->planned_quantity) {
            throw ValidationException::withMessages([
                'truck_id' => ['Truck capacity is below the planned trip quantity.'],
            ]);
        }

        $busyTruck = Trip::query()
            ->where('truck_id', $truck->id)
            ->where('id', '!=', $trip->id)
            ->whereNotIn('status', [TripStatus::Completed, TripStatus::Cancelled])
            ->exists();

        if ($busyTruck) {
            throw ValidationException::withMessages([
                'truck_id' => ['This truck is already assigned to an active trip.'],
            ]);
        }

        $busyDriver = Trip::query()
            ->where('driver_user_id', $driver->id)
            ->where('id', '!=', $trip->id)
            ->whereNotIn('status', [TripStatus::Completed, TripStatus::Cancelled])
            ->exists();

        if ($busyDriver) {
            throw ValidationException::withMessages([
                'driver_id' => ['This driver is already assigned to an active trip.'],
            ]);
        }

        return DB::transaction(function () use ($user, $trip, $truck, $driver, $profile) {
            $trip->forceFill([
                'truck_id' => $truck->id,
                'driver_user_id' => $driver->id,
                'assigned_by' => $user->id,
                'status' => TripStatus::Assigned,
                'assigned_at' => now(),
                'otp_code' => $trip->otp_code ?: ReferenceGenerator::otp(),
            ])->save();

            $truck->forceFill([
                'status' => TruckStatus::Assigned,
                'assigned_driver_id' => $driver->id,
            ])->save();

            $profile->forceFill(['status' => DriverStatus::OnTrip])->save();

            $job = $trip->transportJob;
            if ($job->status === JobStatus::PendingDispatch) {
                $job->forceFill([
                    'status' => JobStatus::InProgress,
                    'started_at' => $job->started_at ?? now(),
                ])->save();
            }

            AuditLogger::record('trip.assigned', $trip, [], [
                'truck_id' => $truck->id,
                'driver_id' => $driver->id,
            ], $user);

            return $trip->fresh(['truck', 'driver', 'transportJob']);
        });
    }

    public function transition(User $user, Trip $trip, TripStatus $next): Trip
    {
        if (! $trip->status->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot change trip status from {$trip->status->value} to {$next->value}."],
            ]);
        }

        if ($user->isDriver() && $trip->driver_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'trip' => ['You can only update trips assigned to you.'],
            ]);
        }

        $timestamps = match ($next) {
            TripStatus::ArrivedAtPickup => ['arrived_pickup_at' => now()],
            TripStatus::Loaded => ['loaded_at' => now()],
            TripStatus::InTransit => ['in_transit_at' => now()],
            TripStatus::Arrived => ['arrived_at' => now()],
            TripStatus::Delivered => ['delivered_at' => now()],
            TripStatus::Completed => ['completed_at' => now()],
            default => [],
        };

        $trip->forceFill(['status' => $next, ...$timestamps])->save();
        AuditLogger::record('trip.status_changed', $trip, [], ['status' => $next->value], $user);

        if ($next === TripStatus::Completed) {
            $this->completeTripEffects($trip);
        }

        return $trip->fresh(['truck', 'driver', 'proofOfDelivery', 'transportJob']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, UploadedFile>  $photos
     */
    public function submitProof(User $user, Trip $trip, array $payload, array $photos = [], ?UploadedFile $signature = null): ProofOfDelivery
    {
        if ($trip->status !== TripStatus::Arrived && $trip->status !== TripStatus::Delivered) {
            throw ValidationException::withMessages([
                'status' => ['Proof of delivery can be submitted after arrival.'],
            ]);
        }

        if (($payload['otp'] ?? null) !== $trip->otp_code) {
            throw ValidationException::withMessages([
                'otp' => ['The delivery OTP is invalid.'],
            ]);
        }

        return DB::transaction(function () use ($user, $trip, $payload, $photos, $signature) {
            $photoPaths = [];
            foreach ($photos as $index => $photo) {
                $photoPaths[] = $photo->store("pods/{$trip->id}/photos", 'local');
            }

            $signaturePath = $signature?->store("pods/{$trip->id}", 'local');

            $pod = ProofOfDelivery::query()->updateOrCreate(
                ['trip_id' => $trip->id],
                [
                    'receiver_name' => $payload['receiver_name'],
                    'otp_verified' => true,
                    'photo_paths' => $photoPaths,
                    'received_quantity' => $payload['received_quantity'],
                    'signature_path' => $signaturePath,
                    'notes' => $payload['notes'] ?? null,
                    'lat' => $payload['lat'] ?? $trip->current_lat,
                    'lng' => $payload['lng'] ?? $trip->current_lng,
                    'captured_at' => now(),
                ]
            );

            $trip->forceFill([
                'delivered_quantity' => $payload['received_quantity'],
            ])->save();

            if ($trip->status === TripStatus::Arrived) {
                $this->transition($user, $trip->fresh(), TripStatus::Delivered);
            }

            AuditLogger::record('pod.created', $pod, [], $pod->toArray(), $user);

            return $pod->fresh('trip');
        });
    }

    public function recordLocation(User $user, Trip $trip, float $lat, float $lng, ?string $etaAt = null): Trip
    {
        if ($user->isDriver() && $trip->driver_user_id !== $user->id) {
            throw ValidationException::withMessages([
                'trip' => ['You can only update location for your assigned trips.'],
            ]);
        }

        TripLocation::query()->create([
            'trip_id' => $trip->id,
            'lat' => $lat,
            'lng' => $lng,
            'recorded_at' => now(),
        ]);

        $trip->forceFill([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'eta_at' => $etaAt,
        ])->save();

        return $trip->fresh();
    }

    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Trip::query()
            ->with([
                'transportJob.customerOrganization',
                'transportJob.providerOrganization',
                'transportJob.quotation',
                'truck',
                'driver',
                'proofOfDelivery',
            ])
            ->latest();

        if ($user->isDriver()) {
            $query->where('driver_user_id', $user->id);
        } elseif ($user->isProvider()) {
            $query->whereHas('transportJob', fn ($builder) => $builder->where('provider_organization_id', $user->organization_id));
        } elseif ($user->isCustomer()) {
            $query->whereHas('transportJob', fn ($builder) => $builder->where('customer_organization_id', $user->organization_id));
        }

        if (! empty($filters['job_id'])) {
            $query->where('transport_job_id', $filters['job_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            \App\Support\ListFilters::search(
                $query,
                $filters['search'],
                ['reference', 'pickup_city', 'delivery_city'],
                [
                    'transportJob' => ['reference'],
                    'driver' => ['name', 'email', 'phone'],
                    'truck' => ['plate_number', 'make', 'model'],
                ],
            );
        }

        if (! empty($filters['city'])) {
            \App\Support\ListFilters::city($query, $filters['city'], ['pickup_city', 'delivery_city']);
        }

        \App\Support\ListFilters::dateRange($query, $filters, 'created_at');

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function completeTripEffects(Trip $trip): void
    {
        $trip->load(['transportJob.trips', 'truck', 'driver.driverProfile']);

        $job = $trip->transportJob;
        $delivered = (float) $job->trips()->sum('delivered_quantity');
        $job->forceFill(['delivered_quantity' => $delivered])->save();

        $allComplete = $job->trips->every(fn (Trip $item) => in_array($item->status, [TripStatus::Completed, TripStatus::Cancelled], true));
        if ($allComplete) {
            $job->forceFill([
                'status' => JobStatus::Completed,
                'completed_at' => now(),
            ])->save();
            $this->walletLedger->releaseCompletedJob($job->fresh());
            AuditLogger::record('job.completed', $job);
        }

        if ($trip->truck && ! Trip::query()->where('truck_id', $trip->truck_id)->whereNotIn('status', [TripStatus::Completed, TripStatus::Cancelled])->exists()) {
            $trip->truck->forceFill([
                'status' => TruckStatus::Available,
                'assigned_driver_id' => null,
            ])->save();
        }

        if ($trip->driver?->driverProfile && ! Trip::query()->where('driver_user_id', $trip->driver_user_id)->whereNotIn('status', [TripStatus::Completed, TripStatus::Cancelled])->exists()) {
            $trip->driver->driverProfile->forceFill(['status' => DriverStatus::Available])->save();
        }
    }
}
