<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\SettlementStatus;
use App\Models\Organization;
use App\Models\Settlement;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettlementService
{
    public function __construct(private readonly WalletLedgerService $ledger) {}

    /**
     * @param  array{provider_organization_id: int, amount: float|int|string, period_start: string, period_end: string}  $data
     */
    public function create(User $user, array $data): Settlement
    {
        return DB::transaction(function () use ($user, $data) {
            $organization = Organization::query()
                ->whereKey($data['provider_organization_id'])
                ->where('type', OrganizationType::Provider)
                ->firstOrFail();

            $payout = round((float) $data['amount'], 3);
            if ($payout < 0.001) {
                throw ValidationException::withMessages([
                    'amount' => ['Enter a payout amount from the available wallet balance.'],
                ]);
            }

            $settlement = Settlement::query()->create([
                'reference' => ReferenceGenerator::next('STL', Settlement::class),
                'provider_organization_id' => $organization->id,
                'amount' => $payout,
                'commission_amount' => 0,
                'net_amount' => $payout,
                'currency' => config('mz.currency'),
                'status' => SettlementStatus::Pending,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
            ]);

            $this->ledger->reservePayout($organization, $payout, $settlement, $user);
            AuditLogger::record('settlement.created', $settlement, [], $settlement->toArray(), $user);

            return $settlement->fresh('providerOrganization');
        });
    }

    public function complete(User $user, Settlement $settlement): Settlement
    {
        if ($settlement->status === SettlementStatus::Completed) {
            return $settlement;
        }

        return DB::transaction(function () use ($user, $settlement) {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status === SettlementStatus::Completed) {
                return $settlement;
            }

            $this->ledger->completePayout($settlement, $user);
            $settlement->forceFill([
                'status' => SettlementStatus::Completed,
                'settled_at' => now(),
            ])->save();

            AuditLogger::record('settlement.completed', $settlement, [], ['status' => $settlement->status->value], $user);

            return $settlement->fresh('providerOrganization');
        });
    }
}
