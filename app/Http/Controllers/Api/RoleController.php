<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Permissions;
use App\Support\StaffRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can(Permissions::ROLES_MANAGE) || $request->user()->can(Permissions::USERS_MANAGE),
            403
        );

        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->serialize($role));

        return ApiResponse::success([
            'roles' => $roles,
            'permissions' => Permissions::all(),
            'platform_roles' => StaffRoles::platform(),
        ]);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ROLES_MANAGE), 403);

        $model = Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail();
        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $permissions = $data['permissions'];
        if ($model->name === StaffRoles::SUPER_ADMIN) {
            $permissions = array_values(array_unique([
                ...StaffRoles::protectedSuperAdminPermissions(),
                ...$permissions,
            ]));
        }

        $model->syncPermissions($permissions);
        AuditLogger::record('role.updated', null, [], [
            'role' => $model->name,
            'permissions' => $permissions,
        ], $request->user());

        return ApiResponse::success($this->serialize($model->fresh(['permissions'])->loadCount('users')), 'Role updated.');
    }

    /**
     * @return array{name: string, users_count: int, permissions: list<string>}
     */
    private function serialize(Role $role): array
    {
        return [
            'name' => $role->name,
            'users_count' => (int) ($role->users_count ?? $role->users()->count()),
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ];
    }
}
