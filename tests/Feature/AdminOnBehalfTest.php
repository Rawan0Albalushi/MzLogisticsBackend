<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOnBehalfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_platform_admin_can_create_a_customer_and_a_shipment_for_them(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);

        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/customers', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@acme.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '96891234567',
            'account_type' => 'company',
            'company_name' => 'Acme Trading',
            'company_name_ar' => 'أكمة للتجارة',
            'city' => 'Muscat',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Acme Trading')
            ->assertJsonPath('data.type', 'customer')
            ->assertJsonPath('data.status', 'active');

        $organizationId = $created->json('data.id');
        $customer = User::query()->where('email', 'sara@acme.test')->firstOrFail();
        $this->assertSame(UserType::Customer, $customer->user_type);
        $this->assertSame($organizationId, $customer->organization_id);
        $this->assertTrue($customer->hasRole('Company Admin'));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sara@acme.test',
            'password' => 'Password123!',
        ])->assertOk();
        $this->assertNotEmpty($login->json('data.token'));

        $shipment = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/shipments', [
            'customer_organization_id' => $organizationId,
            'cargo_type' => 'Cement',
            'weight_tons' => 18,
            'pickup_city' => 'Sohar',
            'pickup_address' => 'Sohar Port',
            'delivery_city' => 'Nizwa',
            'delivery_address' => 'Nizwa Industrial',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->assertCreated()
            ->assertJsonPath('data.cargo_type', 'Cement')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.customer.id', $organizationId);

        $this->assertDatabaseHas('shipment_requests', [
            'id' => $shipment->json('data.id'),
            'customer_organization_id' => $organizationId,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/shipments/'.$shipment->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.reference', $shipment->json('data.reference'));
    }

    public function test_operations_manager_can_create_a_customer(): void
    {
        $manager = $this->makePlatformUser('Operations Manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/customers', $this->customerPayload())
            ->assertCreated()
            ->assertJsonPath('data.account_type', 'company')
            ->assertJsonPath('data.name', 'Huda Trading');
    }

    public function test_staff_without_customer_manage_cannot_create_a_customer(): void
    {
        $staff = $this->makePlatformUser('Operations Staff');

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/customers', $this->customerPayload())
            ->assertForbidden();
    }

    public function test_platform_admin_can_create_an_active_provider(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);

        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/providers', [
            'name' => 'Salim Al Habsi',
            'email' => 'salim@fasthaul.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '96892345678',
            'company_name' => 'Fast Haul',
            'company_name_ar' => 'النقل السريع',
            'commercial_register' => 'CR-1001',
            'city' => 'Sohar',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Fast Haul')
            ->assertJsonPath('data.type', 'provider')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.commercial_register', 'CR-1001');

        $provider = User::query()->where('email', 'salim@fasthaul.test')->firstOrFail();
        $this->assertSame(UserType::Provider, $provider->user_type);
        $this->assertSame($created->json('data.id'), $provider->organization_id);
        $this->assertTrue($provider->hasRole('Provider Admin'));
        $this->assertSame(OrganizationStatus::Active, $provider->organization->status);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'salim@fasthaul.test',
            'password' => 'Password123!',
        ])->assertOk();
    }

    public function test_self_registered_provider_stays_pending(): void
    {
        $this->postJson('/api/v1/auth/register/provider', [
            'name' => 'Salim Al Habsi',
            'email' => 'salim@pending.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'company_name' => 'Pending Haul',
        ])->assertCreated();

        $provider = User::query()->where('email', 'salim@pending.test')->firstOrFail();
        $this->assertSame(OrganizationStatus::Pending, $provider->organization->status);
    }

    public function test_staff_without_provider_manage_cannot_create_a_provider(): void
    {
        $manager = $this->makePlatformUser('Operations Manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/providers', [
            'name' => 'Salim Al Habsi',
            'email' => 'salim@fasthaul.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'company_name' => 'Fast Haul',
        ])->assertForbidden();
    }

    public function test_customer_cannot_create_another_customer(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/customers', $this->customerPayload('other@acme.test'))
            ->assertForbidden();
    }

    public function test_platform_admin_must_choose_an_active_customer_for_a_shipment(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $provider = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul',
            'status' => OrganizationStatus::Active,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'customer_organization_id' => $provider->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_organization_id']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/shipments', $this->shipmentPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_organization_id']);
    }

    public function test_customer_cannot_assign_a_shipment_to_another_organization(): void
    {
        $customer = $this->makeCustomer();
        $other = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Other Trading',
            'status' => OrganizationStatus::Active,
        ]);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'customer_organization_id' => $other->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_organization_id']);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', $this->shipmentPayload())
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $customer->organization_id);
    }

    public function test_provider_cannot_create_a_shipment(): void
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
        ]);
        $provider->assignRole('Provider Admin');

        $this->actingAs($provider, 'sanctum')->postJson('/api/v1/shipments', $this->shipmentPayload())
            ->assertForbidden();
    }

    public function test_platform_admin_can_update_a_draft_shipment_only(): void
    {
        $admin = $this->makePlatformUser(StaffRoles::SUPER_ADMIN);
        $customer = $this->makeCustomer();
        $payload = [
            'customer_organization_id' => $customer->organization_id,
            'cargo_type' => 'Cement',
            'weight_tons' => 18,
            'pickup_city' => 'Sohar',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
        ];

        $draft = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/shipments', [...$payload, 'publish' => false])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/shipments/{$draft}", [
            'cargo_type' => 'Steel',
            'weight_tons' => 12,
            'pickup_city' => 'Sohar',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDays(2)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.cargo_type', 'Steel')
            ->assertJsonPath('data.status', 'draft');

        $published = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/shipments', [...$payload, 'publish' => true])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/shipments/{$published}", [
            'cargo_type' => 'Steel',
            'weight_tons' => 12,
            'pickup_city' => 'Sohar',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDays(2)->toDateString(),
        ])->assertForbidden();
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

    /**
     * @return array<string, string>
     */
    private function customerPayload(string $email = 'huda@acme.test'): array
    {
        return [
            'name' => 'Huda Al Hinai',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'company',
            'company_name' => 'Huda Trading',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentPayload(): array
    {
        return [
            'cargo_type' => 'Sand',
            'weight_tons' => 12,
            'pickup_city' => 'Sohar',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
        ];
    }
}
