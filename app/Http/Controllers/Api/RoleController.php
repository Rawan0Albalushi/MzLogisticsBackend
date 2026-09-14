<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\RoleService;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Permissions;
use App\Support\StaffRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can(Permissions::ROLES_MANAGE) || $request->user()->can(Permissions::USERS_MANAGE),
            403
        );

        $actor = $request->user();
        $roles = $this->roles->visibleTo($actor)->map(fn (Role $role) => $this->serialize($actor, $role));

        return ApiResponse::success([
            'roles' => $roles,
            'permissions' => Permissions::assignableFor($actor),
            'platform_roles' => StaffRoles::assignableBy($actor),
            'assignable_roles' => StaffRoles::assignableBy($actor),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ROLES_MANAGE), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::assignableFor($request->user()))],
        ]);

        $role = $this->roles->create($request->user(), $data['name'], $data['permissions']);
        AuditLogger::record('role.created', null, [], [
            'role' => $role->name,
            'display_name' => $role->display_name,
            'permissions' => $data['permissions'],
        ], $request->user());

        return ApiResponse::success($this->serialize($request->user(), $role), 'Role created.', 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ROLES_MANAGE), 403);

        $model = $this->roles->resolve($role);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::assignableFor($request->user()))],
        ]);

        $model = $this->roles->update($request->user(), $model, $data['permissions'], $data['name'] ?? null);
        AuditLogger::record('role.updated', null, [], [
            'role' => $model->name,
            'display_name' => $model->display_name,
            'permissions' => $data['permissions'],
        ], $request->user());

        return ApiResponse::success($this->serialize($request->user(), $model), 'Role updated.');
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ROLES_MANAGE), 403);

        $model = $this->roles->resolve($role);
        $payload = [
            'role' => $model->name,
            'display_name' => $model->display_name,
        ];
        $this->roles->delete($request->user(), $model);
        AuditLogger::record('role.deleted', null, [], $payload, $request->user());

        return ApiResponse::success(null, 'Role deleted.');
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     display_name: string,
     *     users_count: int,
     *     permissions: list<string>,
     *     is_system: bool,
     *     can_manage: bool,
     *     can_delete: bool,
     *     scope: string|null,
     *     organization_id: int|null
     * }
     */
    private function serialize($actor, Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->label(),
            'users_count' => (int) ($role->users_count ?? $role->users()->count()),
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'is_system' => (bool) $role->is_system,
            'can_manage' => $this->roles->canManage($actor, $role),
            'can_delete' => $this->roles->canDelete($actor, $role),
            'scope' => $role->scope,
            'organization_id' => $role->organization_id,
        ];
    }
}
