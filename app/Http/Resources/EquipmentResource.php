<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'truck_id' => $this->truck_id,
            'truck' => $this->whenLoaded('truck', fn () => $this->truck === null ? null : [
                'id' => $this->truck->id,
                'plate_number' => $this->truck->plate_number,
            ]),
        ];
    }
}
