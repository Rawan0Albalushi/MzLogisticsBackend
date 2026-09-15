<?php

namespace App\Support;

final class BillingAllocator
{
    /**
     * @param  list<float>  $weights
     * @return list<array{amount: float, commission_amount: float, provider_amount: float}>
     */
    public static function split(float $total, float $commissionRate, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $safe = array_map(static fn (float $weight): float => max(0.0, $weight), $weights);
        if (array_sum($safe) <= 0) {
            $safe = array_fill(0, $count, 1.0);
        }

        $sum = array_sum($safe);
        $totalCommission = round($total * $commissionRate, 3);
        $slices = [];
        $usedAmount = 0.0;
        $usedCommission = 0.0;

        foreach ($safe as $index => $weight) {
            $isLast = $index === $count - 1;
            $amount = $isLast
                ? round($total - $usedAmount, 3)
                : round($total * ($weight / $sum), 3);
            $commission = $isLast
                ? round($totalCommission - $usedCommission, 3)
                : round($amount * $commissionRate, 3);

            $slices[] = [
                'amount' => $amount,
                'commission_amount' => $commission,
                'provider_amount' => round($amount - $commission, 3),
            ];
            $usedAmount += $amount;
            $usedCommission += $commission;
        }

        return $slices;
    }
}
