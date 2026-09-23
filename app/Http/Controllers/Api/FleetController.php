<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverStatus;
use App\Enums\EquipmentStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTruckRequest;
use App\Http\Resources\EquipmentResource;
use App\Http\Resources\TruckResource;
use App\Http\Resources\UserResource;
use App\Models\Equipment;
use App\Models\Truck;
use App\Models\User;
use App\Services\DriverImportService;
use App\Services\DriverProvisioningService;
use App\Services\EquipmentImportService;
use App\Services\TruckImportService;
use App\Support\ApiResponse;
use App\Support\ListFilters;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FleetController extends Controller
{
    public function trucks(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_VIEW);
        $trucks = Truck::query()
            ->with(['assignedDriver', 'organization', 'equipment', 'documents'])
            ->when(! $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->user()->organization_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('organization_id') && $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['plate_number', 'make', 'model', 'type'],
                    [
                        'organization' => ['name', 'name_ar'],
                        'assignedDriver' => ['name'],
                    ],
                );
            })
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

    public function truckImportTemplate(Request $request, TruckImportService $import): StreamedResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);

        return $import->templateDownload();
    }

    public function importTrucks(Request $request, TruckImportService $import): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        return ApiResponse::success(
            $import->import($request->user(), $data['file']),
            'Truck import completed.',
        );
    }

    public function updateTruck(StoreTruckRequest $request, Truck $truck): JsonResponse
    {
        $this->assertFleetOwnership($request, $truck->organization_id);
        $truck->fill($request->safe()->except('organization_id'))->save();

        return ApiResponse::success(TruckResource::make($truck->fresh(['assignedDriver', 'equipment', 'documents'])), 'Truck updated.');
    }

    public function equipment(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_VIEW);
        $placement = $request->string('placement')->toString();
        $items = Equipment::query()
            ->with('truck:id,plate_number')
            ->when(! $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->user()->organization_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($placement === 'company', fn ($q) => $q->whereNull('truck_id'))
            ->when($placement === 'truck', fn ($q) => $q->whereNotNull('truck_id'))
            ->when($request->filled('truck_id'), fn ($q) => $q->where('truck_id', $request->integer('truck_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search($q, $request->string('search')->toString(), ['name', 'type']);
            })
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(EquipmentResource::collection($items));
    }

    public function storeEquipment(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);
        $data = $this->validatedEquipment($request);
        $data['organization_id'] = $request->user()->organization_id;
        $item = Equipment::query()->create($data);

        return ApiResponse::success(EquipmentResource::make($item->load('truck:id,plate_number')), 'Equipment added.', 201);
    }

    public function equipmentImportTemplate(Request $request, EquipmentImportService $import): StreamedResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);

        return $import->templateDownload();
    }

    public function importEquipment(Request $request, EquipmentImportService $import): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        return ApiResponse::success(
            $import->import($request->user(), $data['file']),
            'Equipment import completed.',
        );
    }

    public function updateEquipment(Request $request, Equipment $equipment): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);
        $this->assertFleetOwnership($request, $equipment->organization_id);
        $equipment->fill($this->validatedEquipment($request))->save();

        return ApiResponse::success(EquipmentResource::make($equipment->fresh('truck:id,plate_number')), 'Equipment updated.');
    }

    public function drivers(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_VIEW);
        $drivers = User::query()
            ->where('user_type', UserType::Driver)
            ->with(['driverProfile', 'documents'])
            ->when(! $request->user()->isPlatform(), fn ($q) => $q->where('organization_id', $request->user()->organization_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['name', 'email', 'phone'],
                    ['driverProfile' => ['license_number']],
                );
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->whereHas('driverProfile', fn ($profile) => $profile->where('status', $request->string('status')));
            })
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(UserResource::collection($drivers));
    }

    public function storeDriver(Request $request, DriverProvisioningService $provisioning): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'license_expires_at' => ['nullable', 'date'],
        ]);

        $result = $provisioning->provision($request->user(), $data);

        return ApiResponse::success([
            'driver' => UserResource::make($result['driver'])->resolve(),
            'invite_url' => $result['invite_url'],
            'whatsapp_sent' => $result['whatsapp_sent'],
        ], 'Driver added.', 201);
    }

    public function updateDriver(Request $request, User $driver, DriverProvisioningService $provisioning): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($driver->id)],
            'phone' => ['required', 'string', 'max:32'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'license_expires_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(DriverStatus::class)],
        ]);

        $updated = $provisioning->update($request->user(), $driver, $data);

        return ApiResponse::success(UserResource::make($updated), 'Driver updated.');
    }

    public function importTemplate(Request $request, DriverImportService $import): StreamedResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);

        return $import->templateDownload();
    }

    public function importDrivers(Request $request, DriverImportService $import): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $result = $import->import($request->user(), $data['file']);

        return ApiResponse::success($result, 'Driver import completed.');
    }

    public function resendInvite(Request $request, User $driver, DriverProvisioningService $provisioning): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);
        $result = $provisioning->resendInvite($request->user(), $driver);

        return ApiResponse::success([
            'driver' => UserResource::make($result['driver'])->resolve(),
            'invite_url' => $result['invite_url'],
            'whatsapp_sent' => $result['whatsapp_sent'],
        ], 'Activation invite sent.');
    }

    /**
     * @return array{name: string, type: ?string, quantity: int, status: ?string, truck_id: ?int}
     */
    private function validatedEquipment(Request $request): array
    {
        $organizationId = $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(EquipmentStatus::class)],
            'truck_id' => [
                'nullable',
                'integer',
                Rule::exists('trucks', 'id')->where(
                    fn ($query) => $query->where('organization_id', $organizationId),
                ),
            ],
        ]);
        $data['type'] = filled($data['type'] ?? null) ? $data['type'] : null;
        $data['truck_id'] = $data['truck_id'] ?? null;

        return $data;
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
