<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShipmentRequest;
use App\Http\Resources\ShipmentResource;
use App\Models\ShipmentRequest;
use App\Services\ShipmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentService $shipments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ShipmentRequest::class);

        return ApiResponse::success(
            ShipmentResource::collection($this->shipments->paginateFor($request->user(), $request->all()))
        );
    }

    public function store(StoreShipmentRequest $request): JsonResponse
    {
        $shipment = $this->shipments->create($request->user(), $request->validated());

        return ApiResponse::success(ShipmentResource::make($shipment->load('customerOrganization')), 'Shipment request created.', 201);
    }

    public function show(Request $request, ShipmentRequest $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);

        return ApiResponse::success(
            ShipmentResource::make($shipment->load(['customerOrganization', 'quotations.providerOrganization']))
        );
    }

    public function update(StoreShipmentRequest $request, ShipmentRequest $shipment): JsonResponse
    {
        $this->authorize('update', $shipment);
        $updated = $this->shipments->update($request->user(), $shipment, $request->validated());

        return ApiResponse::success(ShipmentResource::make($updated), 'Shipment request updated.');
    }

    public function publish(Request $request, ShipmentRequest $shipment): JsonResponse
    {
        $this->authorize('publish', $shipment);

        return ApiResponse::success(
            ShipmentResource::make($this->shipments->publish($request->user(), $shipment)),
            'Shipment request published.'
        );
    }

    public function cancel(Request $request, ShipmentRequest $shipment): JsonResponse
    {
        $this->authorize('cancel', $shipment);

        return ApiResponse::success(
            ShipmentResource::make($this->shipments->cancel($request->user(), $shipment)),
            'Shipment request cancelled.'
        );
    }
}
