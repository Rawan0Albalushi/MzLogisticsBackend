<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTruckTypeRequest;
use App\Http\Requests\UpdateTruckTypeRequest;
use App\Http\Resources\TruckTypeResource;
use App\Models\TruckType;
use App\Services\TruckTypeService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TruckTypeController extends Controller
{
    public function __construct(private readonly TruckTypeService $truckTypes) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::FLEET_MANAGE), 403);

        return ApiResponse::success(TruckTypeResource::collection($this->truckTypes->manageable($request->user())));
    }

    public function store(StoreTruckTypeRequest $request): JsonResponse
    {
        $type = $this->truckTypes->create($request->user(), $request->validated());

        return ApiResponse::success(TruckTypeResource::make($type), 'Truck type created.', 201);
    }

    public function update(UpdateTruckTypeRequest $request, TruckType $truckType): JsonResponse
    {
        $type = $this->truckTypes->update($request->user(), $truckType, $request->validated());

        return ApiResponse::success(TruckTypeResource::make($type), 'Truck type updated.');
    }

    public function destroy(Request $request, TruckType $truckType): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::FLEET_MANAGE), 403);

        $this->truckTypes->delete($request->user(), $truckType);

        return ApiResponse::success(null, 'Truck type deleted.');
    }
}
