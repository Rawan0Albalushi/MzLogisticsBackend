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

class OfferSelectionModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_customer_selection_remains_the_default_and_is_frozen_on_publish(): void
    {
        [$customer, $provider, $admin] = $this->makeActors();

        $shipmentId = $this->publishShipment($customer);
        $this->assertSame('customer', $this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->json('data.offer_selection_mode'));

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $this->assertSame('customer', $this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->json('data.offer_selection_mode'));

        $quotationId = $this->submitQuotation($provider, $shipmentId, 500)['id'];
        $visible = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->assertOk();
        $this->assertSame($quotationId, $visible->json('data.quotations.0.id'));
        $this->assertSame(500.0, (float) $visible->json('data.quotations.0.total_price'));

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/accept")
            ->assertOk();

        $this->assertDatabaseHas('payments', [
            'quotation_id' => $quotationId,
            'amount' => 500,
        ]);
    }

    public function test_admin_selection_hides_provider_quotes_and_bills_the_marked_up_price(): void
    {
        [$customer, $provider, $admin] = $this->makeActors();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $this->assertSame('admin', $this->actingAs($admin, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->json('data.offer_selection_mode'));

        $quotation = $this->submitQuotation($provider, $shipmentId, 100);
        $second = $this->submitQuotation($this->makeProvider('Other Haul', 'other@example.com'), $shipmentId, 140);

        $customerView = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->assertOk();
        $this->assertNull($customerView->json('data.quotations'));
        $this->assertNull($customerView->json('data.platform_offer'));

        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/quotations/'.$quotation['id'])->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/quotations/'.$quotation['id'].'/accept')->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'quotation_id' => $quotation['id'],
            'customer_price' => 90,
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_price']);

        $published = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'quotation_id' => $quotation['id'],
            'customer_price' => 130,
        ])->assertCreated();

        $offerId = $published->json('data.id');
        $this->assertSame(100.0, (float) $published->json('data.provider_price'));
        $this->assertSame(30.0, (float) $published->json('data.margin_amount'));

        $customerOffer = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/shipments/{$shipmentId}")->assertOk();
        $this->assertSame(130.0, (float) $customerOffer->json('data.platform_offer.customer_price'));
        $this->assertArrayNotHasKey('provider_price', $customerOffer->json('data.platform_offer'));
        $this->assertArrayNotHasKey('provider', $customerOffer->json('data.platform_offer'));

        $accepted = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/platform-offers/{$offerId}/accept")
            ->assertOk();

        $this->assertSame(130.0, (float) $accepted->json('data.total_price'));
        $this->assertNull($accepted->json('data.quotation'));

        $providerJob = $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/jobs/'.$accepted->json('data.id'))
            ->assertOk();
        $this->assertSame(100.0, (float) $providerJob->json('data.total_price'));

        $this->assertDatabaseHas('payments', [
            'quotation_id' => $quotation['id'],
            'amount' => 130,
            'provider_amount' => 100,
            'commission_amount' => 30,
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotation['id'],
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $second['id'],
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('platform_offers', [
            'id' => $offerId,
            'status' => 'accepted',
        ]);
    }

    public function test_withdrawing_a_provider_quotation_withdraws_the_platform_offer(): void
    {
        [$customer, $provider, $admin] = $this->makeActors();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/settings/offer-selection', [
            'offer_selection_mode' => 'admin',
        ])->assertOk();

        $shipmentId = $this->publishShipment($customer);
        $quotation = $this->submitQuotation($provider, $shipmentId, 80);
        $offerId = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/shipments/{$shipmentId}/platform-offers", [
            'quotation_id' => $quotation['id'],
            'customer_price' => 100,
        ])->assertCreated()->json('data.id');

        $this->actingAs($provider, 'sanctum')->postJson('/api/v1/quotations/'.$quotation['id'].'/withdraw')->assertOk();

        $this->assertDatabaseHas('platform_offers', [
            'id' => $offerId,
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

        $provider = $this->makeProvider();
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return [$customer, $provider, $admin];
    }

    private function makeProvider(string $name = 'Fast Haul', string $email = 'provider@example.com'): User
    {
        $providerOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => $name,
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $providerOrg->id,
            'email' => $email,
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
