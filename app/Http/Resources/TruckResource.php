<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TruckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'capacity_tons' => $this->capacity_tons,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'status' => $this->status,
            'assigned_driver_id' => $this->assigned_driver_id,
            'assigned_driver' => UserResource::make($this->whenLoaded('assignedDriver')),
            'insurance_expires_at' => $this->insurance_expires_at?->toDateString(),
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
        ];
    }
}
