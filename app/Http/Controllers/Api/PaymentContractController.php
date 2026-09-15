<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePaymentContractRequest;
use App\Http\Resources\PaymentContractResource;
use App\Models\Organization;
use App\Services\PaymentContractService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentContractController extends Controller
{
    public function __construct(private readonly PaymentContractService $contracts) {}

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isCustomer() && $user->organization, 403);

        $contract = $this->contracts->ensureForCustomer($user->organization);

        return ApiResponse::success(PaymentContractResource::make($contract));
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canReview($request), 403);

        return ApiResponse::success(
            PaymentContractResource::collection($this->contracts->paginatePending($request->all()))
        );
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->assertCanView($request, $organization);
        abort_unless($organization->isCustomer(), 404);

        return ApiResponse::success(
            PaymentContractResource::make($this->contracts->ensureForCustomer($organization)->load('organization'))
        );
    }

    public function update(UpdatePaymentContractRequest $request, Organization $organization): JsonResponse
    {
        $this->assertCanManage($request, $organization);

        $contract = $this->contracts->requestTerms(
            $request->user(),
            $organization,
            $request->validated(),
        );

        return ApiResponse::success(PaymentContractResource::make($contract), 'Payment terms updated.');
    }

    public function approve(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($this->canReview($request), 403);
        abort_unless($organization->isCustomer(), 404);

        return ApiResponse::success(
            PaymentContractResource::make($this->contracts->approve($request->user(), $organization)),
            'Payment terms approved.'
        );
    }

    public function reject(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($this->canReview($request), 403);
        abort_unless($organization->isCustomer(), 404);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(
            PaymentContractResource::make(
                $this->contracts->reject($request->user(), $organization, $data['reason'] ?? null)
            ),
            'Payment terms request rejected.'
        );
    }

    private function assertCanView(Request $request, Organization $organization): void
    {
        $user = $request->user();
        if ($user->isPlatform()) {
            abort_unless(
                $user->can(Permissions::CUSTOMERS_VIEW)
                || $user->can(Permissions::PAYMENTS_VIEW)
                || $user->can(Permissions::PAYMENTS_MANAGE),
                403
            );

            return;
        }

        abort_unless($user->isCustomer() && (int) $user->organization_id === $organization->id, 403);
    }

    private function assertCanManage(Request $request, Organization $organization): void
    {
        $user = $request->user();
        abort_unless($organization->isCustomer(), 404);
        abort_unless(
            $user->isCustomer()
            && (int) $user->organization_id === $organization->id
            && $user->can(Permissions::COMPANY_MANAGE),
            403
        );
    }

    private function canReview(Request $request): bool
    {
        $user = $request->user();

        return $user->isPlatform()
            && ($user->can(Permissions::PAYMENTS_MANAGE) || $user->can(Permissions::CUSTOMERS_MANAGE));
    }
}
