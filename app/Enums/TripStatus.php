<?php

namespace App\Enums;

enum TripStatus: string
{
    case Unassigned = 'unassigned';
    case Assigned = 'assigned';
    case ArrivedAtPickup = 'arrived_at_pickup';
    case Loaded = 'loaded';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unassigned => [self::Assigned, self::Cancelled],
            self::Assigned => [self::ArrivedAtPickup, self::Cancelled],
            self::ArrivedAtPickup => [self::Loaded, self::Cancelled],
            self::Loaded => [self::InTransit, self::Cancelled],
            self::InTransit => [self::Arrived, self::Cancelled],
            self::Arrived => [self::Delivered, self::Cancelled],
            self::Delivered => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
