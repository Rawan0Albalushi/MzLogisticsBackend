<?php

namespace App\Http\Resources;

use App\Services\TruckTypeService;
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
            'type_label' => app(TruckTypeService::class)->label(is_object($this->type) ? $this->type->value : $this->type),
            'capacity_tons' => $this->capacity_tons,
            'volume_cbm' => $this->volume_cbm,
            'cargo_length_m' => $this->cargo_length_m,
            'cargo_width_m' => $this->cargo_width_m,
            'cargo_height_m' => $this->cargo_height_m,
            'axle_count' => $this->axle_count,
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
