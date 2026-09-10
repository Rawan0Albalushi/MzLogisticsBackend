<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\Permissions;
use App\Support\StaffRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);
        $actor = $request->user();

        $items = User::query()
            ->with(['organization', 'roles', 'driverProfile'])
            ->when(! $actor->isPlatform(), fn ($q) => $q->where('organization_id', $actor->organization_id))
            ->when($request->filled('type'), fn ($q) => $q->where('user_type', $request->string('type')))
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('is_active', $request->string('status')->toString() === 'active');
            })
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where(function ($builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(UserResource::collection($items));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);
        $actor = $request->user();
        $roles = StaffRoles::assignableBy($actor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => ['required', 'string', Rule::in($roles)],
            'locale' => ['nullable', 'in:ar,en'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'ar',
            'user_type' => $this->userTypeFor($actor),
            'organization_id' => $actor->isPlatform() ? null : $actor->organization_id,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->assignRole($data['role']);

        AuditLogger::record('user.created', $user, [], [
            'email' => $user->email,
            'role' => $data['role'],
        ], $actor);

        return ApiResponse::success(
            UserResource::make($user->load(['organization', 'roles', 'permissions'])),
            'User created.',
            201
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);
        $this->assertCanManage($request->user(), $user);

        return ApiResponse::success(
            UserResource::make($user->load(['organization', 'roles', 'permissions', 'driverProfile']))
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);
        $actor = $request->user();
        $this->assertCanManage($actor, $user);
        $roles = StaffRoles::assignableBy($actor);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:ar,en'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'string', Rule::in($roles)],
        ]);

        if (array_key_exists('is_active', $data) && $data['is_active'] === false) {
            $this->guardLastSuperAdmin($user, deactivate: true);
            if ($user->is($actor)) {
                throw ValidationException::withMessages([
                    'is_active' => ['You cannot deactivate your own account.'],
                ]);
            }
        }

        if (isset($data['role'])) {
            $this->guardLastSuperAdmin($user, newRole: $data['role']);
            $user->syncRoles([$data['role']]);
        }

        $user->fill(collect($data)->except('role')->all())->save();

        AuditLogger::record('user.updated', $user, [], $data, $actor);

        return ApiResponse::success(
            UserResource::make($user->fresh(['organization', 'roles', 'permissions'])),
            'User updated.'
        );
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::USERS_MANAGE), 403);
        $this->assertCanManage($request->user(), $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->forceFill(['password' => $data['password']])->save();
        AuditLogger::record('user.password_reset', $user, [], [], $request->user());

        return ApiResponse::success(null, 'Password updated.');
    }

    private function userTypeFor(User $actor): UserType
    {
        if ($actor->isPlatform()) {
            return UserType::Platform;
        }

        if ($actor->isCustomer()) {
            return UserType::Customer;
        }

        return UserType::Provider;
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if ($actor->isPlatform()) {
            return;
        }

        abort_unless((int) $actor->organization_id === (int) $target->organization_id, 403);
    }

    private function guardLastSuperAdmin(User $user, bool $deactivate = false, ?string $newRole = null): void
    {
        if (! $user->hasRole(StaffRoles::SUPER_ADMIN)) {
            return;
        }

        $remaining = User::role(StaffRoles::SUPER_ADMIN)->where('is_active', true)->whereKeyNot($user->id)->count();
        $losingRole = $newRole !== null && $newRole !== StaffRoles::SUPER_ADMIN;

        if (($deactivate || $losingRole) && $remaining === 0) {
            throw ValidationException::withMessages([
                'role' => ['The last active Super Admin cannot be removed or deactivated.'],
            ]);
        }
    }
}
