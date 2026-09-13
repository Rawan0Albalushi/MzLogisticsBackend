<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GooglePlacesService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PlacesController extends Controller
{
    public function __construct(private readonly GooglePlacesService $places) {}

    public function autocomplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        return $this->handle(fn () => [
            'suggestions' => $this->places->autocomplete(
                $data['query'],
                $this->language($request),
            ),
        ]);
    }

    public function details(Request $request): JsonResponse
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'max:255'],
        ]);

        return $this->handle(fn () => $this->places->details(
            $data['place_id'],
            $this->language($request),
        ));
    }

    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->handle(fn () => $this->places->reverse(
            (float) $data['lat'],
            (float) $data['lng'],
            $this->language($request),
        ) ?? []);
    }

    private function language(Request $request): string
    {
        $header = strtolower((string) $request->header('Accept-Language', 'en'));

        return str_starts_with($header, 'ar') ? 'ar' : 'en';
    }

    private function handle(callable $callback): JsonResponse
    {
        if (! $this->places->isConfigured()) {
            return ApiResponse::error('Google Maps is not configured.', 503);
        }

        try {
            return ApiResponse::success($callback());
        } catch (RuntimeException $exception) {
            return ApiResponse::error($exception->getMessage(), 502);
        }
    }
}
