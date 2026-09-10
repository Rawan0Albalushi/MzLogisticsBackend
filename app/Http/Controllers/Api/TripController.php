<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTripRequest;
use App\Http\Requests\StoreProofOfDeliveryRequest;
use App\Http\Requests\UpdateTripStatusRequest;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\TripService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(private readonly TripService $trips) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Trip::class);

        return ApiResponse::success(
            TripResource::collection($this->trips->paginateFor($request->user(), $request->all()))
        );
    }

    public function show(Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return ApiResponse::success(
            TripResource::make($trip->load(['transportJob.shipmentRequest', 'truck', 'driver', 'proofOfDelivery', 'locations']))
        );
    }

    public function assign(AssignTripRequest $request, Trip $trip): JsonResponse
    {
        $this->authorize('assign', $trip);

        return ApiResponse::success(
            TripResource::make($this->trips->assign($request->user(), $trip, $request->validated())),
            'Trip assigned.'
        );
    }

    public function updateStatus(UpdateTripStatusRequest $request, Trip $trip): JsonResponse
    {
        $this->authorize('updateStatus', $trip);
        $status = TripStatus::from($request->string('status')->toString());

        return ApiResponse::success(
            TripResource::make($this->trips->transition($request->user(), $trip, $status)),
            'Trip status updated.'
        );
    }

    public function location(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('track', $trip);
        $data = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'eta_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::success(
            TripResource::make($this->trips->recordLocation(
                $request->user(),
                $trip,
                (float) $data['lat'],
                (float) $data['lng'],
                $data['eta_at'] ?? null
            ))
        );
    }

    public function storePod(StoreProofOfDeliveryRequest $request, Trip $trip): JsonResponse
    {
        $this->authorize('submitPod', $trip);
        $pod = $this->trips->submitProof(
            $request->user(),
            $trip,
            $request->validated(),
            $request->file('photos', []),
            $request->file('signature')
        );

        return ApiResponse::success($pod, 'Proof of delivery recorded.', 201);
    }
}
