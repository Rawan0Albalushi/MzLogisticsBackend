<?php

namespace App\Http\Controllers\Api;

use App\Enums\OfferSelectionMode;
use App\Http\Controllers\Controller;
use App\Services\PlatformSettingService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformSettingController extends Controller
{
    public function __construct(private readonly PlatformSettingService $settings) {}

    public function showOfferSelection(Request $request): JsonResponse
    {
        abort_unless($request->user()->isPlatform(), 403);
        abort_unless(
            $request->user()->can(Permissions::QUOTATIONS_VIEW)
            || $request->user()->can(Permissions::QUOTATIONS_MANAGE),
            403,
        );

        return ApiResponse::success([
            'offer_selection_mode' => $this->settings->offerSelectionMode()->value,
        ]);
    }

    public function updateOfferSelection(Request $request): JsonResponse
    {
        abort_unless($request->user()->isPlatform(), 403);
        abort_unless($request->user()->can(Permissions::QUOTATIONS_MANAGE), 403);

        $data = $request->validate([
            'offer_selection_mode' => ['required', Rule::enum(OfferSelectionMode::class)],
        ]);

        $mode = $this->settings->updateOfferSelectionMode(
            $request->user(),
            OfferSelectionMode::from($data['offer_selection_mode']),
        );

        return ApiResponse::success([
            'offer_selection_mode' => $mode->value,
        ], 'Offer selection mode updated.');
    }
}
