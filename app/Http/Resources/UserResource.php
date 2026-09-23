<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->usesTechnicalEmail() ? null : $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'user_type' => $this->user_type,
            'is_active' => $this->is_active,
            'must_set_password' => (bool) $this->must_set_password,
            'organization_id' => $this->organization_id,
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'role_labels' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->map(fn ($role) => $role instanceof Role ? $role->label() : $role->name)->values()
            ),
            'permissions' => $this->when(
                $this->relationLoaded('roles') || $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->pluck('name')->values()
            ),
            'driver_profile' => $this->whenLoaded('driverProfile'),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'last_login_at' => $this->last_login_at,
        ];
    }
}
