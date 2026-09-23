<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type instanceof DocumentType ? $this->type->value : $this->type,
            'title' => $this->title,
            'status' => $this->status instanceof DocumentStatus ? $this->status->value : $this->status,
            'expires_at' => $this->expires_at?->toDateString(),
        ];
    }
}
