<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'jobs_count' => $this->whenCounted('jobs'),
            'jobs' => JobResource::collection($this->whenLoaded('jobs')),
            'created_at' => $this->created_at,
        ];
    }
}
