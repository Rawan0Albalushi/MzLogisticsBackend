<?php

namespace App\Http\Controllers\Api;

use App\Enums\JobStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TripStatus;
use App\Enums\TruckType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentMethodResource;
use App\Services\PaymentMethodService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(private readonly PaymentMethodService $paymentMethods) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'shipment_statuses' => ShipmentStatus::cases(),
            'quotation_statuses' => QuotationStatus::cases(),
            'job_statuses' => JobStatus::cases(),
            'trip_statuses' => TripStatus::cases(),
            'payment_statuses' => PaymentStatus::cases(),
            'truck_types' => TruckType::cases(),
            'payment_methods' => PaymentMethodResource::collection($this->paymentMethods->active())->resolve(),
            'currency' => config('mz.currency'),
            'commission_rate' => config('mz.commission_rate'),
        ]);
    }
}
