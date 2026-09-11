<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TripStatus;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Enums\WalletTransactionType;
use App\Models\DriverProfile;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_completed_payment_credits_provider_wallet_as_pending(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();
        $quotationId = $this->createAwardedQuotation($customer, $provider, 500);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk();

        $wallet = Wallet::query()->where('organization_id', $provider->organization_id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(450.0, (float) $wallet->pending_balance);
        $this->assertEquals(0.0, (float) $wallet->available_balance);
        $this->assertEquals(450.0, (float) $wallet->lifetime_earned);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::JobEarning->value,
            'amount' => 450,
        ]);
    }

    public function test_job_completion_releases_pending_balance_to_available(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $quotationId = $this->createAwardedQuotation($customer, $provider, 500);

        $job = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
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
            TripStatus::Delivered,
            TripStatus::Completed,
        ] as $status) {
            $this->actingAs($driver, 'sanctum')
                ->postJson("/api/v1/trips/{$tripId}/status", ['status' => $status->value])
                ->assertOk();
        }

        $wallet = Wallet::query()->where('organization_id', $provider->organization_id)->firstOrFail();
        $this->assertEquals(0.0, (float) $wallet->pending_balance);
        $this->assertEquals(450.0, (float) $wallet->available_balance);

        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::EarningReleased)->count());
    }

    public function test_wallet_entries_are_idempotent_for_the_same_payment(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();
        $quotationId = $this->createAwardedQuotation($customer, $provider, 500);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/quotations/'.$quotationId.'/accept')->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/quotations/'.$quotationId.'/accept')->assertOk();

        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::JobEarning)->count());
        $wallet = Wallet::query()->where('organization_id', $provider->organization_id)->firstOrFail();
        $this->assertEquals(450.0, (float) $wallet->pending_balance);
    }

    public function test_provider_can_view_own_wallet_and_ledger(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();
        $quotationId = $this->createAwardedQuotation($customer, $provider, 500);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/quotations/'.$quotationId.'/accept')->assertOk();

        $list = $this->actingAs($provider, 'sanctum')->getJson('/api/v1/wallets')->assertOk();
        $this->assertEquals(450.0, (float) $list->json('data.0.pending_balance'));

        $walletId = $list->json('data.0.id');
        $this->actingAs($provider, 'sanctum')
            ->getJson("/api/v1/wallets/{$walletId}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.type', WalletTransactionType::JobEarning->value);
    }

    public function test_provider_cannot_view_another_providers_wallet(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();
        $quotationId = $this->createAwardedQuotation($customer, $provider, 500);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/quotations/'.$quotationId.'/accept')->assertOk();

        $otherOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Other Fleet',
            'status' => OrganizationStatus::Active,
        ]);
        $other = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $otherOrg->id,
        ]);
        $other->assignRole('Provider Admin');

        $walletId = Wallet::query()->where('organization_id', $provider->organization_id)->value('id');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/wallets/'.$walletId)
            ->assertForbidden();
    }

    public function test_settlement_reserves_available_balance_without_recalculating_commission(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->completePaidJob($customer, $provider, $driver, $truck, 500);

        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/settlements', [
                'provider_organization_id' => $provider->organization_id,
                'amount' => 450,
                'commission_amount' => 99,
                'net_amount' => 1,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertCreated();

        $this->assertEquals(0.0, (float) $create->json('data.commission_amount'));
        $this->assertEquals(450.0, (float) $create->json('data.amount'));
        $this->assertEquals(450.0, (float) $create->json('data.net_amount'));

        $wallet = Wallet::query()->where('organization_id', $provider->organization_id)->firstOrFail();
        $this->assertEquals(0.0, (float) $wallet->available_balance);
        $this->assertEquals(450.0, (float) $wallet->reserved_balance);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/settlements/'.$create->json('data.id').'/complete')
            ->assertOk();

        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertEquals(450.0, (float) $wallet->lifetime_withdrawn);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransactionType::PayoutCompleted)->count());
    }

    public function test_settlement_cannot_exceed_available_wallet_balance(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->completePaidJob($customer, $provider, $driver, $truck, 500);

        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/settlements', [
                'provider_organization_id' => $provider->organization_id,
                'amount' => 451,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    private function completePaidJob(User $customer, User $provider, User $driver, Truck $truck, float $totalPrice): void
    {
        $quotationId = $this->createAwardedQuotation($customer, $provider, $totalPrice);

        $job = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
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
            TripStatus::Delivered,
            TripStatus::Completed,
        ] as $status) {
            $this->actingAs($driver, 'sanctum')
                ->postJson("/api/v1/trips/{$tripId}/status", ['status' => $status->value])
                ->assertOk();
        }
    }

    private function createAwardedQuotation(User $customer, User $provider, float $totalPrice): int
    {
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
        ])->json('data');

        return $this->actingAs($provider, 'sanctum')->postJson('/api/v1/shipments/'.$shipment['id'].'/quotations', [
            'total_price' => $totalPrice,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
        ])->json('data.id');
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
            'plate_number' => 'T-200',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
            'status' => \App\Enums\TruckStatus::Available,
        ]);

        return [$customer, $provider, $driver, $truck];
    }
}
