<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\PlatformOfferResource;
use App\Models\PlatformOffer;
use App\Models\ShipmentRequest;
use App\Rules\TransportStartDate;
use App\Rules\UsableTruckType;
use App\Services\JobOrchestrationService;
use App\Services\PlatformOfferService;
use App\Support\ApiResponse;
use App\Support\BankTransferResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        if ($request->filled('quotation_id') && ($request->filled('total_price') || $request->filled('price_per_trip'))) {
            throw ValidationException::withMessages([
                'quotation_id' => ['Choose either a provider quotation or a platform offer.'],
            ]);
        }

        $data = $request->validate([
            'quotation_id' => ['required_without_all:total_price,price_per_trip', 'integer'],
            'customer_price' => ['required_with:quotation_id', 'numeric', 'min:0.001'],
            'total_price' => ['required_without_all:quotation_id,price_per_trip', 'numeric', 'min:0.001'],
            'price_per_trip' => ['required_without_all:quotation_id,total_price', 'numeric', 'min:0.001'],
            'currency' => ['nullable', 'string', 'size:3'],
            'truck_count' => ['required_without:quotation_id', 'integer', 'min:1'],
            'truck_type' => ['required_without:quotation_id', 'string', 'max:32', new UsableTruckType($request->user())],
            'truck_capacity_tons' => ['required_without:quotation_id', 'numeric', 'min:0.1'],
            'trip_count' => ['required_without:quotation_id', 'integer', 'min:1'],
            'quantity_per_trip' => ['required_without:quotation_id', 'numeric', 'min:0.1'],
            'duration_days' => ['required_without:quotation_id', 'integer', 'min:1'],
            'transport_start_date' => ['required_without:quotation_id', 'date', new TransportStartDate($shipment)],
            'additional_costs' => ['nullable', 'numeric', 'min:0'],
            'conditions' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $confirm = $request->boolean('confirm');

        $result = DB::transaction(function () use ($request, $shipment, $data, $confirm) {
            $offer = isset($data['quotation_id'])
                ? $this->offers->publish(
                    $request->user(),
                    $shipment,
                    (int) $data['quotation_id'],
                    (float) $data['customer_price'],
                )
                : $this->offers->publishOwned($request->user(), $shipment, $data);

            if (! $confirm) {
                return ['offer' => $offer, 'job' => null];
            }

            $acceptance = $this->jobs->confirmPlatformOffer($request->user(), $offer);

            return ['offer' => $offer, 'job' => $acceptance->job];
        });

        if ($result['job']) {
            return ApiResponse::success(
                JobResource::make($result['job']),
                'Agreement confirmed and job created.',
                201,
            );
        }

        return ApiResponse::success(
            PlatformOfferResource::make($result['offer']),
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

    public function confirm(Request $request, PlatformOffer $platformOffer): JsonResponse
    {
        $this->authorize('confirm', $platformOffer);

        $result = $this->jobs->confirmPlatformOffer($request->user(), $platformOffer);

        return ApiResponse::success(
            JobResource::make($result->job),
            'Agreement confirmed and job created. Payment is still collected under the shipment terms.',
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

        if ($result->awaitingTransfer && $result->payment) {
            return ApiResponse::success(
                BankTransferResponse::awaiting($result->payment),
                'Bank transfer recorded. The shipment starts after finance confirms the receipt.',
            );
        }

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
