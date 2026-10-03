<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\JobStatus;
use App\Enums\TripStatus;
use App\Enums\TruckStatus;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Organization;
use App\Models\ProofOfDelivery;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\Truck;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ListFilters;
use App\Support\ReferenceGenerator;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TripService
{
    public function __construct(
        private readonly WalletLedgerService $walletLedger,
        private readonly JobOrchestrationService $jobs,
        private readonly DriverPayableService $driverPayables,
    ) {}

    /**
     * @param  array{truck_id: int, driver_id: int, departure_time: string, departure_date?: string|null, driver_pay_amount?: float|int|string|null}  $payload
     */
    public function assign(User $user, Trip $trip, array $payload): Trip
    {
        if (! in_array($trip->status, [TripStatus::Unassigned, TripStatus::Assigned], true)) {
            throw ValidationException::withMessages([
                'status' => ['This trip can no longer be reassigned.'],
            ]);
        }

        $organizationId = $user->isPlatform()
            ? Organization::platform()->id
            : (int) $user->organization_id;

        abort_unless((int) $trip->transportJob?->provider_organization_id === $organizationId, 403);

        $truck = Truck::query()
            ->where('organization_id', $organizationId)
            ->findOrFail($payload['truck_id']);

        $driver = User::query()
            ->where('organization_id', $organizationId)
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

        $departure = $this->scheduledDeparture(
            $trip,
            (string) ($payload['departure_time'] ?? ''),
            isset($payload['departure_date']) ? (string) $payload['departure_date'] : null,
        );
        $driverPay = $user->isPlatform() ? $this->resolveDriverPay($profile, $payload) : null;

        return DB::transaction(function () use ($user, $trip, $truck, $driver, $profile, $departure, $driverPay) {
            $trip->forceFill([
                'truck_id' => $truck->id,
                'driver_user_id' => $driver->id,
                'assigned_by' => $user->id,
                'driver_pay_amount' => $driverPay,
                'status' => TripStatus::Assigned,
                'assigned_at' => now(),
                'scheduled_departure_at' => $departure,
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
                'driver_pay_amount' => $driverPay,
                'scheduled_departure_at' => $departure->toIso8601String(),
            ], $user);

            return $trip->fresh(['truck', 'driver.driverProfile', 'transportJob']);
        });
    }

    /**
     * @param  array{trailer_plate?: string|null, delivery_note_number?: string|null, operations_notes?: string|null}  $payload
     */
    public function updateOperations(User $user, Trip $trip, array $payload): Trip
    {
        if ($trip->status === TripStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => ['A cancelled trip cannot be updated.'],
            ]);
        }

        $changes = [];
        foreach (['trailer_plate', 'delivery_note_number', 'operations_notes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $changes[$field] = $payload[$field];
            }
        }

        $before = $trip->only(array_keys($changes));
        $trip->forceFill($changes)->save();

        AuditLogger::record('trip.operations_updated', $trip, $before, $changes, $user);

        return $trip->fresh(['truck', 'driver.driverProfile', 'transportJob', 'proofOfDelivery', 'driverPayable']);
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
    public function submitProof(
        User $user,
        Trip $trip,
        array $payload,
        array $photos = [],
        ?UploadedFile $signature = null,
        ?UploadedFile $invoice = null,
        ?UploadedFile $weightTicket = null,
    ): ProofOfDelivery {
        if ($trip->status !== TripStatus::Arrived && $trip->status !== TripStatus::Delivered) {
            throw ValidationException::withMessages([
                'status' => ['Proof of delivery can be submitted after arrival.'],
            ]);
        }

        $otp = trim((string) ($payload['otp'] ?? ''));
        $otpVerified = false;
        if ($otp !== '') {
            if (! $this->otpMatches($trip, $otp)) {
                throw ValidationException::withMessages([
                    'otp' => ['The delivery OTP is invalid.'],
                ]);
            }
            $otpVerified = true;
        } elseif (! $user->isPlatform() && ! $this->otpMatches($trip, $otp)) {
            throw ValidationException::withMessages([
                'otp' => ['The delivery OTP is invalid.'],
            ]);
        }

        $receivedQuantity = $payload['received_quantity'] ?? null;
        if ($receivedQuantity === '') {
            $receivedQuantity = null;
        }

        return DB::transaction(function () use ($user, $trip, $payload, $photos, $signature, $invoice, $weightTicket, $otpVerified, $receivedQuantity) {
            $existing = $trip->proofOfDelivery;
            $directory = "pods/{$trip->id}";

            $photoPaths = [];
            foreach ($photos as $photo) {
                $photoPaths[] = $photo->store("{$directory}/photos", 'local');
            }

            $signaturePath = $signature?->store($directory, 'local');
            $invoicePath = $this->storePodAttachment($invoice, $existing?->invoice_path, "{$directory}/documents");
            $weightTicketPath = $this->storePodAttachment($weightTicket, $existing?->weight_ticket_path, "{$directory}/documents");

            $receiverName = trim((string) ($payload['receiver_name'] ?? ''));

            $pod = ProofOfDelivery::query()->updateOrCreate(
                ['trip_id' => $trip->id],
                [
                    'receiver_name' => $receiverName !== '' ? $receiverName : null,
                    'otp_verified' => $otpVerified,
                    'photo_paths' => $photoPaths,
                    'received_quantity' => $receivedQuantity ?? $trip->planned_quantity ?? 0,
                    'signature_path' => $signaturePath,
                    'invoice_path' => $invoicePath,
                    'weight_ticket_path' => $weightTicketPath,
                    'notes' => $payload['notes'] ?? null,
                    'lat' => $payload['lat'] ?? $trip->current_lat,
                    'lng' => $payload['lng'] ?? $trip->current_lng,
                    'captured_at' => now(),
                ]
            );

            if ($receivedQuantity !== null) {
                $trip->forceFill([
                    'delivered_quantity' => $receivedQuantity,
                ])->save();
            }

            if ($trip->status === TripStatus::Arrived) {
                $trip = $this->transition($user, $trip->fresh(), TripStatus::Delivered);
            }

            if ($trip->status === TripStatus::Delivered) {
                $this->transition($user, $trip, TripStatus::Completed);
            }

            AuditLogger::record('pod.created', $pod, [], $pod->toArray(), $user);

            return $pod->fresh('trip');
        });
    }

    public function attachPodDocuments(User $user, Trip $trip, ?UploadedFile $invoice = null, ?UploadedFile $weightTicket = null): ProofOfDelivery
    {
        $trip->loadMissing('proofOfDelivery');
        $pod = $trip->proofOfDelivery;

        if (! $pod) {
            throw ValidationException::withMessages([
                'documents' => ['Record proof of delivery before uploading these documents.'],
            ]);
        }

        return DB::transaction(function () use ($user, $trip, $pod, $invoice, $weightTicket) {
            $directory = "pods/{$trip->id}/documents";
            $pod->forceFill([
                'invoice_path' => $this->storePodAttachment($invoice, $pod->invoice_path, $directory),
                'weight_ticket_path' => $this->storePodAttachment($weightTicket, $pod->weight_ticket_path, $directory),
            ])->save();

            AuditLogger::record('pod.documents_uploaded', $pod, [], [
                'invoice' => $invoice !== null,
                'weight_ticket' => $weightTicket !== null,
            ], $user);

            return $pod->fresh();
        });
    }

    public function streamPodPhoto(Trip $trip, int $index): StreamedResponse
    {
        $trip->loadMissing('proofOfDelivery');
        $paths = array_values($trip->proofOfDelivery?->photo_paths ?? []);
        $path = $paths[$index] ?? null;

        return $this->streamPodFile(is_string($path) ? $path : null);
    }

    public function streamPodSignature(Trip $trip): StreamedResponse
    {
        $trip->loadMissing('proofOfDelivery');

        return $this->streamPodFile($trip->proofOfDelivery?->signature_path);
    }

    public function streamPodInvoice(Trip $trip): StreamedResponse
    {
        $trip->loadMissing('proofOfDelivery');

        return $this->streamPodFile($trip->proofOfDelivery?->invoice_path);
    }

    public function streamPodWeightTicket(Trip $trip): StreamedResponse
    {
        $trip->loadMissing('proofOfDelivery');

        return $this->streamPodFile($trip->proofOfDelivery?->weight_ticket_path);
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
                'transportJob.project',
                'transportJob.shipmentRequest',
                'truck',
                'driver.driverProfile',
                'proofOfDelivery',
                'driverPayable',
                'customerInvoice.payment',
                'customerInvoice.sourcePayment',
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
            ListFilters::search(
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
            ListFilters::city($query, $filters['city'], ['pickup_city', 'delivery_city']);
        }

        ListFilters::dateRange($query, $filters, 'created_at');

        return $query->paginate((int) ($filters['per_page'] ?? 15));
    }

    private function completeTripEffects(Trip $trip): void
    {
        $trip->load(['transportJob.trips', 'truck', 'driver.driverProfile']);

        $job = $trip->transportJob;
        $delivered = (float) $job->trips()->sum('delivered_quantity');
        $job->forceFill(['delivered_quantity' => $delivered])->save();
        $this->jobs->openDeliveredTripInvoice($trip);

        $allComplete = $job->trips->every(fn (Trip $item) => in_array($item->status, [TripStatus::Completed, TripStatus::Cancelled], true));
        if ($allComplete) {
            $job->forceFill([
                'status' => JobStatus::Completed,
                'completed_at' => now(),
            ])->save();
            $this->walletLedger->releaseCompletedJob($job->fresh());
            $this->jobs->openDeferredInvoices($job->fresh());
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

        $this->driverPayables->accrue($trip);
    }

    /**
     * @param  array{driver_pay_amount?: float|int|string|null}  $payload
     */
    private function resolveDriverPay(DriverProfile $profile, array $payload): float
    {
        $raw = $payload['driver_pay_amount'] ?? null;
        if ($raw === null || $raw === '') {
            $raw = $profile->trip_rate;
        }

        if ($raw === null || $raw === '') {
            throw ValidationException::withMessages([
                'driver_pay_amount' => ['Enter the driver pay for this trip.'],
            ]);
        }

        return round((float) $raw, 3);
    }

    private function scheduledDeparture(Trip $trip, string $time, ?string $date = null): CarbonInterface
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages([
                'departure_time' => ['The departure time must use the 24-hour format HH:MM.'],
            ]);
        }

        $override = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
        $serviceDate = $override ? $date : $trip->plannedServiceDate();
        if ($serviceDate === null || $serviceDate === '') {
            throw ValidationException::withMessages([
                'departure_date' => ['Choose the departure date for this trip.'],
            ]);
        }

        $zone = (string) config('mz.business_timezone', 'Asia/Muscat');
        $departure = Carbon::createFromFormat('Y-m-d H:i:s', $serviceDate.' '.$time.':00', $zone);
        if (! $departure instanceof CarbonInterface) {
            throw ValidationException::withMessages([
                'departure_date' => ['The departure date is not valid.'],
            ]);
        }

        if ($departure->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                ($override ? 'departure_date' : 'departure_time') => ['The departure must be later than now.'],
            ]);
        }

        return $departure->timezone((string) config('app.timezone'));
    }

    private function otpMatches(Trip $trip, string $provided): bool
    {
        if ($trip->otp_code !== null && $trip->otp_code !== '' && hash_equals((string) $trip->otp_code, $provided)) {
            return true;
        }

        if (! config('mz.allow_test_otp')) {
            return false;
        }

        $testOtp = (string) config('mz.test_otp', '123456');

        return $testOtp !== '' && hash_equals($testOtp, $provided);
    }

    private function storePodAttachment(?UploadedFile $file, ?string $existingPath, string $directory): ?string
    {
        if ($file === null) {
            return $existingPath;
        }

        if (filled($existingPath)) {
            Storage::disk('local')->delete($existingPath);
        }

        return $file->store($directory, 'local');
    }

    private function streamPodFile(?string $path): StreamedResponse
    {
        abort_unless(filled($path), 404);

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, [
                    'Cache-Control' => 'private, max-age=3600',
                ]);
            }
        }

        abort(404);
    }
}
