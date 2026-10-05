<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\TransportJob;
use App\Services\FinanceStatementService;
use App\Support\ApiResponse;
use App\Support\ListFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TransportJob::class);
        $user = $request->user();

        $jobs = TransportJob::query()
            ->with(['project', 'customerOrganization', 'providerOrganization', 'shipmentRequest', 'quotation', 'trips'])
            ->when($user->user_type === UserType::Customer, fn ($q) => $q->where('customer_organization_id', $user->organization_id))
            ->when($user->user_type === UserType::Provider, fn ($q) => $q->where('provider_organization_id', $user->organization_id))
            ->when($user->user_type === UserType::Driver, fn ($q) => $q->whereHas('trips', fn ($trips) => $trips->where('driver_user_id', $user->id)))
            ->when($request->filled('project'), fn ($q) => $q->where('project_id', $request->integer('project')))
            ->when($request->boolean('without_project'), fn ($q) => $q->whereNull('project_id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request, $user) {
                $relations = [
                    'providerOrganization' => ['name', 'name_ar'],
                    'shipmentRequest' => ['reference', 'pickup_city', 'delivery_city'],
                ];
                if (! $user->isProvider()) {
                    $relations['customerOrganization'] = ['name', 'name_ar', 'email'];
                }

                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['reference'],
                    $relations,
                );
            })
            ->tap(fn ($q) => ListFilters::dateRange($q, $request->all(), 'created_at'))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(JobResource::collection($jobs));
    }

    public function show(Request $request, TransportJob $job, FinanceStatementService $statement): JsonResponse
    {
        $this->authorize('view', $job);

        $job->load([
            'project',
            'customerOrganization',
            'providerOrganization',
            'shipmentRequest',
            'quotation',
            'trips.truck',
            'trips.driver',
            'trips.proofOfDelivery',
            'trips.driverPayable',
            'invoices.trip',
        ]);

        if ($request->user()?->isPlatform()) {
            $job->setAttribute('platform_statement', $statement->forJob($job));
        }

        return ApiResponse::success(JobResource::make($job));
    }
}
