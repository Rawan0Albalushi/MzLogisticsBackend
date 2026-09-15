<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetTruckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_provider_can_store_truck_load_specifications(): void
    {
        $provider = $this->makeProvider();

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/trucks', [
                'plate_number' => 'H 2201',
                'type' => 'flatbed',
                'capacity_tons' => 32,
                'volume_cbm' => 80.5,
                'cargo_length_m' => 13.6,
                'cargo_width_m' => 2.45,
                'cargo_height_m' => 2.7,
                'axle_count' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('data.capacity_tons', '32.00')
            ->assertJsonPath('data.volume_cbm', '80.50')
            ->assertJsonPath('data.cargo_length_m', '13.60')
            ->assertJsonPath('data.cargo_width_m', '2.45')
            ->assertJsonPath('data.cargo_height_m', '2.70')
            ->assertJsonPath('data.axle_count', 4);

        $this->assertDatabaseHas('trucks', [
            'plate_number' => 'H 2201',
            'volume_cbm' => 80.5,
            'axle_count' => 4,
        ]);
    }

    public function test_provider_can_clear_optional_load_specifications(): void
    {
        $provider = $this->makeProvider();
        $truck = Truck::query()->create([
            'organization_id' => $provider->organization_id,
            'plate_number' => 'H 2202',
            'type' => 'box',
            'capacity_tons' => 18,
            'volume_cbm' => 48,
            'cargo_length_m' => 7.2,
            'cargo_width_m' => 2.4,
            'cargo_height_m' => 2.5,
            'axle_count' => 3,
        ]);

        $this->actingAs($provider, 'sanctum')
            ->putJson('/api/v1/trucks/'.$truck->id, [
                'plate_number' => 'H 2202',
                'type' => 'box',
                'capacity_tons' => 18,
                'volume_cbm' => null,
                'cargo_length_m' => null,
                'cargo_width_m' => null,
                'cargo_height_m' => null,
                'axle_count' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.volume_cbm', null)
            ->assertJsonPath('data.axle_count', null);
    }

    private function makeProvider(): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'email' => 'provider@example.com',
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }
}
