<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->string('source', 32)->default('platform');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate(Permissions::SETTLEMENTS_REQUEST, 'web');

        foreach (['Provider Admin', 'Finance', 'Super Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('source');
        });
    }
};
