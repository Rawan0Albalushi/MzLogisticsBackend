<?php

use App\Support\StaffRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
            $table->string('scope', 32)->nullable()->after('display_name');
            $table->foreignId('organization_id')->nullable()->after('scope')->constrained()->cascadeOnDelete();
            $table->boolean('is_system')->default(true)->after('organization_id');
            $table->index(['scope', 'organization_id']);
        });

        foreach (DB::table('roles')->get() as $role) {
            DB::table('roles')->where('id', $role->id)->update([
                'display_name' => $role->display_name ?: $role->name,
                'scope' => StaffRoles::scopeOf($role->name),
                'is_system' => StaffRoles::isSystemName($role->name) ? 1 : ($role->is_system ?? 1),
            ]);
        }

        $providerAdminId = DB::table('roles')->where('name', StaffRoles::PROVIDER_ADMIN)->value('id');
        $rolesManageId = DB::table('permissions')->where('name', 'roles.manage')->value('id');
        if ($providerAdminId && $rolesManageId) {
            $exists = DB::table('role_has_permissions')
                ->where('role_id', $providerAdminId)
                ->where('permission_id', $rolesManageId)
                ->exists();
            if (! $exists) {
                DB::table('role_has_permissions')->insert([
                    'role_id' => $providerAdminId,
                    'permission_id' => $rolesManageId,
                ]);
            }
            if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
                app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            }
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['scope', 'organization_id']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['display_name', 'scope', 'is_system']);
        });
    }
};
