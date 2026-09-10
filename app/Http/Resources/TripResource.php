<?php

namespace App\Http\Resources;

use App\Enums\UserType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeOtp = $user && (
            $user->user_type === UserType::Driver
            || $user->user_type === UserType::Provider
            || $user->user_type === UserType::Platform
        );

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'planned_quantity' => $this->planned_quantity,
            'delivered_quantity' => $this->delivered_quantity,
            'pickup_address' => $this->pickup_address,
            'pickup_city' => $this->pickup_city,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            'delivery_address' => $this->delivery_address,
            'delivery_city' => $this->delivery_city,
            'delivery_lat' => $this->delivery_lat,
            'delivery_lng' => $this->delivery_lng,
            'current_lat' => $this->current_lat,
            'current_lng' => $this->current_lng,
            'eta_at' => $this->eta_at,
            'otp_code' => $this->when($canSeeOtp, $this->otp_code),
            'assigned_at' => $this->assigned_at,
            'arrived_pickup_at' => $this->arrived_pickup_at,
            'loaded_at' => $this->loaded_at,
            'in_transit_at' => $this->in_transit_at,
            'arrived_at' => $this->arrived_at,
            'delivered_at' => $this->delivered_at,
            'completed_at' => $this->completed_at,
            'job' => JobResource::make($this->whenLoaded('transportJob')),
            'truck' => TruckResource::make($this->whenLoaded('truck')),
            'driver' => UserResource::make($this->whenLoaded('driver')),
            'proof_of_delivery' => $this->whenLoaded('proofOfDelivery'),
            'created_at' => $this->created_at,
        ];
    }
}
