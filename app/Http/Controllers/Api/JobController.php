<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\TransportJob;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TransportJob::class);
        $user = $request->user();

        $jobs = TransportJob::query()
            ->with(['customerOrganization', 'providerOrganization', 'shipmentRequest', 'quotation', 'trips'])
            ->when($user->user_type === UserType::Customer, fn ($q) => $q->where('customer_organization_id', $user->organization_id))
            ->when($user->user_type === UserType::Provider, fn ($q) => $q->where('provider_organization_id', $user->organization_id))
            ->when($user->user_type === UserType::Driver, fn ($q) => $q->whereHas('trips', fn ($trips) => $trips->where('driver_user_id', $user->id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(JobResource::collection($jobs));
    }

    public function show(TransportJob $job): JsonResponse
    {
        $this->authorize('view', $job);

        return ApiResponse::success(
            JobResource::make($job->load([
                'customerOrganization',
                'providerOrganization',
                'shipmentRequest',
                'quotation',
                'trips.truck',
                'trips.driver',
                'trips.proofOfDelivery',
            ]))
        );
    }
}
