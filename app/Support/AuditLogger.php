<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    public static function record(string $action, ?Model $model = null, array $old = [], array $new = [], ?User $user = null): void
    {
        AuditLog::query()->create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}
