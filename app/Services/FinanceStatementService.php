<?php

namespace App\Services;

use App\Enums\DriverPayableStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Models\DriverPayable;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TransportJob;
use App\Support\ListFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash profit for the platform, read from payments, invoices, and driver payables.
 *
 * A marketplace job earns the collected commission. The provider share is reported and left out of net profit.
 * A platform-fleet job earns the collected customer amount, and its cost is driver pay that was paid on a trip
 * that was not cancelled.
 */
class FinanceStatementService
{
    /**
     * @param  array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     project_id?: int|string|null,
     *     unassigned?: bool|null,
     *     search?: string|null,
     *     job_id?: int|null
     * }  $filters
     * @return array{
     *     currency: string,
     *     summary: array<string, float>,
     *     projects: list<array<string, mixed>>,
     *     jobs: list<array<string, mixed>>
     * }
     */
    public function build(array $filters = []): array
    {
        $from = $this->date($filters['date_from'] ?? null);
        $to = $this->date($filters['date_to'] ?? null);
        $singleJob = filled($filters['job_id'] ?? null);

        $jobs = TransportJob::query()
            ->with(['project', 'customerOrganization', 'providerOrganization'])
            ->when($singleJob, fn ($query) => $query->whereKey($filters['job_id']))
            ->when(! empty($filters['unassigned']), fn ($query) => $query->whereNull('project_id'))
            ->when(
                empty($filters['unassigned']) && ! empty($filters['project_id']),
                fn ($query) => $query->where('project_id', (int) $filters['project_id']),
            )
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                ListFilters::search(
                    $query,
                    $filters['search'],
                    ['reference'],
                    [
                        'customerOrganization' => ['name', 'name_ar'],
                        'project' => ['project_id', 'name_en', 'name_ar'],
                    ],
                );
            })
            ->get();

        $metrics = $this->metricsFor($jobs, $from, $to);
        $rows = [];

        foreach ($jobs as $job) {
            $amounts = $metrics[$job->id] ?? $this->blank();
            if (! $singleJob && ! $this->hasActivity($amounts)) {
                continue;
            }

            $rows[] = $this->jobRow($job, $amounts);
        }

        usort($rows, fn (array $left, array $right): int => strcmp((string) $right['reference'], (string) $left['reference']));

        return [
            'currency' => (string) config('mz.currency'),
            'summary' => $this->summarize($rows),
            'projects' => $this->projects($rows),
            'jobs' => $rows,
        ];
    }

    /**
     * Lifetime cash result for one job, using the same split as the statement.
     *
     * @return array<string, float|string>
     */
    public function forJob(TransportJob $job): array
    {
        $row = $this->build(['job_id' => $job->id])['jobs'][0] ?? null;

        return [
            'execution' => $row['execution'] ?? ($this->isFleet($job) ? 'fleet' : 'marketplace'),
            'currency' => $row['currency'] ?? (string) ($job->currency ?: config('mz.currency')),
            'collected' => $row['collected'] ?? 0.0,
            'platform_revenue' => $row['platform_revenue'] ?? 0.0,
            'provider_share' => $row['provider_share'] ?? 0.0,
            'driver_expense' => $row['driver_expense'] ?? 0.0,
            'net_profit' => $row['net_profit'] ?? 0.0,
            'customer_outstanding' => $row['customer_outstanding'] ?? 0.0,
            'driver_outstanding' => $row['driver_outstanding'] ?? 0.0,
        ];
    }

    /**
     * @param  Collection<int, TransportJob>  $jobs
     * @return array<int, array<string, float>>
     */
    private function metricsFor(Collection $jobs, ?string $from, ?string $to): array
    {
        /** @var array<int, array<string, float>> $metrics */
        $metrics = [];
        foreach ($jobs as $job) {
            $metrics[$job->id] = $this->blank();
        }

        if ($jobs->isEmpty()) {
            return $metrics;
        }

        $jobsByQuotation = $jobs->keyBy('quotation_id');
        $fleetIds = $jobs->filter(fn (TransportJob $job): bool => $this->isFleet($job))->pluck('id');

        Payment::query()
            ->whereIn('quotation_id', $jobs->pluck('quotation_id')->filter()->all())
            ->whereIn('status', [PaymentStatus::Completed->value, PaymentStatus::Refunded->value])
            ->orderBy('id')
            ->get()
            ->each(function (Payment $payment) use (&$metrics, $jobsByQuotation, $from, $to): void {
                $job = $jobsByQuotation->get($payment->quotation_id);
                if (! $job instanceof TransportJob || ! $this->inRange($payment->paid_at ?? $payment->updated_at, $from, $to)) {
                    return;
                }

                $sign = $payment->status === PaymentStatus::Refunded ? -1.0 : 1.0;
                $metrics[$job->id]['collected'] += $sign * (float) $payment->amount;
                $metrics[$job->id]['provider_share'] += $sign * (float) $payment->provider_amount;
                $metrics[$job->id]['platform_revenue'] += $sign * (float) ($this->isFleet($job) ? $payment->amount : $payment->commission_amount);
            });

        Invoice::query()
            ->whereIn('transport_job_id', $jobs->modelKeys())
            ->where('type', InvoiceType::Customer->value)
            ->where('status', InvoiceStatus::Issued->value)
            ->orderBy('id')
            ->get()
            ->each(function (Invoice $invoice) use (&$metrics, $from, $to): void {
                if ($invoice->transport_job_id === null || ! isset($metrics[$invoice->transport_job_id]) || ! $this->inRange($invoice->issued_at, $from, $to)) {
                    return;
                }

                $metrics[$invoice->transport_job_id]['customer_outstanding'] += (float) $invoice->amount;
            });

        if ($fleetIds->isNotEmpty()) {
            DriverPayable::query()
                ->whereIn('transport_job_id', $fleetIds->all())
                ->whereHas('trip', fn ($trip) => $trip->where('status', '!=', TripStatus::Cancelled->value))
                ->orderBy('id')
                ->get()
                ->each(function (DriverPayable $payable) use (&$metrics, $from, $to): void {
                    $jobId = (int) $payable->transport_job_id;
                    if (! isset($metrics[$jobId])) {
                        return;
                    }

                    if ($payable->status === DriverPayableStatus::Paid && $this->inRange($payable->paid_at ?? $payable->updated_at, $from, $to)) {
                        $metrics[$jobId]['driver_expense'] += (float) $payable->amount;
                    }
                    if ($payable->status === DriverPayableStatus::Pending && $this->inRange($payable->created_at, $from, $to)) {
                        $metrics[$jobId]['driver_outstanding'] += (float) $payable->amount;
                    }
                });
        }

        foreach ($metrics as $jobId => $amounts) {
            $metrics[$jobId] = $this->finish($amounts);
        }

        return $metrics;
    }

    /**
     * @param  array<string, float>  $amounts
     * @return array<string, mixed>
     */
    private function jobRow(TransportJob $job, array $amounts): array
    {
        $project = $job->project;
        $customer = $job->customerOrganization;

        return [
            'id' => $job->id,
            'reference' => $job->reference,
            'execution' => $this->isFleet($job) ? 'fleet' : 'marketplace',
            'currency' => (string) ($job->currency ?: config('mz.currency')),
            'project' => $project instanceof Project ? [
                'id' => $project->id,
                'project_id' => $project->project_id,
                'name_en' => $project->name_en,
                'name_ar' => $project->name_ar,
            ] : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'name_ar' => $customer->name_ar,
            ] : null,
            ...$amounts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function projects(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $project = $row['project'] ?? null;
            $key = is_array($project) ? 'project:'.$project['id'] : 'unassigned';
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => is_array($project) ? $project['id'] : null,
                    'project_id' => is_array($project) ? $project['project_id'] : null,
                    'name_en' => is_array($project) ? $project['name_en'] : null,
                    'name_ar' => is_array($project) ? $project['name_ar'] : null,
                    'unassigned' => ! is_array($project),
                    'jobs_count' => 0,
                    ...$this->blank(),
                ];
            }

            $groups[$key]['jobs_count']++;
            foreach ($this->moneyKeys() as $moneyKey) {
                $groups[$key][$moneyKey] += (float) $row[$moneyKey];
            }
        }

        $projects = array_values($groups);
        foreach ($projects as $index => $project) {
            $projects[$index] = $this->finish($project);
        }

        usort($projects, function (array $left, array $right): int {
            if ($left['unassigned'] !== $right['unassigned']) {
                return $left['unassigned'] ? 1 : -1;
            }

            return strcmp((string) ($left['name_en'] ?: $left['project_id']), (string) ($right['name_en'] ?: $right['project_id']));
        });

        return $projects;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function summarize(array $rows): array
    {
        $summary = $this->blank();
        foreach ($rows as $row) {
            foreach ($this->moneyKeys() as $key) {
                $summary[$key] += (float) $row[$key];
            }
        }

        return $this->finish($summary);
    }

    private function isFleet(TransportJob $job): bool
    {
        return (bool) $job->providerOrganization?->isPlatform();
    }

    /**
     * @param  array<string, float>  $amounts
     */
    private function hasActivity(array $amounts): bool
    {
        foreach ($this->moneyKeys() as $key) {
            if (abs($amounts[$key]) >= 0.0005) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, float>
     */
    private function blank(): array
    {
        return [
            'collected' => 0.0,
            'platform_revenue' => 0.0,
            'provider_share' => 0.0,
            'driver_expense' => 0.0,
            'net_profit' => 0.0,
            'customer_outstanding' => 0.0,
            'driver_outstanding' => 0.0,
        ];
    }

    /**
     * @return list<string>
     */
    private function moneyKeys(): array
    {
        return [
            'collected',
            'platform_revenue',
            'provider_share',
            'driver_expense',
            'customer_outstanding',
            'driver_outstanding',
        ];
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  T  $amounts
     * @return T
     */
    private function finish(array $amounts): array
    {
        foreach ($this->moneyKeys() as $key) {
            $amounts[$key] = round((float) $amounts[$key], 3);
        }

        $amounts['net_profit'] = round((float) $amounts['platform_revenue'] - (float) $amounts['driver_expense'], 3);

        return $amounts;
    }

    private function date(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function inRange(mixed $moment, ?string $from, ?string $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }

        if ($moment === null) {
            return false;
        }

        $day = Carbon::parse($moment)->toDateString();

        return ($from === null || $day >= $from) && ($to === null || $day <= $to);
    }
}
