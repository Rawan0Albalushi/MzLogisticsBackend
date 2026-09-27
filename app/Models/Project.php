<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id',
    'name_en',
    'name_ar',
])]
class Project extends Model
{
    public function jobs(): HasMany
    {
        return $this->hasMany(TransportJob::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isProvider()) {
            return $query;
        }

        return $query->whereHas(
            'jobs',
            fn (Builder $jobs) => $jobs->where('provider_organization_id', $user->organization_id),
        );
    }
}
