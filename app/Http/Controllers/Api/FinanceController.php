<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Settlement;
use App\Services\SettlementService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function payments(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW), 403);
        $user = $request->user();

        $items = Payment::query()
            ->with(['quotation.providerOrganization', 'payerOrganization'])
            ->when($user->isCustomer(), fn ($q) => $q->where('payer_organization_id', $user->organization_id))
            ->when($user->isProvider(), fn ($q) => $q->whereHas('quotation', fn ($quotation) => $quotation->where('provider_organization_id', $user->organization_id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(PaymentResource::collection($items));
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVOICES_VIEW), 403);
        $user = $request->user();

        $items = Invoice::query()
            ->with(['organization', 'transportJob', 'payment'])
            ->when(! $user->isPlatform(), fn ($q) => $q->where('organization_id', $user->organization_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(InvoiceResource::collection($items));
    }

    public function settlements(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTLEMENTS_VIEW), 403);
        $user = $request->user();

        $items = Settlement::query()
            ->with('providerOrganization')
            ->when($user->isProvider(), fn ($q) => $q->where('provider_organization_id', $user->organization_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success($items);
    }

    public function storeSettlement(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTLEMENTS_MANAGE), 403);
        $data = $request->validate([
            'provider_organization_id' => ['required', 'exists:organizations,id'],
            'amount' => ['required', 'numeric', 'min:0.001'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $settlement = $this->settlements->create($request->user(), $data);

        return ApiResponse::success($settlement, 'Settlement created.', 201);
    }

    public function completeSettlement(Request $request, Settlement $settlement): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTLEMENTS_MANAGE), 403);

        return ApiResponse::success($this->settlements->complete($request->user(), $settlement), 'Settlement completed.');
    }
}
