<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTruckRequest;
use App\Http\Resources\TruckResource;
use App\Http\Resources\UserResource;
use App\Models\DriverProfile;
use App\Models\Equipment;
use App\Models\Truck;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class FleetController extends Controller
{
    public function trucks(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_VIEW);
        $trucks = Truck::query()
            ->with(['assignedDriver', 'organization'])
            ->when(! $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->user()->organization_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(TruckResource::collection($trucks));
    }

    public function storeTruck(StoreTruckRequest $request): JsonResponse
    {
        $organizationId = $request->user()->isPlatform()
            ? $request->integer('organization_id')
            : $request->user()->organization_id;

        $truck = Truck::query()->create([
            ...$request->safe()->except('organization_id'),
            'organization_id' => $organizationId,
        ]);

        return ApiResponse::success(TruckResource::make($truck), 'Truck added.', 201);
    }

    public function updateTruck(StoreTruckRequest $request, Truck $truck): JsonResponse
    {
        $this->assertFleetOwnership($request, $truck->organization_id);
        $truck->fill($request->safe()->except('organization_id'))->save();

        return ApiResponse::success(TruckResource::make($truck->fresh('assignedDriver')), 'Truck updated.');
    }

    public function equipment(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_VIEW);
        $items = Equipment::query()
            ->when(! $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->user()->organization_id))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success($items);
    }

    public function storeEquipment(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string'],
        ]);
        $data['organization_id'] = $request->user()->organization_id;
        $item = Equipment::query()->create($data);

        return ApiResponse::success($item, 'Equipment added.', 201);
    }

    public function drivers(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_VIEW);
        $drivers = User::query()
            ->where('user_type', UserType::Driver)
            ->with('driverProfile')
            ->when(! $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->user()->organization_id))
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

        return ApiResponse::success(UserResource::collection($drivers));
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'phone' => ['nullable', 'string', 'max:32'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'license_expires_at' => ['nullable', 'date'],
        ]);

        $driver = DB::transaction(function () use ($request, $data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'locale' => 'ar',
                'user_type' => UserType::Driver,
                'organization_id' => $request->user()->organization_id,
                'is_active' => true,
            ]);
            $user->assignRole('Driver');
            DriverProfile::query()->create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'license_number' => $data['license_number'] ?? null,
                'license_expires_at' => $data['license_expires_at'] ?? null,
                'status' => DriverStatus::Available,
            ]);

            return $user->load('driverProfile');
        });

        return ApiResponse::success(UserResource::make($driver), 'Driver added.', 201);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission) || $request->user()->isPlatform(), 403);
    }

    private function assertFleetOwnership(Request $request, int $organizationId): void
    {
        if ($request->user()->isPlatform()) {
            return;
        }

        abort_unless((int) $request->user()->organization_id === $organizationId, 403);
    }
}
