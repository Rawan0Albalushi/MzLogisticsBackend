<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShipmentStatus;
use App\Enums\QuotationStatus;
use App\Enums\JobStatus;
use App\Enums\TripStatus;
use App\Enums\PaymentStatus;
use App\Enums\TruckType;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'shipment_statuses' => ShipmentStatus::cases(),
            'quotation_statuses' => QuotationStatus::cases(),
            'job_statuses' => JobStatus::cases(),
            'trip_statuses' => TripStatus::cases(),
            'payment_statuses' => PaymentStatus::cases(),
            'truck_types' => TruckType::cases(),
            'currency' => config('mz.currency'),
            'commission_rate' => config('mz.commission_rate'),
        ]);
    }
}
