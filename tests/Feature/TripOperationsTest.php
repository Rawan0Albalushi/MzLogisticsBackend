<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Trip;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_platform_can_record_trailer_delivery_note_and_operations_notes(): void
    {
        [$customer, $admin] = $this->makeActors();
        $tripId = $this->acceptedPlatformTrip($customer, $admin);

        $saved = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/operations", [
            'trailer_plate' => '4491 DK',
            'delivery_note_number' => '20',
            'operations_notes' => 'Waiting for invoice',
        ])->assertOk();

        $saved->assertJsonPath('data.trailer_plate', '4491 DK')
            ->assertJsonPath('data.delivery_note_number', '20')
            ->assertJsonPath('data.operations_notes', 'Waiting for invoice');

        $this->assertDatabaseHas('trips', [
            'id' => $tripId,
            'trailer_plate' => '4491 DK',
            'delivery_note_number' => '20',
            'operations_notes' => 'Waiting for invoice',
        ]);

        $customerView = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$tripId}")->assertOk();
        $customerView->assertJsonPath('data.trailer_plate', '4491 DK')
            ->assertJsonPath('data.delivery_note_number', '20');
        $this->assertArrayNotHasKey('operations_notes', $customerView->json('data'));

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/operations", [
            'trailer_plate' => '9999 XX',
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/operations", [
            'trailer_plate' => null,
            'delivery_note_number' => null,
            'operations_notes' => null,
        ])->assertOk()
            ->assertJsonPath('data.trailer_plate', null)
            ->assertJsonPath('data.delivery_note_number', null)
            ->assertJsonPath('data.operations_notes', null);
    }

    public function test_platform_admin_can_advance_trip_status_on_behalf_of_the_driver(): void
    {
        [$customer, $admin] = $this->makeActors();
        $tripId = $this->acceptedPlatformTrip($customer, $admin);

        $driverId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Afzal',
            'phone' => '99331121',
        ])->assertCreated()->json('data.driver.id');
        $truckId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/trucks', [
            'plate_number' => 'MX 4410',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
            'driver_pay_amount' => 12,
        ])->assertOk();

        $viewer = User::factory()->create(['user_type' => UserType::Platform]);
        $viewer->assignRole('Operations Staff');

        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'arrived_at_pickup',
        ])->assertForbidden();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'arrived_at_pickup',
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'in_transit',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'arrived_at_pickup',
        ])->assertOk()->assertJsonPath('data.status', 'arrived_at_pickup');

        $this->assertNotNull(Trip::query()->whereKey($tripId)->value('arrived_pickup_at'));
    }

    public function test_cancelled_trip_rejects_operation_log_edits(): void
    {
        [$customer, $admin] = $this->makeActors();
        $tripId = $this->acceptedPlatformTrip($customer, $admin);
        Trip::query()->whereKey($tripId)->update(['status' => 'cancelled']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/operations", [
            'operations_notes' => 'Too late',
        ])->assertForbidden();
    }

    public function test_civil_id_is_stored_once_on_the_driver_and_rejected_when_duplicated(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Afzal',
            'phone' => '99331101',
            'civil_id' => '88940386',
        ])->assertCreated();

        $driverId = $first->json('data.driver.id');
        $this->assertSame('88940386', $first->json('data.driver.driver_profile.civil_id'));

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Amjad',
            'phone' => '99331102',
            'civil_id' => '88940386',
        ])->assertUnprocessable()->assertJsonValidationErrors('civil_id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/drivers/{$driverId}", [
            'name' => 'Afzal',
            'phone' => '99331101',
            'civil_id' => '88940386',
        ])->assertOk()->assertJsonPath('data.driver_profile.civil_id', '88940386');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/drivers/{$driverId}", [
            'name' => 'Afzal',
            'phone' => '99331101',
            'civil_id' => '12',
        ])->assertUnprocessable()->assertJsonValidationErrors('civil_id');
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function makeActors(): array
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

        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return [$customer, $admin];
    }

    private function acceptedPlatformTrip(User $customer, User $admin): int
    {
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Cement',
            'weight_tons' => 20,
            'quantity' => 20,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->assertCreated()->json('data.id');

        $offerId = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'total_price' => 150,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 1,
            'transport_start_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');

        $jobId = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/platform-offers/{$offerId}/accept")
            ->assertOk()
            ->json('data.id');

        return (int) Trip::query()->where('transport_job_id', $jobId)->value('id');
    }
}
