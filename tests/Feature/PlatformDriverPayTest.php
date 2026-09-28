<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\DriverPayable;
use App\Models\Organization;
use App\Models\Trip;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDriverPayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_platform_trip_pay_is_estimated_at_assignment_and_owed_when_completed(): void
    {
        [$customer, $admin] = $this->makeActors();

        $driverId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Platform Driver',
            'phone' => '99330021',
        ])->assertCreated()->json('data.driver.id');

        $truckId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/trucks', [
            'plate_number' => 'MX 3003',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
        ])->assertCreated()->json('data.id');

        $tripId = $this->acceptedPlatformTrip($customer, $admin);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
        ])->assertUnprocessable();

        $assigned = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
            'driver_pay_amount' => 15,
        ])->assertOk();

        $this->assertEquals(15.0, (float) $assigned->json('data.driver_pay_amount'));
        $this->assertArrayNotHasKey('driver_pay_amount', $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$tripId}")->json('data'));
        $this->assertDatabaseCount('driver_payables', 0);

        $jobId = Trip::query()->whereKey($tripId)->value('transport_job_id');
        $estimated = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/jobs/{$jobId}")->assertOk();
        $this->assertEquals(15.0, (float) $estimated->json('data.driver_cost'));
        $this->assertEquals(135.0, (float) $estimated->json('data.net_amount'));
        $this->assertArrayNotHasKey('driver_cost', $this->actingAs($customer, 'sanctum')->getJson("/api/v1/jobs/{$jobId}")->json('data'));

        Trip::query()->whereKey($tripId)->update(['status' => 'delivered']);
        $driver = User::query()->findOrFail($driverId);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('driver_payables', [
            'trip_id' => $tripId,
            'driver_user_id' => $driverId,
            'amount' => 15,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);

        $payableId = DriverPayable::query()->where('trip_id', $tripId)->value('id');
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/driver-payables')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/driver-payables/{$payableId}/pay")->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/driver-payables/{$payableId}/pay")->assertUnprocessable();
    }

    public function test_cancelling_before_completion_creates_no_payable_and_default_rate_can_fill_the_amount(): void
    {
        [$customer, $admin] = $this->makeActors();

        $driverId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Rated Driver',
            'phone' => '99330022',
            'trip_rate' => 8,
        ])->assertCreated()->json('data.driver.id');
        $truckId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/trucks', [
            'plate_number' => 'MX 3004',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
        ])->assertCreated()->json('data.id');

        $tripId = $this->acceptedPlatformTrip($customer, $admin);
        $assigned = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
        ])->assertOk();
        $this->assertEquals(8.0, (float) $assigned->json('data.driver_pay_amount'));

        $driver = User::query()->findOrFail($driverId);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/status", [
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertDatabaseCount('driver_payables', 0);
        $jobId = Trip::query()->whereKey($tripId)->value('transport_job_id');
        $this->assertEquals(0.0, (float) $this->actingAs($admin, 'sanctum')->getJson("/api/v1/jobs/{$jobId}")->json('data.driver_cost'));
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
        ])->assertCreated()->json('data.id');

        $jobId = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/platform-offers/{$offerId}/accept")
            ->assertOk()
            ->json('data.id');

        return (int) Trip::query()->where('transport_job_id', $jobId)->value('id');
    }
}
