<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuotationRequest;
use App\Http\Resources\JobResource;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Services\JobOrchestrationService;
use App\Services\QuotationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationService $quotations,
        private readonly JobOrchestrationService $jobs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);

        return ApiResponse::success(
            QuotationResource::collection($this->quotations->paginateFor($request->user(), $request->all()))
        );
    }

    public function store(StoreQuotationRequest $request, ShipmentRequest $shipment): JsonResponse
    {
        $this->authorize('view', $shipment);
        $quotation = $this->quotations->submit($request->user(), $shipment, $request->validated());

        return ApiResponse::success(QuotationResource::make($quotation), 'Quotation submitted.', 201);
    }

    public function show(Quotation $quotation): JsonResponse
    {
        $this->authorize('view', $quotation);

        return ApiResponse::success(
            QuotationResource::make($quotation->load(['providerOrganization', 'shipmentRequest.customerOrganization']))
        );
    }

    public function withdraw(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('withdraw', $quotation);

        return ApiResponse::success(
            QuotationResource::make($this->quotations->withdraw($request->user(), $quotation)),
            'Quotation withdrawn.'
        );
    }

    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('accept', $quotation);
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:card,bank_transfer,wallet'],
        ]);

        $job = $this->jobs->acceptQuotation(
            $request->user(),
            $quotation,
            $data['payment_method'] ?? 'card'
        );

        return ApiResponse::success(JobResource::make($job), 'Quotation accepted, payment verified, and job created.');
    }
}
