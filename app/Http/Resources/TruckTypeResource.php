<?php

namespace App\Http\Resources;

use App\Services\TruckTypeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TruckTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user() ?? $request->user('sanctum');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'label' => $this->localizedName(),
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'is_platform' => $this->isPlatform(),
            'can_manage' => $user ? app(TruckTypeService::class)->canManage($user, $this->resource) : false,
            'organization_id' => $this->organization_id,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
