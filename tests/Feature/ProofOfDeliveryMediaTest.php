<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DriverStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TripStatus;
use App\Enums\TruckStatus;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProofOfDeliveryMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_customer_can_download_pod_photo_and_signature(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider();
        $tripId = $this->createArrivedTrip($customer, $provider, $driver, $truck);
        $otp = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/trips/{$tripId}")
            ->json('data.otp_code');

        $this->actingAs($driver, 'sanctum')
            ->post("/api/v1/trips/{$tripId}/pod", [
                'receiver_name' => 'Ahmed Al Busaidi',
                'otp' => $otp,
                'received_quantity' => 12,
                'photos' => [UploadedFile::fake()->image('pod.jpg', 40, 30)],
                'signature' => UploadedFile::fake()->image('sign.png', 80, 40),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $photo = $this->actingAs($customer, 'sanctum')
            ->get("/api/v1/trips/{$tripId}/pod/photos/0");
        $photo->assertOk();
        $this->assertNotEmpty($photo->streamedContent());

        $signature = $this->actingAs($customer, 'sanctum')
            ->get("/api/v1/trips/{$tripId}/pod/signature");
        $signature->assertOk();
        $this->assertNotEmpty($signature->streamedContent());
    }

    public function test_unrelated_customer_cannot_download_pod_media(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider();
        $tripId = $this->createArrivedTrip($customer, $provider, $driver, $truck);
        $otp = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/trips/{$tripId}")
            ->json('data.otp_code');

        $this->actingAs($driver, 'sanctum')
            ->post("/api/v1/trips/{$tripId}/pod", [
                'receiver_name' => 'Ahmed Al Busaidi',
                'otp' => $otp,
                'received_quantity' => 12,
                'photos' => [UploadedFile::fake()->image('pod.jpg', 40, 30)],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $otherOrg = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Other Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $other = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $otherOrg->id,
        ]);
        $other->assignRole('Company Admin');

        $this->actingAs($other, 'sanctum')
            ->get("/api/v1/trips/{$tripId}/pod/photos/0")
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Truck}
     */
    private function makeCustomerAndProvider(): array
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

        $driver = User::factory()->create([
            'user_type' => UserType::Driver,
            'organization_id' => $providerOrg->id,
        ]);
        $driver->assignRole('Driver');
        DriverProfile::query()->create([
            'user_id' => $driver->id,
            'organization_id' => $providerOrg->id,
            'status' => DriverStatus::Available,
        ]);
        $truck = Truck::query()->create([
            'organization_id' => $providerOrg->id,
            'plate_number' => 'T-100',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
            'status' => TruckStatus::Available,
        ]);

        return [$customer, $provider, $driver, $truck];
    }

    private function createArrivedTrip(User $customer, User $provider, User $driver, Truck $truck): int
    {
        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Cement',
            'weight_tons' => 12,
            'quantity' => 12,
            'pickup_address' => 'Muscat',
            'pickup_city' => 'Muscat',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
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

        foreach ([
            TripStatus::ArrivedAtPickup,
            TripStatus::Loaded,
            TripStatus::InTransit,
            TripStatus::Arrived,
        ] as $status) {
            $this->actingAs($driver, 'sanctum')
                ->postJson("/api/v1/trips/{$tripId}/status", ['status' => $status->value])
                ->assertOk();
        }

        return $tripId;
    }
}
