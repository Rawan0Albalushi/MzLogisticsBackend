<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GooglePlacesService
{
    /**
     * @return array{lat: float, lng: float, radius: int}
     */
    public function defaultBias(): array
    {
        return [
            'lat' => (float) config('services.google_maps.default_lat', 23.5880),
            'lng' => (float) config('services.google_maps.default_lng', 58.3829),
            'radius' => (int) config('services.google_maps.bias_radius_meters', 400000),
        ];
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google_maps.key'));
    }

    /**
     * @return list<array{place_id: string, description: string, main_text: string|null, secondary_text: string|null}>
     */
    public function autocomplete(string $query, string $language = 'en'): array
    {
        $bias = $this->defaultBias();
        $payload = $this->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
            'input' => $query,
            'language' => $language,
            'location' => $bias['lat'].','.$bias['lng'],
            'radius' => $bias['radius'],
        ]);

        return collect($payload['predictions'] ?? [])
            ->map(function (array $prediction): array {
                $structured = is_array($prediction['structured_formatting'] ?? null)
                    ? $prediction['structured_formatting']
                    : [];

                return [
                    'place_id' => (string) ($prediction['place_id'] ?? ''),
                    'description' => (string) ($prediction['description'] ?? ''),
                    'main_text' => isset($structured['main_text']) ? (string) $structured['main_text'] : null,
                    'secondary_text' => isset($structured['secondary_text']) ? (string) $structured['secondary_text'] : null,
                ];
            })
            ->filter(fn (array $item): bool => $item['place_id'] !== '' && $item['description'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{place_id: string, name: string|null, address: string, city: string, lat: float, lng: float}
     */
    public function details(string $placeId, string $language = 'en'): array
    {
        $payload = $this->get('https://maps.googleapis.com/maps/api/place/details/json', [
            'place_id' => $placeId,
            'language' => $language,
            'fields' => 'place_id,name,formatted_address,geometry,address_components',
        ]);

        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $location = $this->locationFromResult($result);

        if ($location === null) {
            throw new RuntimeException('Google Maps did not return coordinates for this place.');
        }

        return [
            'place_id' => (string) ($result['place_id'] ?? $placeId),
            'name' => isset($result['name']) ? (string) $result['name'] : null,
            'address' => $this->addressFromResult($result),
            'city' => $this->cityFromComponents($result['address_components'] ?? []),
            'lat' => $location['lat'],
            'lng' => $location['lng'],
        ];
    }

    /**
     * @return array{address: string, city: string, lat: float, lng: float}|null
     */
    public function reverse(float $lat, float $lng, string $language = 'en'): ?array
    {
        $payload = $this->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => $lat.','.$lng,
            'language' => $language,
        ]);

        $result = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : null;
        if ($result === null) {
            return null;
        }

        return [
            'address' => $this->addressFromResult($result),
            'city' => $this->cityFromComponents($result['address_components'] ?? []),
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Maps is not configured.');
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get($url, [
                    ...$query,
                    'key' => config('services.google_maps.key'),
                ])
                ->throw();
        } catch (RequestException $exception) {
            Log::warning('Google Maps request failed.', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to reach Google Maps.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];
        $status = (string) ($payload['status'] ?? 'UNKNOWN');

        if (in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            return $payload;
        }

        Log::warning('Google Maps returned an error status.', [
            'url' => $url,
            'status' => $status,
            'error' => $payload['error_message'] ?? null,
        ]);

        throw new RuntimeException('Google Maps could not complete this lookup.');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{lat: float, lng: float}|null
     */
    private function locationFromResult(array $result): ?array
    {
        $location = $result['geometry']['location'] ?? null;
        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function addressFromResult(array $result): string
    {
        $formatted = trim((string) ($result['formatted_address'] ?? ''));
        if ($formatted !== '') {
            return mb_substr($formatted, 0, 255);
        }

        return mb_substr(trim((string) ($result['name'] ?? '')), 0, 255);
    }

    /**
     * @param  mixed  $components
     */
    private function cityFromComponents(mixed $components): string
    {
        if (! is_array($components)) {
            return '';
        }

        $priority = [
            'locality',
            'postal_town',
            'administrative_area_level_2',
            'administrative_area_level_1',
            'sublocality',
        ];

        foreach ($priority as $type) {
            foreach ($components as $component) {
                if (! is_array($component)) {
                    continue;
                }

                $types = $component['types'] ?? [];
                if (is_array($types) && in_array($type, $types, true)) {
                    $name = trim((string) ($component['long_name'] ?? ''));
                    if ($name !== '') {
                        return mb_substr($name, 0, 120);
                    }
                }
            }
        }

        return '';
    }
}
