<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'account_type' => $this->account_type,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'commercial_register' => $this->commercial_register,
            'tax_number' => $this->tax_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'country' => $this->country,
            'address' => $this->address,
            'status' => $this->status,
            'verification_notes' => $this->verification_notes,
            'commission_rate' => $this->commission_rate,
            'created_at' => $this->created_at,
        ];
    }
}
