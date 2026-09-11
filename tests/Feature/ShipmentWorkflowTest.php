<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TripStatus;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_quotation_acceptance_creates_job_and_trips(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Cement',
            'weight_tons' => 20,
            'quantity' => 20,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->assertCreated();

        $shipmentId = $shipment->json('data.id');

        $quotation = $this->actingAs($provider, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/quotations", [
            'total_price' => 500,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 2,
            'quantity_per_trip' => 10,
            'duration_days' => 2,
        ])->assertCreated();

        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotation->json('data.id').'/accept')
            ->assertOk();

        $this->assertSame('pending_dispatch', $accept->json('data.status'));
        $this->assertCount(2, $accept->json('data.trips'));
        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->json('data.id'),
            'truck_count' => 1,
            'trip_count' => 2,
        ]);
    }

    public function test_quoted_truck_count_creates_matching_trips_for_dispatch(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Cement',
            'weight_tons' => 60,
            'quantity' => 60,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->assertCreated();

        $quotation = $this->actingAs($provider, 'sanctum')->postJson('/api/v1/shipments/'.$shipment->json('data.id').'/quotations', [
            'total_price' => 1500,
            'truck_count' => 3,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
        ])->assertCreated();

        $this->assertSame(3, $quotation->json('data.truck_count'));
        $this->assertSame(3, $quotation->json('data.trip_count'));

        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotation->json('data.id').'/accept')
            ->assertOk();

        $this->assertCount(3, $accept->json('data.trips'));
        $this->assertEquals(20, (float) $accept->json('data.trips.0.planned_quantity'));
        $this->assertEquals(20, (float) $accept->json('data.trips.2.planned_quantity'));
        $this->assertDatabaseHas('payments', [
            'quotation_id' => $quotation->json('data.id'),
            'status' => 'completed',
        ]);
    }

    public function test_driver_cannot_skip_trip_states(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Pipes',
            'weight_tons' => 12,
            'quantity' => 12,
            'pickup_address' => 'Muscat',
            'pickup_city' => 'Muscat',
            'delivery_address' => 'Salalah',
            'delivery_city' => 'Salalah',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->json('data');

        $quotation = $this->actingAs($provider, 'sanctum')->postJson("/api/v1/shipments/{$shipment['id']}/quotations", [
            'total_price' => 900,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 12,
            'duration_days' => 4,
        ])->json('data');

        $job = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotation['id']}/accept")
            ->json('data');

        $tripId = $job['trips'][0]['id'];

        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
        ])->assertOk();

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => TripStatus::InTransit->value,
        ])->assertStatus(422);
    }

    public function test_provider_cannot_see_another_providers_quotation(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();

        $otherProviderOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Other Fleet',
            'status' => OrganizationStatus::Active,
        ]);
        $other = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $otherProviderOrg->id,
        ]);
        $other->assignRole('Provider Admin');

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Tiles',
            'weight_tons' => 8,
            'quantity' => 8,
            'pickup_address' => 'Muscat',
            'pickup_city' => 'Muscat',
            'delivery_address' => 'Ibri',
            'delivery_city' => 'Ibri',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->json('data');

        $quotation = $this->actingAs($provider, 'sanctum')->postJson("/api/v1/shipments/{$shipment['id']}/quotations", [
            'total_price' => 260,
            'truck_count' => 1,
            'truck_type' => TruckType::Box->value,
            'truck_capacity_tons' => 12,
            'trip_count' => 1,
            'quantity_per_trip' => 8,
            'duration_days' => 1,
        ])->json('data');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/quotations/{$quotation['id']}")
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2?: User, 3?: Truck}
     */
    private function makeCustomerAndProvider(bool $withDriver = false): array
    {
        $customerOrg = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $customerOrg->id,
        ]);
        $customer->assignRole('Company Admin');

        $providerOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $providerOrg->id,
        ]);
        $provider->assignRole('Provider Admin');

        if (! $withDriver) {
            return [$customer, $provider];
        }

        $driver = User::factory()->create([
            'user_type' => UserType::Driver,
            'organization_id' => $providerOrg->id,
        ]);
        $driver->assignRole('Driver');
        DriverProfile::query()->create([
            'user_id' => $driver->id,
            'organization_id' => $providerOrg->id,
            'status' => \App\Enums\DriverStatus::Available,
        ]);
        $truck = Truck::query()->create([
            'organization_id' => $providerOrg->id,
            'plate_number' => 'T-100',
            'type' => TruckType::Flatbed,
            'capacity_tons' => 30,
            'status' => \App\Enums\TruckStatus::Available,
        ]);

        return [$customer, $provider, $driver, $truck];
    }
}
