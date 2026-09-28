<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverPayableResource;
use App\Models\DriverPayable;
use App\Services\DriverPayableService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverPayableController extends Controller
{
    public function __construct(private readonly DriverPayableService $payables) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isPlatform() && $request->user()->can(Permissions::SETTLEMENTS_VIEW), 403);

        return ApiResponse::success(
            DriverPayableResource::collection($this->payables->paginate($request->all()))
        );
    }

    public function pay(Request $request, DriverPayable $driverPayable): JsonResponse
    {
        abort_unless($request->user()->isPlatform() && $request->user()->can(Permissions::SETTLEMENTS_MANAGE), 403);

        return ApiResponse::success(
            DriverPayableResource::make($this->payables->markPaid($request->user(), $driverPayable)),
            'Driver pay recorded.'
        );
    }
}
