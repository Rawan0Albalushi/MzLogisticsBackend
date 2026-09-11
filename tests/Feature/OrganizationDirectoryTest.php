<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customers_index_returns_only_customers_with_account_type(): void
    {
        $admin = $this->platformAdmin();

        $individual = $this->makeOrganization('Sara Al Lawati', OrganizationType::Customer, AccountType::Individual);
        $company = $this->makeOrganization('Gulf Materials Trading', OrganizationType::Customer, AccountType::Company);
        $provider = $this->makeOrganization('Oman Haulers', OrganizationType::Provider, AccountType::Company);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/customers');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $items = collect($response->json('data'));
        $ids = $items->pluck('id');

        $this->assertTrue($ids->contains($individual->id));
        $this->assertTrue($ids->contains($company->id));
        $this->assertFalse($ids->contains($provider->id));

        $individualRow = $items->firstWhere('id', $individual->id);
        $companyRow = $items->firstWhere('id', $company->id);

        $this->assertSame('customer', $individualRow['type']);
        $this->assertSame('individual', $individualRow['account_type']);
        $this->assertSame('customer', $companyRow['type']);
        $this->assertSame('company', $companyRow['account_type']);
    }

    public function test_customers_index_can_filter_by_account_type(): void
    {
        $admin = $this->platformAdmin();

        $individual = $this->makeOrganization('Sara Al Lawati', OrganizationType::Customer, AccountType::Individual);
        $company = $this->makeOrganization('Gulf Materials Trading', OrganizationType::Customer, AccountType::Company);
        $this->makeOrganization('Oman Haulers', OrganizationType::Provider, AccountType::Company);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/customers?account_type=individual');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($individual->id));
        $this->assertFalse($ids->contains($company->id));
    }

    public function test_providers_index_excludes_customers(): void
    {
        $admin = $this->platformAdmin();

        $customer = $this->makeOrganization('Gulf Materials Trading', OrganizationType::Customer, AccountType::Company);
        $provider = $this->makeOrganization('Oman Haulers', OrganizationType::Provider, AccountType::Company);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/providers');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($provider->id));
        $this->assertFalse($ids->contains($customer->id));
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return $admin;
    }

    private function makeOrganization(string $name, OrganizationType $type, AccountType $accountType): Organization
    {
        return Organization::query()->create([
            'type' => $type,
            'account_type' => $accountType,
            'name' => $name,
            'status' => OrganizationStatus::Active,
        ]);
    }
}
