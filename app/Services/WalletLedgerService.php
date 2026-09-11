<?php

namespace App\Services;

use App\Enums\JobStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletTransactionType;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\TransportJob;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletLedgerService
{
    public function forProvider(Organization $organization): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'currency' => config('mz.currency'),
                'pending_balance' => 0,
                'available_balance' => 0,
                'reserved_balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_withdrawn' => 0,
            ]
        );
    }

    public function creditPendingEarning(Payment $payment, ?TransportJob $job = null, ?User $actor = null): ?WalletTransaction
    {
        if ($payment->status !== PaymentStatus::Completed) {
            return null;
        }

        $amount = round((float) $payment->provider_amount, 3);
        if ($amount <= 0) {
            return null;
        }

        $payment->loadMissing(['quotation.providerOrganization']);
        $organization = $payment->quotation?->providerOrganization;
        if (! $organization) {
            return null;
        }

        $job ??= $payment->transportJob;

        return $this->post($this->forProvider($organization), [
            'type' => WalletTransactionType::JobEarning,
            'amount' => $amount,
            'pending_delta' => $amount,
            'available_delta' => 0,
            'reserved_delta' => 0,
            'lifetime_earned_delta' => $amount,
            'payment_id' => $payment->id,
            'transport_job_id' => $job?->id,
            'created_by' => $actor?->id,
            'idempotency_key' => 'job_earning:payment:'.$payment->id,
            'description' => 'Provider share held until job completion.',
            'currency' => $payment->currency,
        ]);
    }

    public function releaseCompletedJob(TransportJob $job, ?User $actor = null): ?WalletTransaction
    {
        if ($job->status !== JobStatus::Completed) {
            return null;
        }

        $job->loadMissing(['quotation.providerOrganization']);
        $payment = Payment::query()->where('quotation_id', $job->quotation_id)->first();
        if (! $payment || $payment->status !== PaymentStatus::Completed) {
            return null;
        }

        $this->creditPendingEarning($payment, $job, $actor);

        $amount = round((float) $payment->provider_amount, 3);
        if ($amount <= 0) {
            return null;
        }

        $organization = $job->quotation?->providerOrganization ?? $payment->quotation?->providerOrganization;
        if (! $organization) {
            return null;
        }

        return $this->post($this->forProvider($organization), [
            'type' => WalletTransactionType::EarningReleased,
            'amount' => $amount,
            'pending_delta' => -$amount,
            'available_delta' => $amount,
            'reserved_delta' => 0,
            'payment_id' => $payment->id,
            'transport_job_id' => $job->id,
            'created_by' => $actor?->id,
            'idempotency_key' => 'earning_released:job:'.$job->id,
            'description' => 'Job completed. Provider share is now available.',
            'currency' => $payment->currency,
        ]);
    }

    public function reservePayout(Organization $organization, float $amount, Settlement $settlement, ?User $actor = null): WalletTransaction
    {
        $amount = round($amount, 3);
        $wallet = $this->forProvider($organization);

        if ($amount > (float) $wallet->available_balance + 0.0005) {
            throw ValidationException::withMessages([
                'amount' => ['The payout exceeds the provider available wallet balance.'],
            ]);
        }

        return $this->post($wallet, [
            'type' => WalletTransactionType::PayoutReserved,
            'amount' => $amount,
            'pending_delta' => 0,
            'available_delta' => -$amount,
            'reserved_delta' => $amount,
            'created_by' => $actor?->id,
            'idempotency_key' => 'payout_reserved:settlement:'.$settlement->id,
            'description' => 'Payout reserved for '.$settlement->reference.'.',
            'currency' => $settlement->currency ?? $wallet->currency,
            'meta' => ['settlement_id' => $settlement->id],
        ]);
    }

    public function completePayout(Settlement $settlement, ?User $actor = null): ?WalletTransaction
    {
        $settlement->loadMissing('providerOrganization');
        $organization = $settlement->providerOrganization;
        if (! $organization) {
            return null;
        }

        $reserved = WalletTransaction::query()
            ->where('idempotency_key', 'payout_reserved:settlement:'.$settlement->id)
            ->first();

        if (! $reserved) {
            return null;
        }

        $amount = round((float) $settlement->net_amount, 3);

        return $this->post($this->forProvider($organization), [
            'type' => WalletTransactionType::PayoutCompleted,
            'amount' => $amount,
            'pending_delta' => 0,
            'available_delta' => 0,
            'reserved_delta' => -$amount,
            'lifetime_withdrawn_delta' => $amount,
            'created_by' => $actor?->id,
            'idempotency_key' => 'payout_completed:settlement:'.$settlement->id,
            'description' => 'Payout completed for '.$settlement->reference.'.',
            'currency' => $settlement->currency,
            'meta' => ['settlement_id' => $settlement->id],
        ]);
    }

    public function backfillFromExistingPayments(): int
    {
        $count = 0;

        Payment::query()
            ->where('status', PaymentStatus::Completed)
            ->with(['quotation.providerOrganization', 'transportJob'])
            ->orderBy('id')
            ->each(function (Payment $payment) use (&$count): void {
                if ($this->creditPendingEarning($payment, $payment->transportJob)) {
                    $count++;
                }

                $job = $payment->transportJob;
                if ($job && $job->status === JobStatus::Completed) {
                    $this->releaseCompletedJob($job);
                }
            });

        return $count;
    }

    /**
     * @param  array{
     *     type: WalletTransactionType,
     *     amount: float,
     *     pending_delta: float,
     *     available_delta: float,
     *     reserved_delta: float,
     *     lifetime_earned_delta?: float,
     *     lifetime_withdrawn_delta?: float,
     *     payment_id?: int|null,
     *     transport_job_id?: int|null,
     *     created_by?: int|null,
     *     idempotency_key: string,
     *     description?: string|null,
     *     currency?: string|null,
     *     meta?: array<string, mixed>|null
     * }  $data
     */
    private function post(Wallet $wallet, array $data): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $data) {
            $existing = WalletTransaction::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing;
            }

            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            $transaction = WalletTransaction::query()->create([
                'reference' => ReferenceGenerator::next('WTX', WalletTransaction::class),
                'idempotency_key' => $data['idempotency_key'],
                'wallet_id' => $wallet->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'pending_delta' => $data['pending_delta'],
                'available_delta' => $data['available_delta'],
                'reserved_delta' => $data['reserved_delta'],
                'currency' => $data['currency'] ?? $wallet->currency,
                'payment_id' => $data['payment_id'] ?? null,
                'transport_job_id' => $data['transport_job_id'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'description' => $data['description'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);

            $wallet->forceFill([
                'pending_balance' => round((float) $wallet->pending_balance + $data['pending_delta'], 3),
                'available_balance' => round((float) $wallet->available_balance + $data['available_delta'], 3),
                'reserved_balance' => round((float) $wallet->reserved_balance + $data['reserved_delta'], 3),
                'lifetime_earned' => round((float) $wallet->lifetime_earned + ($data['lifetime_earned_delta'] ?? 0), 3),
                'lifetime_withdrawn' => round((float) $wallet->lifetime_withdrawn + ($data['lifetime_withdrawn_delta'] ?? 0), 3),
            ])->save();

            return $transaction;
        });
    }
}
