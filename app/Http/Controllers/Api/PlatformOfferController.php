<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PlatformOfferResource;
use App\Models\PlatformOffer;
use App\Models\ShipmentRequest;
use App\Services\JobOrchestrationService;
use App\Services\PlatformOfferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformOfferController extends Controller
{
    public function __construct(
        private readonly PlatformOfferService $offers,
        private readonly JobOrchestrationService $jobs,
    ) {}

    public function store(Request $request, ShipmentRequest $shipment): JsonResponse
    {
        $this->authorize('create', PlatformOffer::class);
        $this->authorize('view', $shipment);

        $data = $request->validate([
            'quotation_id' => ['required', 'integer'],
            'customer_price' => ['required', 'numeric', 'min:0.001'],
        ]);

        $offer = $this->offers->publish(
            $request->user(),
            $shipment,
            (int) $data['quotation_id'],
            (float) $data['customer_price'],
        );

        return ApiResponse::success(
            PlatformOfferResource::make($offer),
            'Platform offer published.',
            201,
        );
    }

    public function withdraw(Request $request, PlatformOffer $platformOffer): JsonResponse
    {
        $this->authorize('withdraw', $platformOffer);

        return ApiResponse::success(
            PlatformOfferResource::make($this->offers->withdraw($request->user(), $platformOffer)),
            'Platform offer withdrawn.',
        );
    }

    public function accept(Request $request, PlatformOffer $platformOffer): JsonResponse
    {
        $this->authorize('accept', $platformOffer);
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $this->jobs->acceptPlatformOffer(
            $request->user(),
            $platformOffer,
            $data['payment_method'] ?? null,
            $request->header('X-Payment-Callback-Base'),
        );

        if ($result->requiresCheckout) {
            return ApiResponse::success([
                'requires_checkout' => true,
                'payment_link' => $result->paymentLink,
                'session_id' => $result->sessionId,
                'payment' => PaymentResource::make($result->payment)->resolve(),
                'job' => null,
            ], 'Complete payment with Thawani to award this offer.');
        }

        $message = $result->paymentDeferred
            ? 'Offer accepted and job created. Payment is due after delivery.'
            : 'Offer accepted, payment verified, and job created.';

        return ApiResponse::success(JobResource::make($result->job), $message);
    }
}
