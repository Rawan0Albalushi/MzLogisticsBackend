<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Wallet;
use App\Services\WalletLedgerService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private readonly WalletLedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanView($request);
        $user = $request->user();

        if ($user->isProvider() && $user->organization) {
            $this->ledger->forProvider($user->organization);
        }

        $items = Wallet::query()
            ->with('organization')
            ->when($user->isProvider(), fn ($query) => $query->where('organization_id', $user->organization_id))
            ->when(
                $user->isPlatform() && $request->filled('organization_id'),
                fn ($query) => $query->where('organization_id', $request->integer('organization_id'))
            )
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(WalletResource::collection($items));
    }

    public function show(Request $request, Wallet $wallet): JsonResponse
    {
        $this->assertCanAccess($request, $wallet);

        return ApiResponse::success(WalletResource::make($wallet->load('organization')));
    }

    public function transactions(Request $request, Wallet $wallet): JsonResponse
    {
        $this->assertCanAccess($request, $wallet);

        $items = $wallet->transactions()
            ->with(['payment', 'transportJob'])
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(WalletTransactionResource::collection($items));
    }

    private function assertCanView(Request $request): void
    {
        abort_unless($request->user()->can(Permissions::WALLETS_VIEW), 403);
        abort_if($request->user()->isCustomer() || $request->user()->isDriver(), 403);
    }

    private function assertCanAccess(Request $request, Wallet $wallet): void
    {
        $this->assertCanView($request);
        $user = $request->user();

        abort_if($user->isProvider() && (int) $wallet->organization_id !== (int) $user->organization_id, 403);
    }
}
