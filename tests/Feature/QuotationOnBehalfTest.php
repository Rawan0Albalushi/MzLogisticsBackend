<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationOnBehalfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_platform_admin_can_submit_a_quotation_for_an_active_provider(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $customer = $this->makeCustomer();
        $provider = $this->makeProvider('Fast Haul');
        $shipmentId = $this->publishShipment($customer);

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($provider->organization_id))
            ->assertCreated()
            ->assertJsonPath('data.submitted_on_behalf', true)
            ->assertJsonPath('data.provider.id', $provider->organization_id);

        $this->assertSame(90.0, (float) $created->json('data.total_price'));
        $this->assertNull($created->json('data.price_per_trip'));

        $this->assertDatabaseHas('quotations', [
            'id' => $created->json('data.id'),
            'shipment_request_id' => $shipmentId,
            'provider_organization_id' => $provider->organization_id,
            'created_by' => $admin->id,
            'submitted_on_behalf' => true,
            'status' => 'submitted',
        ]);

        $visible = collect($this->actingAs($provider, 'sanctum')->getJson('/api/v1/quotations')->assertOk()->json('data'));
        $this->assertTrue($visible->pluck('id')->contains($created->json('data.id')));

        $customerQuotes = collect($this->actingAs($customer, 'sanctum')->getJson('/api/v1/quotations')->assertOk()->json('data'));
        $this->assertFalse($customerQuotes->pluck('id')->contains($created->json('data.id')));
    }

    public function test_price_per_trip_becomes_the_job_total(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $customer = $this->makeCustomer();
        $provider = $this->makeProvider('Trip Rate Haul');
        $shipmentId = $this->publishShipment($customer);
        $payload = $this->quotePayload($provider->organization_id);
        unset($payload['total_price']);
        $payload['price_per_trip'] = 25;
        $payload['truck_count'] = 4;
        $payload['trip_count'] = 2;

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $payload)
            ->assertCreated();

        $this->assertSame(25.0, (float) $created->json('data.price_per_trip'));
        $this->assertSame(100.0, (float) $created->json('data.total_price'));
        $this->assertSame(4, (int) $created->json('data.trip_count'));
    }

    public function test_operations_manager_can_submit_and_the_quote_can_become_the_customer_offer(): void
    {
        $manager = $this->makePlatformUser('Operations Manager');
        $customer = $this->makeCustomer();
        $provider = $this->makeProvider('Gulf Freight');

        $this->actingAs($manager, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertForbidden();

        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $quotationId = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($provider->organization_id, 80))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'quotation_id' => $quotationId,
            'customer_price' => 100,
        ])->assertCreated()
            ->assertJsonPath('data.provider_price', '80.000')
            ->assertJsonPath('data.customer_price', '100.000');
    }

    public function test_a_second_submission_is_rejected_until_the_on_behalf_quote_is_withdrawn(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $customer = $this->makeCustomer();
        $provider = $this->makeProvider('Fast Haul');
        $shipmentId = $this->publishShipment($customer);
        $payload = $this->quotePayload($provider->organization_id);

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $payload)
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_organization_id']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/quotations/'.$first->json('data.id').'/withdraw')
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($provider->organization_id, 95))
            ->assertCreated()
            ->assertJsonPath('data.reference', $first->json('data.reference'))
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_platform_staff_cannot_withdraw_a_quotation_the_provider_submitted(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $customer = $this->makeCustomer();
        $provider = $this->makeProvider('Fast Haul');
        $shipmentId = $this->publishShipment($customer);

        $quotationId = $this->actingAs($provider, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/quotations", [
            'total_price' => 70,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
            'transport_start_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/withdraw")
            ->assertForbidden();
    }

    public function test_inactive_provider_customer_and_staff_are_rejected(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $staff = $this->makePlatformUser('Operations Staff');
        $customer = $this->makeCustomer();
        $provider = $this->makeProvider('Fast Haul');
        $suspended = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Paused Haul',
            'status' => OrganizationStatus::Suspended,
        ]);
        $shipmentId = $this->publishShipment($customer);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($suspended->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_organization_id']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($customer->organization_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_organization_id']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($provider->organization_id))
            ->assertForbidden();

        $this->actingAs($provider, 'sanctum')
            ->postJson("/api/v1/shipments/{$shipmentId}/quotations/on-behalf", $this->quotePayload($provider->organization_id))
            ->assertForbidden();

        $draftId = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Sand',
            'weight_tons' => 10,
            'pickup_city' => 'Sohar',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => false,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/shipments/{$draftId}/quotations/on-behalf", $this->quotePayload($provider->organization_id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipment']);
    }

    private function makePlatformUser(string $role): User
    {
        $user = User::factory()->create(['user_type' => UserType::Platform]);
        $user->assignRole($role);

        return $user;
    }

    private function makeCustomer(): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $organization->id,
        ]);
        $customer->assignRole('Company Admin');

        return $customer;
    }

    private function makeProvider(string $name): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => $name,
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
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
    private function quotePayload(int $providerOrganizationId, float $price = 90): array
    {
        return [
            'provider_organization_id' => $providerOrganizationId,
            'total_price' => $price,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
            'transport_start_date' => now()->addDay()->toDateString(),
            'conditions' => 'Entered by operations',
        ];
    }
}
