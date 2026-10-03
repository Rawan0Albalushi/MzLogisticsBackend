<?php

namespace App\Services;

use App\Enums\DriverPayableStatus;
use App\Models\DriverPayable;
use App\Models\Trip;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ListFilters;
use App\Support\ReferenceGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverPayableService
{
    public function accrue(Trip $trip): void
    {
        $trip->loadMissing('transportJob.providerOrganization');
        $job = $trip->transportJob;

        if (! $job?->providerOrganization?->isPlatform() || ! $trip->driver_user_id) {
            return;
        }

        $amount = round((float) $trip->driver_pay_amount, 3);
        if ($amount <= 0 || DriverPayable::query()->where('trip_id', $trip->id)->exists()) {
            return;
        }

        DriverPayable::query()->create([
            'reference' => ReferenceGenerator::next('DPY', DriverPayable::class),
            'trip_id' => $trip->id,
            'driver_user_id' => $trip->driver_user_id,
            'transport_job_id' => $job->id,
            'amount' => $amount,
            'currency' => $job->currency ?: 'OMR',
            'status' => DriverPayableStatus::Pending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return DriverPayable::query()
            ->with(['driver:id,name', 'trip:id,reference', 'transportJob:id,reference'])
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['job_id']), fn ($query) => $query->where('transport_job_id', $filters['job_id']))
            ->when(! empty($filters['project']), function ($query) use ($filters) {
                $query->whereHas('transportJob', fn ($job) => $job->where('project_id', $filters['project']));
            })
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                ListFilters::search(
                    $query,
                    (string) $filters['search'],
                    ['reference'],
                    [
                        'driver' => ['name'],
                        'trip' => ['reference'],
                        'transportJob' => ['reference'],
                    ],
                );
            })
            ->tap(fn ($query) => ListFilters::dateRange($query, $filters, 'created_at'))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function markPaid(User $user, DriverPayable $payable, ?UploadedFile $receipt = null): DriverPayable
    {
        return DB::transaction(function () use ($user, $payable, $receipt) {
            $locked = DriverPayable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DriverPayableStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['This driver pay is already recorded as paid.'],
                ]);
            }

            $locked->forceFill([
                'status' => DriverPayableStatus::Paid,
                'paid_at' => now(),
                'paid_by' => $user->id,
                'receipt_path' => $receipt?->store('driver-payables/'.$locked->id, 'local'),
            ])->save();

            AuditLogger::record('driver_payable.paid', $locked, [], [
                'amount' => $locked->amount,
                'has_receipt' => $locked->hasReceipt(),
            ], $user);

            return $locked->fresh(['driver:id,name', 'trip:id,reference', 'transportJob:id,reference']);
        });
    }
}
