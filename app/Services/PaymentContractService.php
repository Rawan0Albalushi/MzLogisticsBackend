<?php

namespace App\Services;

use App\Enums\BillingTrigger;
use App\Enums\BillingUnit;
use App\Enums\OrganizationType;
use App\Enums\PaymentContractRequestStatus;
use App\Models\Organization;
use App\Models\PaymentContract;
use App\Models\ShipmentRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PaymentTerms;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class PaymentContractService
{
    public function maxDueDays(): int
    {
        return (int) config('mz.payment_due_days_max', 730);
    }

    public function ensureForCustomer(Organization $organization): PaymentContract
    {
        if (! $organization->isCustomer()) {
            throw ValidationException::withMessages([
                'organization' => ['Payment contracts can only be created for customers.'],
            ]);
        }

        return PaymentContract::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'billing_trigger' => BillingTrigger::OnAward,
                'due_days' => 0,
                'billing_unit' => BillingUnit::Job,
            ]
        );
    }

    /**
     * @param  array{billing_trigger?: string, due_days?: int|null, billing_unit?: string|null}  $payload
     */
    public function snapshotOnto(ShipmentRequest $shipment, array $payload = []): ShipmentRequest
    {
        $organization = $shipment->customerOrganization ?? Organization::query()->find($shipment->customer_organization_id);
        if (! $organization) {
            return $shipment;
        }

        $contract = $this->ensureForCustomer($organization);

        if (isset($payload['billing_trigger'])) {
            [$trigger, $dueDays, $unit] = $this->normalizeTerms($payload);
            $terms = new PaymentTerms($trigger, $dueDays, $unit, $contract->id);
        } elseif ($shipment->payment_billing_trigger) {
            return $shipment;
        } else {
            $terms = $contract->effectiveTerms();
        }

        $shipment->forceFill([
            'payment_contract_id' => $terms->contractId,
            'payment_billing_trigger' => $terms->billingTrigger,
            'payment_due_days' => $terms->dueDays,
            'payment_billing_unit' => $terms->billingUnit,
        ])->save();

        return $shipment->fresh();
    }

    /**
     * @param  array{billing_trigger: string, due_days?: int|null, billing_unit?: string|null}  $payload
     */
    public function requestTerms(User $actor, Organization $organization, array $payload): PaymentContract
    {
        $contract = $this->ensureForCustomer($organization);
        [$trigger, $dueDays, $unit] = $this->normalizeTerms($payload);

        $contract->forceFill([
            'billing_trigger' => $trigger,
            'due_days' => $dueDays,
            'billing_unit' => $unit,
            'pending_billing_trigger' => null,
            'pending_due_days' => null,
            'pending_billing_unit' => null,
            'pending_status' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        AuditLogger::record('payment_contract.updated', $contract, [], [
            'billing_trigger' => $trigger->value,
            'due_days' => $dueDays,
            'billing_unit' => $unit->value,
        ], $actor);

        return $contract->fresh();
    }

    public function approve(User $actor, Organization $organization): PaymentContract
    {
        $contract = $this->ensureForCustomer($organization);

        if (! $contract->hasPendingRequest()) {
            throw ValidationException::withMessages([
                'payment_contract' => ['There is no pending payment-terms request to approve.'],
            ]);
        }

        $trigger = $contract->pending_billing_trigger ?? BillingTrigger::OnAward;
        $dueDays = $trigger === BillingTrigger::OnAward ? 0 : (int) $contract->pending_due_days;
        $unit = $trigger === BillingTrigger::OnAward
            ? BillingUnit::Job
            : ($contract->pending_billing_unit ?? BillingUnit::Job);

        $contract->forceFill([
            'billing_trigger' => $trigger,
            'due_days' => $dueDays,
            'billing_unit' => $unit,
            'pending_billing_trigger' => null,
            'pending_due_days' => null,
            'pending_billing_unit' => null,
            'pending_status' => null,
            'approved_at' => now(),
            'approved_by' => $actor->id,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        AuditLogger::record('payment_contract.approved', $contract, [], [
            'billing_trigger' => $trigger->value,
            'due_days' => $dueDays,
            'billing_unit' => $unit->value,
        ], $actor);

        return $contract->fresh();
    }

    public function reject(User $actor, Organization $organization, ?string $reason = null): PaymentContract
    {
        $contract = $this->ensureForCustomer($organization);

        if (! $contract->hasPendingRequest()) {
            throw ValidationException::withMessages([
                'payment_contract' => ['There is no pending payment-terms request to reject.'],
            ]);
        }

        $contract->forceFill([
            'pending_status' => PaymentContractRequestStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        AuditLogger::record('payment_contract.rejected', $contract, [], [
            'reason' => $reason,
        ], $actor);

        return $contract->fresh();
    }

    public function paginatePending(array $filters = []): LengthAwarePaginator
    {
        return PaymentContract::query()
            ->with('organization')
            ->where('pending_status', PaymentContractRequestStatus::Pending)
            ->whereHas('organization', fn ($query) => $query->where('type', OrganizationType::Customer))
            ->latest('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array{billing_trigger: string, due_days?: int|null, billing_unit?: string|null}  $payload
     * @return array{0: BillingTrigger, 1: int, 2: BillingUnit}
     */
    private function normalizeTerms(array $payload): array
    {
        $trigger = BillingTrigger::from($payload['billing_trigger']);
        $dueDays = $trigger === BillingTrigger::OnAward
            ? 0
            : (int) ($payload['due_days'] ?? 0);
        $unit = $trigger === BillingTrigger::OnAward
            ? BillingUnit::Job
            : BillingUnit::from($payload['billing_unit'] ?? BillingUnit::Job->value);

        if ($dueDays < 0 || $dueDays > $this->maxDueDays()) {
            throw ValidationException::withMessages([
                'due_days' => ['Payment due days must be between 0 and '.$this->maxDueDays().'.'],
            ]);
        }

        return [$trigger, $dueDays, $unit];
    }
}
