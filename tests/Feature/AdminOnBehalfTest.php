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
            ->assertJsonPath('data.account_type', 'individual')
            ->assertJsonPath('data.name', 'Huda Al Hinai');
    }

    public function test_staff_without_customer_manage_cannot_create_a_customer(): void
    {
        $staff = $this->makePlatformUser('Operations Staff');

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/customers', $this->customerPayload())
            ->assertForbidden();
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
            'account_type' => 'individual',
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
