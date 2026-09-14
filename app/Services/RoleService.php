<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use App\Support\StaffRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    /**
     * @return Collection<int, Role>
     */
    public function visibleTo(User $actor): Collection
    {
        return $this->visibleQuery($actor)
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('display_name')
            ->orderBy('name')
            ->get();
    }

    public function create(User $actor, string $displayName, array $permissions): Role
    {
        abort_unless($actor->can(Permissions::ROLES_MANAGE), 403);

        if (! $actor->isPlatform() && ! $actor->organization_id) {
            abort(403);
        }

        $displayName = trim($displayName);
        $this->assertDisplayNameAvailable($actor, $displayName);
        $permissions = $this->validatedPermissions($actor, $permissions);

        $role = Role::query()->create([
            'name' => $this->uniqueName($actor, $displayName),
            'guard_name' => 'web',
            'display_name' => $displayName,
            'scope' => StaffRoles::scopeFor($actor),
            'organization_id' => $actor->isPlatform() ? null : $actor->organization_id,
            'is_system' => false,
        ]);
        $role->syncPermissions($permissions);
        $this->forgetCachedPermissions();

        return $role->fresh(['permissions'])->loadCount('users');
    }

    public function update(User $actor, Role $role, array $permissions, ?string $displayName = null): Role
    {
        abort_unless($this->canManage($actor, $role), 403);

        if ($displayName !== null && ! $role->is_system) {
            $displayName = trim($displayName);
            $this->assertDisplayNameAvailable($actor, $displayName, $role);
            $role->display_name = $displayName;
            $role->save();
        }

        $permissions = $this->validatedPermissions($actor, $permissions);
        if ($role->name === StaffRoles::SUPER_ADMIN) {
            $permissions = array_values(array_unique([
                ...StaffRoles::protectedSuperAdminPermissions(),
                ...$permissions,
            ]));
        }

        $role->syncPermissions($permissions);
        $this->forgetCachedPermissions();

        return $role->fresh(['permissions'])->loadCount('users');
    }

    public function delete(User $actor, Role $role): void
    {
        abort_unless($this->canManage($actor, $role), 403);

        if ($role->is_system || StaffRoles::isSystemName($role->name)) {
            throw ValidationException::withMessages([
                'role' => ['System roles cannot be deleted.'],
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => ['This role is assigned to users and cannot be deleted.'],
            ]);
        }

        $role->syncPermissions([]);
        $role->delete();
        $this->forgetCachedPermissions();
    }

    public function canManage(User $actor, Role $role): bool
    {
        if (! $actor->can(Permissions::ROLES_MANAGE)) {
            return false;
        }

        if ($actor->isPlatform()) {
            return $role->is_system || ($role->scope === StaffRoles::SCOPE_PLATFORM && $role->organization_id === null);
        }

        return ! $role->is_system
            && $role->scope === StaffRoles::scopeFor($actor)
            && (int) $role->organization_id === (int) $actor->organization_id;
    }

    public function canDelete(User $actor, Role $role): bool
    {
        return $this->canManage($actor, $role)
            && ! $role->is_system
            && ! StaffRoles::isSystemName($role->name)
            && (int) ($role->users_count ?? $role->users()->count()) === 0;
    }

    public function resolve(string $role): Role
    {
        $query = Role::query()->where('guard_name', 'web');

        if (ctype_digit($role)) {
            return $query->whereKey((int) $role)->firstOrFail();
        }

        return $query->where('name', $role)->firstOrFail();
    }

    /**
     * @return Builder<Role>
     */
    private function visibleQuery(User $actor): Builder
    {
        $query = Role::query()->where('guard_name', 'web');

        if ($actor->isPlatform()) {
            return $query->where(function (Builder $builder) {
                $builder->where('is_system', true)
                    ->orWhere(function (Builder $custom) {
                        $custom->where('is_system', false)
                            ->where('scope', StaffRoles::SCOPE_PLATFORM)
                            ->whereNull('organization_id');
                    });
            });
        }

        return $query->where(function (Builder $builder) use ($actor) {
            $builder->where(function (Builder $system) use ($actor) {
                $system->where('is_system', true)
                    ->where('scope', StaffRoles::scopeFor($actor));
            })->orWhere(function (Builder $custom) use ($actor) {
                $custom->where('is_system', false)
                    ->where('scope', StaffRoles::scopeFor($actor))
                    ->where('organization_id', $actor->organization_id);
            });
        });
    }

    private function assertDisplayNameAvailable(User $actor, string $displayName, ?Role $ignore = null): void
    {
        if ($displayName === '') {
            throw ValidationException::withMessages([
                'name' => ['The role name is required.'],
            ]);
        }

        if (StaffRoles::isSystemName($displayName)) {
            throw ValidationException::withMessages([
                'name' => ['This role name is reserved for a system role.'],
            ]);
        }

        $exists = Role::query()
            ->where('guard_name', 'web')
            ->where('scope', StaffRoles::scopeFor($actor))
            ->when(
                $actor->isPlatform(),
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->where('organization_id', $actor->organization_id),
            )
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->whereRaw('lower(display_name) = ?', [mb_strtolower($displayName)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A role with this name already exists.'],
            ]);
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function validatedPermissions(User $actor, array $permissions): array
    {
        $allowed = Permissions::assignableFor($actor);
        $filtered = array_values(array_unique(array_intersect($permissions, $allowed)));

        if ($filtered === []) {
            throw ValidationException::withMessages([
                'permissions' => ['Select at least one allowed permission.'],
            ]);
        }

        return $filtered;
    }

    private function uniqueName(User $actor, string $displayName): string
    {
        $slug = Str::slug($displayName);
        if ($slug === '') {
            $slug = 'role';
        }

        $base = sprintf(
            'custom.%s.%d.%s',
            StaffRoles::scopeFor($actor),
            $actor->isPlatform() ? 0 : (int) $actor->organization_id,
            $slug
        );
        $name = $base;
        $suffix = 2;

        while (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            $name = $base.'-'.$suffix;
            $suffix++;
        }

        return $name;
    }

    private function forgetCachedPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
