<?php

namespace App\Support;

use App\Enums\BillingTrigger;
use App\Enums\BillingUnit;
use App\Models\PaymentContract;
use App\Models\ShipmentRequest;

final class PaymentTerms
{
    public function __construct(
        public readonly BillingTrigger $billingTrigger,
        public readonly int $dueDays,
        public readonly BillingUnit $billingUnit = BillingUnit::Job,
        public readonly ?int $contractId = null,
    ) {}

    public static function prepaid(): self
    {
        return new self(BillingTrigger::OnAward, 0, BillingUnit::Job);
    }

    public static function fromContract(PaymentContract $contract): self
    {
        $trigger = $contract->billing_trigger;
        $unit = $trigger === BillingTrigger::OnAward
            ? BillingUnit::Job
            : ($contract->billing_unit ?? BillingUnit::Job);

        return new self(
            $trigger,
            $contract->dueDays(),
            $unit,
            $contract->id,
        );
    }

    public static function fromShipment(ShipmentRequest $shipment): self
    {
        $trigger = $shipment->payment_billing_trigger ?? BillingTrigger::OnAward;
        $unit = $trigger === BillingTrigger::OnAward
            ? BillingUnit::Job
            : ($shipment->payment_billing_unit ?? BillingUnit::Job);

        return new self(
            $trigger,
            $trigger === BillingTrigger::OnAward ? 0 : (int) ($shipment->payment_due_days ?? 0),
            $unit,
            $shipment->payment_contract_id,
        );
    }

    public function isPrepaid(): bool
    {
        return $this->billingTrigger === BillingTrigger::OnAward;
    }

    public function isDeferred(): bool
    {
        return $this->billingTrigger === BillingTrigger::OnDelivery;
    }

    public function isPerTrip(): bool
    {
        return $this->isDeferred() && $this->billingUnit === BillingUnit::Trip;
    }

    /**
     * @return array{billing_trigger: string, due_days: int, billing_unit: string, prepaid: bool, per_trip: bool, contract_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'billing_trigger' => $this->billingTrigger->value,
            'due_days' => $this->dueDays,
            'billing_unit' => $this->billingUnit->value,
            'prepaid' => $this->isPrepaid(),
            'per_trip' => $this->isPerTrip(),
            'contract_id' => $this->contractId,
        ];
    }
}
