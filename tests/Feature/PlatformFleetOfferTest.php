<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Trip;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformFleetOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_platform_organization_is_hidden_from_provider_directory(): void
    {
        $admin = $this->makeAdmin();
        $this->makeProvider();

        $names = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/providers')->assertOk()->json('data'))
            ->pluck('type');

        $this->assertTrue(Organization::query()->where('type', OrganizationType::Platform)->exists());
        $this->assertFalse($names->contains(OrganizationType::Platform->value));
    }

    public function test_platform_staff_manage_their_own_fleet_and_assign_it(): void
    {
        [$customer, $provider, $admin] = $this->makeActors();
        $platformId = Organization::platform()->id;

        $driverId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/drivers', [
            'name' => 'Platform Driver',
            'phone' => '99330011',
        ])->assertCreated()->json('data.driver.id');

        $truckId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/trucks', [
            'plate_number' => 'MX 1001',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/equipment', [
            'name' => 'Straps',
            'type' => 'cargo',
            'quantity' => 4,
            'truck_id' => $truckId,
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'id' => $driverId,
            'organization_id' => $platformId,
            'user_type' => UserType::Driver->value,
        ]);
        $this->assertDatabaseHas('trucks', [
            'id' => $truckId,
            'organization_id' => $platformId,
            'plate_number' => 'MX 1001',
        ]);
        $this->assertDatabaseHas('equipment', [
            'organization_id' => $platformId,
            'truck_id' => $truckId,
            'name' => 'Straps',
        ]);

        $providerTrucks = collect($this->actingAs($provider, 'sanctum')->getJson('/api/v1/trucks')->assertOk()->json('data'));
        $this->assertFalse($providerTrucks->pluck('plate_number')->contains('MX 1001'));

        $providerTruckId = $this->actingAs($provider, 'sanctum')->postJson('/api/v1/trucks', [
            'plate_number' => 'PR 2002',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 20,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/trucks/{$providerTruckId}", [
            'plate_number' => 'PR 2002',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 20,
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $providerQuote = $this->submitQuotation($provider, $shipmentId, 90);

        $published = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'total_price' => 150,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
            'conditions' => 'Platform fleet',
        ])->assertCreated();

        $this->assertSame(0.0, (float) $published->json('data.provider_price'));
        $this->assertSame(150.0, (float) $published->json('data.customer_price'));
        $this->assertSame(150.0, (float) $published->json('data.margin_amount'));
        $this->assertTrue($published->json('data.owned_by_platform'));
        $this->assertArrayNotHasKey('provider', $published->json('data'));

        $internalId = Quotation::query()
            ->where('shipment_request_id', $shipmentId)
            ->where('provider_organization_id', $platformId)
            ->value('id');

        $adminShipment = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->assertOk();
        $visibleQuoteIds = collect($adminShipment->json('data.quotations'))->pluck('id');
        $this->assertTrue($visibleQuoteIds->contains($providerQuote['id']));
        $this->assertFalse($visibleQuoteIds->contains($internalId));

        $customerOffer = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->assertOk();
        $this->assertNull($customerOffer->json('data.quotations'));
        $this->assertSame(150.0, (float) $customerOffer->json('data.platform_offer.customer_price'));
        $this->assertArrayNotHasKey('provider_price', $customerOffer->json('data.platform_offer'));

        $providerQuotes = collect($this->actingAs($provider, 'sanctum')->getJson('/api/v1/quotations')->assertOk()->json('data'));
        $this->assertFalse($providerQuotes->pluck('id')->contains($internalId));

        $accepted = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/platform-offers/'.$published->json('data.id').'/accept')
            ->assertOk();

        $jobId = $accepted->json('data.id');
        $this->assertDatabaseHas('transport_jobs', [
            'id' => $jobId,
            'provider_organization_id' => $platformId,
        ]);
        $this->assertSame(150.0, (float) $accepted->json('data.total_price'));
        $this->assertDatabaseHas('quotations', [
            'id' => $providerQuote['id'],
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('invoices', [
            'transport_job_id' => $jobId,
            'type' => 'customer',
        ]);
        $this->assertDatabaseMissing('invoices', [
            'transport_job_id' => $jobId,
            'type' => 'provider',
        ]);
        $this->assertDatabaseHas('payments', [
            'quotation_id' => $internalId,
            'amount' => 150,
            'provider_amount' => 0,
            'commission_amount' => 150,
        ]);
        $this->assertDatabaseCount('wallet_transactions', 0);

        $tripId = Trip::query()->where('transport_job_id', $jobId)->value('id');
        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truckId,
            'driver_id' => $driverId,
            'departure_time' => '10:00',
            'driver_pay_amount' => 12,
        ])->assertOk()->assertJsonPath('data.status', 'assigned');
    }

    public function test_admin_can_publish_an_offer_and_confirm_the_agreement_later(): void
    {
        [$customer, , $admin] = $this->makeActors();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $offerId = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'total_price' => 80,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 20,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'published')
            ->json('data.id');

        $this->assertDatabaseHas('shipment_requests', [
            'id' => $shipmentId,
            'status' => 'published',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/platform-offers/{$offerId}/confirm")
            ->assertForbidden();

        $confirmed = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/platform-offers/{$offerId}/confirm")
            ->assertOk();

        $this->assertSame('pending_dispatch', $confirmed->json('data.status'));
        $this->assertDatabaseHas('platform_offers', [
            'id' => $offerId,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('shipment_requests', [
            'id' => $shipmentId,
            'status' => 'awarded',
        ]);
        $this->assertDatabaseHas('invoices', [
            'transport_job_id' => $confirmed->json('data.id'),
            'type' => 'customer',
            'status' => 'issued',
        ]);
        $this->assertDatabaseMissing('payments', [
            'shipment_request_id' => $shipmentId,
        ]);
    }

    public function test_admin_can_confirm_the_agreement_in_the_same_step_as_publishing(): void
    {
        [$customer, , $admin] = $this->makeActors();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $confirmed = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'total_price' => 120,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 20,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 1,
            'confirm' => true,
        ])->assertCreated();

        $this->assertSame('pending_dispatch', $confirmed->json('data.status'));
        $this->assertDatabaseHas('platform_offers', [
            'shipment_request_id' => $shipmentId,
            'status' => 'accepted',
            'customer_price' => 120,
        ]);
        $this->assertDatabaseHas('shipment_requests', [
            'id' => $shipmentId,
            'status' => 'awarded',
        ]);
        $this->assertDatabaseMissing('payments', [
            'shipment_request_id' => $shipmentId,
        ]);
    }

    public function test_withdrawing_a_platform_offer_withdraws_its_internal_quotation(): void
    {
        [$customer, , $admin] = $this->makeActors();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $offerId = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'total_price' => 80,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 20,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 1,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/platform-offers/{$offerId}/withdraw")->assertOk();

        $this->assertDatabaseHas('platform_offers', [
            'id' => $offerId,
            'status' => 'withdrawn',
        ]);
        $this->assertDatabaseHas('quotations', [
            'provider_organization_id' => Organization::platform()->id,
            'shipment_request_id' => $shipmentId,
            'status' => 'withdrawn',
        ]);
        $this->assertNull($this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->json('data.platform_offer'));
    }

    /**
     * @return array{0: User, 1: User, 2: User}
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

        return [$customer, $this->makeProvider(), $this->makeAdmin()];
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return $admin;
    }

    private function makeProvider(): User
    {
        $providerOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $providerOrg->id,
            'email' => 'provider@example.com',
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }

    private function publishShipment(User $customer): int
    {
        return $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
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
    }

    /**
     * @return array<string, mixed>
     */
    private function submitQuotation(User $provider, int $shipmentId, float $price): array
    {
        return $this->actingAs($provider, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/quotations", [
            'total_price' => $price,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
        ])->assertCreated()->json('data');
    }
}
