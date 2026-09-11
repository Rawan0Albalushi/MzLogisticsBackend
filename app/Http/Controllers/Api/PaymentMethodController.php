<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Services\PaymentMethodService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly PaymentMethodService $methods) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_MANAGE), 403);

        return ApiResponse::success(PaymentMethodResource::collection($this->methods->all()));
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_MANAGE), 403);

        $method = $this->methods->create($request->validated());

        return ApiResponse::success(PaymentMethodResource::make($method), 'Payment method created.', 201);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_MANAGE), 403);

        $method = $this->methods->update($paymentMethod, $request->validated());

        return ApiResponse::success(PaymentMethodResource::make($method), 'Payment method updated.');
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_MANAGE), 403);

        $this->methods->delete($paymentMethod);

        return ApiResponse::success(null, 'Payment method deleted.');
    }
}
