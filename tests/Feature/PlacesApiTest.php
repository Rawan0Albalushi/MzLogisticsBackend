<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlacesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_places_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/places/autocomplete?query=sohar')->assertUnauthorized();
        $this->getJson('/api/v1/places/details?place_id=abc')->assertUnauthorized();
        $this->getJson('/api/v1/places/reverse?lat=23.5&lng=58.4')->assertUnauthorized();
    }

    public function test_places_return_service_unavailable_when_maps_key_is_missing(): void
    {
        config(['services.google_maps.key' => null]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/v1/places/autocomplete?query=sohar')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Google Maps is not configured.');
    }

    public function test_autocomplete_returns_google_suggestions(): void
    {
        config(['services.google_maps.key' => 'test-key']);
        Http::fake([
            'maps.googleapis.com/maps/api/place/autocomplete/json*' => Http::response([
                'status' => 'OK',
                'predictions' => [[
                    'place_id' => 'ChIJsohar',
                    'description' => 'Sohar Port, Sohar, Oman',
                    'structured_formatting' => [
                        'main_text' => 'Sohar Port',
                        'secondary_text' => 'Sohar, Oman',
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/v1/places/autocomplete?query=sohar')
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.place_id', 'ChIJsohar')
            ->assertJsonPath('data.suggestions.0.main_text', 'Sohar Port');
    }

    public function test_details_return_coordinates_address_and_city(): void
    {
        config(['services.google_maps.key' => 'test-key']);
        Http::fake([
            'maps.googleapis.com/maps/api/place/details/json*' => Http::response([
                'status' => 'OK',
                'result' => [
                    'place_id' => 'ChIJsohar',
                    'name' => 'Sohar Port',
                    'formatted_address' => 'Sohar Port, Sohar, Oman',
                    'geometry' => ['location' => ['lat' => 24.3820, 'lng' => 56.7396]],
                    'address_components' => [
                        ['long_name' => 'Sohar', 'types' => ['locality', 'political']],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/v1/places/details?place_id=ChIJsohar')
            ->assertOk()
            ->assertJsonPath('data.city', 'Sohar')
            ->assertJsonPath('data.lat', 24.382)
            ->assertJsonPath('data.lng', 56.7396);
    }

    public function test_reverse_geocode_fills_address_from_coordinates(): void
    {
        config(['services.google_maps.key' => 'test-key']);
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Nizwa Central Market, Nizwa, Oman',
                    'address_components' => [
                        ['long_name' => 'Nizwa', 'types' => ['locality', 'political']],
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($this->customer(), 'sanctum')
            ->getJson('/api/v1/places/reverse?lat=22.9333&lng=57.5333')
            ->assertOk()
            ->assertJsonPath('data.city', 'Nizwa')
            ->assertJsonPath('data.address', 'Nizwa Central Market, Nizwa, Oman');
    }

    private function customer(): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $user = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $organization->id,
        ]);
        $user->assignRole('Company Admin');

        return $user;
    }
}
