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
     * @return array{place_id: string, name: string|null, address: string, city: string, governorate: string, wilayat: string, lat: float, lng: float}
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

        $divisions = $this->divisionsFromComponents($result['address_components'] ?? []);

        return [
            'place_id' => (string) ($result['place_id'] ?? $placeId),
            'name' => isset($result['name']) ? (string) $result['name'] : null,
            'address' => $this->addressFromResult($result),
            'city' => $divisions['city'],
            'governorate' => $divisions['governorate'],
            'wilayat' => $divisions['wilayat'],
            'lat' => $location['lat'],
            'lng' => $location['lng'],
        ];
    }

    /**
     * @return array{address: string, city: string, governorate: string, wilayat: string, lat: float, lng: float}|null
     */
    public function reverse(float $lat, float $lng, string $language = 'en'): ?array
    {
        $google = null;
        if ($this->isConfigured()) {
            try {
                $google = $this->reverseGoogle($lat, $lng, $language);
            } catch (RuntimeException) {
                $google = null;
            }
        }
        if ($google !== null && $google['governorate'] !== '' && $google['wilayat'] !== '') {
            return $google;
        }

        $osm = $this->reverseOsm($lat, $lng, $language);
        if ($osm === null) {
            return $google;
        }
        if ($google === null) {
            return $osm;
        }

        return [
            'address' => $google['address'] !== '' ? $google['address'] : $osm['address'],
            'city' => $osm['city'] !== '' ? $osm['city'] : $google['city'],
            'governorate' => $osm['governorate'] !== '' ? $osm['governorate'] : $google['governorate'],
            'wilayat' => $osm['wilayat'] !== '' ? $osm['wilayat'] : $google['wilayat'],
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    /**
     * @return array{address: string, city: string, governorate: string, wilayat: string, lat: float, lng: float}|null
     */
    private function reverseGoogle(float $lat, float $lng, string $language): ?array
    {
        $payload = $this->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => $lat.','.$lng,
            'language' => $language,
        ]);

        $best = null;
        foreach ($payload['results'] ?? [] as $result) {
            if (! is_array($result)) {
                continue;
            }
            $divisions = $this->divisionsFromComponents($result['address_components'] ?? []);
            $candidate = [
                'address' => $this->addressFromResult($result),
                'city' => $divisions['city'],
                'governorate' => $divisions['governorate'],
                'wilayat' => $divisions['wilayat'],
                'lat' => $lat,
                'lng' => $lng,
            ];
            if ($best === null) {
                $best = $candidate;
            }
            if ($candidate['governorate'] !== '' && $candidate['wilayat'] !== '') {
                return $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array{address: string, city: string, governorate: string, wilayat: string, lat: float, lng: float}|null
     */
    private function reverseOsm(float $lat, float $lng, string $language): ?array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'MZ-Logistics/1.0',
                    'Accept-Language' => $language,
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'zoom' => 14,
                ])
                ->throw();
        } catch (RequestException $exception) {
            Log::warning('Nominatim reverse lookup failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $payload = $response->json();
        $address = is_array($payload) && is_array($payload['address'] ?? null)
            ? $payload['address']
            : [];
        if ($address === []) {
            return null;
        }

        $governorate = $this->stripDivisionPrefix((string) ($address['state'] ?? $address['region'] ?? ''));
        $wilayat = $this->stripDivisionPrefix((string) (
            $address['province']
            ?? $address['county']
            ?? $address['municipality']
            ?? $address['state_district']
            ?? ''
        ));
        if ($wilayat === '' || $this->samePlaceName($wilayat, $governorate)) {
            $wilayat = $this->stripDivisionPrefix((string) (
                $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['suburb'] ?? ''
            ));
        }
        if ($wilayat !== '' && $this->samePlaceName($wilayat, $governorate)) {
            $wilayat = $this->stripDivisionPrefix((string) ($address['suburb'] ?? $address['city_district'] ?? ''));
        }

        $city = $this->joinDivisions($wilayat, $governorate);
        if ($governorate === '' && $wilayat === '') {
            return null;
        }

        return [
            'address' => '',
            'city' => $city,
            'governorate' => $governorate,
            'wilayat' => $wilayat,
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
     * @return array{governorate: string, wilayat: string, city: string}
     */
    private function divisionsFromComponents(mixed $components): array
    {
        $governorate = $this->stripDivisionPrefix($this->componentName($components, ['administrative_area_level_1']));
        $wilayat = $this->stripDivisionPrefix($this->componentName($components, [
            'administrative_area_level_2',
            'locality',
            'postal_town',
            'sublocality',
            'sublocality_level_1',
            'administrative_area_level_3',
        ]);

        if ($wilayat !== '' && $this->samePlaceName($wilayat, $governorate)) {
            $wilayat = $this->componentName($components, [
                'sublocality',
                'sublocality_level_1',
                'neighborhood',
            ]);
        }

        $city = $this->joinDivisions($wilayat, $governorate);
        if ($city === '') {
            $city = $this->cityFromComponents($components);
        }

        return [
            'governorate' => $governorate,
            'wilayat' => $wilayat,
            'city' => $city,
        ];
    }

    /**
     * @param  mixed  $components
     * @param  list<string>  $types
     */
    private function componentName(mixed $components, array $types): string
    {
        if (! is_array($components)) {
            return '';
        }

        foreach ($types as $type) {
            foreach ($components as $component) {
                if (! is_array($component)) {
                    continue;
                }

                $componentTypes = $component['types'] ?? [];
                if (! is_array($componentTypes) || ! in_array($type, $componentTypes, true)) {
                    continue;
                }

                $name = trim((string) ($component['long_name'] ?? ''));
                if ($name !== '') {
                    return mb_substr($name, 0, 120);
                }
            }
        }

        return '';
    }

    private function samePlaceName(string $left, string $right): bool
    {
        return mb_strtolower($this->stripDivisionPrefix($left)) === mb_strtolower($this->stripDivisionPrefix($right));
    }

    private function stripDivisionPrefix(string $value): string
    {
        $name = trim($value);
        $name = preg_replace('/^(محافظة|ولاية)\s+/u', '', $name) ?? $name;
        $name = preg_replace('/^(Governorate|Wilayat|Wilaya)(?:\s+of)?\s+/iu', '', $name) ?? $name;
        $name = preg_replace('/\s+(Governorate|Wilayat|Wilaya)$/iu', '', $name) ?? $name;

        return mb_substr(trim($name), 0, 120);
    }

    private function joinDivisions(string $wilayat, string $governorate): string
    {
        $parts = [];
        foreach ([$wilayat, $governorate] as $part) {
            if ($part === '') {
                continue;
            }
            $seen = false;
            foreach ($parts as $existing) {
                if ($this->samePlaceName($existing, $part)) {
                    $seen = true;
                    break;
                }
            }
            if (! $seen) {
                $parts[] = $part;
            }
        }

        return mb_substr(implode(', ', $parts), 0, 120);
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
