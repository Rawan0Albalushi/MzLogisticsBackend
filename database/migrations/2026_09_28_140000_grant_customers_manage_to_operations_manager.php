<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::query()->where('name', 'Operations Manager')->where('guard_name', 'web')->first();
        if (! $role) {
            return;
        }

        $permission = Permission::findOrCreate(Permissions::CUSTOMERS_MANAGE, 'web');
        $role->givePermissionTo($permission);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $role = Role::query()->where('name', 'Operations Manager')->where('guard_name', 'web')->first();
        if (! $role || ! $role->hasPermissionTo(Permissions::CUSTOMERS_MANAGE)) {
            return;
        }

        $role->revokePermissionTo(Permissions::CUSTOMERS_MANAGE);
    }
};
