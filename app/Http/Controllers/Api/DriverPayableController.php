<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayDriverPayableRequest;
use App\Http\Resources\DriverPayableResource;
use App\Models\DriverPayable;
use App\Services\DriverPayableService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function pay(PayDriverPayableRequest $request, DriverPayable $driverPayable): JsonResponse
    {
        $receipt = $request->file('receipt');

        return ApiResponse::success(
            DriverPayableResource::make($this->payables->markPaid(
                $request->user(),
                $driverPayable,
                $receipt instanceof UploadedFile ? $receipt : null,
            )),
            'Driver pay recorded.'
        );
    }

    public function receipt(Request $request, DriverPayable $driverPayable): StreamedResponse
    {
        abort_unless($request->user()->isPlatform() && $request->user()->can(Permissions::SETTLEMENTS_VIEW), 403);
        abort_unless(filled($driverPayable->receipt_path), 404);
        abort_unless(Storage::disk('local')->exists($driverPayable->receipt_path), 404);

        return Storage::disk('local')->response($driverPayable->receipt_path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
