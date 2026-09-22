<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_equipment_can_stay_with_the_company_or_attach_to_a_truck(): void
    {
        $provider = $this->makeProvider('provider@example.com');
        $truck = $this->makeTruck($provider, 'H 1001');

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/equipment', [
                'name' => 'Twist locks',
                'type' => 'securing',
                'quantity' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('data.truck_id', null);

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/equipment', [
                'name' => 'Mounted crane',
                'type' => 'lifting',
                'quantity' => 1,
                'truck_id' => $truck->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.truck_id', $truck->id)
            ->assertJsonPath('data.truck.plate_number', 'H 1001');

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/equipment?placement=company')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Twist locks');

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/trucks')
            ->assertOk()
            ->assertJsonPath('data.0.equipment.0.name', 'Mounted crane');
    }

    public function test_equipment_can_move_between_company_pool_and_a_truck(): void
    {
        $provider = $this->makeProvider('mover@example.com');
        $truck = $this->makeTruck($provider, 'H 2002');
        $item = Equipment::query()->create([
            'organization_id' => $provider->organization_id,
            'name' => 'Straps',
            'quantity' => 8,
            'status' => 'available',
        ]);

        $this->actingAs($provider, 'sanctum')
            ->putJson('/api/v1/equipment/'.$item->id, [
                'name' => 'Straps',
                'quantity' => 8,
                'truck_id' => $truck->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.truck_id', $truck->id);

        $this->actingAs($provider, 'sanctum')
            ->putJson('/api/v1/equipment/'.$item->id, [
                'name' => 'Straps',
                'quantity' => 8,
                'truck_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.truck_id', null);
    }

    public function test_equipment_cannot_be_attached_to_another_companys_truck(): void
    {
        $provider = $this->makeProvider('owner@example.com');
        $other = $this->makeProvider('other@example.com');
        $foreignTruck = $this->makeTruck($other, 'H 9999');

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/equipment', [
                'name' => 'Forklift',
                'quantity' => 1,
                'truck_id' => $foreignTruck->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('truck_id');
    }

    private function makeProvider(string $email): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'email' => $email,
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }

    private function makeTruck(User $provider, string $plate): Truck
    {
        return Truck::query()->create([
            'organization_id' => $provider->organization_id,
            'plate_number' => $plate,
            'type' => 'flatbed',
            'capacity_tons' => 30,
        ]);
    }
}
