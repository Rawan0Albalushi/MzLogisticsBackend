<?php

namespace App\Support;

final class QuotationPricing
{
    /**
     * The charged total is the price of one trip multiplied by the trips that will be dispatched.
     *
     * @param  array<string, mixed>  $payload
     * @return array{price_per_trip: float|null, total_price: float, trip_count: int}
     */
    public static function resolve(array $payload): array
    {
        $tripCount = max((int) $payload['truck_count'], (int) $payload['trip_count']);
        $unit = $payload['price_per_trip'] ?? null;

        if ($unit !== null && $unit !== '') {
            $pricePerTrip = round((float) $unit, 3);

            return [
                'price_per_trip' => $pricePerTrip,
                'total_price' => round($pricePerTrip * $tripCount, 3),
                'trip_count' => $tripCount,
            ];
        }

        return [
            'price_per_trip' => null,
            'total_price' => round((float) $payload['total_price'], 3),
            'trip_count' => $tripCount,
        ];
    }
}
