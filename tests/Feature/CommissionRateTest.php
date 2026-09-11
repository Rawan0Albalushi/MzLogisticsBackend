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

class CommissionRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_organization_resource_exposes_effective_and_default_commission(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $custom = $this->makeProvider('Custom Rate Fleet', 0.08);
        $fallback = $this->makeProvider('Default Rate Fleet');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/organizations/'.$custom->id)
            ->assertOk()
            ->assertJsonPath('data.commission_rate', 0.08)
            ->assertJsonPath('data.effective_commission_rate', 0.08)
            ->assertJsonPath('data.uses_default_commission', false);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/organizations/'.$fallback->id)
            ->assertOk()
            ->assertJsonPath('data.commission_rate', null)
            ->assertJsonPath('data.effective_commission_rate', config('mz.commission_rate'))
            ->assertJsonPath('data.uses_default_commission', true);
    }

    public function test_platform_admin_can_set_and_clear_provider_commission_rate(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);
        $provider = $this->makeProvider('Fast Haul');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/organizations/'.$provider->id.'/commission-rate', [
                'commission_rate' => 0.12,
            ])
            ->assertOk()
            ->assertJsonPath('data.commission_rate', 0.12)
            ->assertJsonPath('data.effective_commission_rate', 0.12)
            ->assertJsonPath('data.uses_default_commission', false);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/organizations/'.$provider->id.'/commission-rate', [
                'commission_rate' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.commission_rate', null)
            ->assertJsonPath('data.effective_commission_rate', config('mz.commission_rate'))
            ->assertJsonPath('data.uses_default_commission', true);
    }

    public function test_provider_cannot_change_own_commission_rate(): void
    {
        $providerOrg = $this->makeProvider('Fast Haul', 0.1);
        $provider = User::factory()->create([
            'user_type' => UserType::Provider,
            'organization_id' => $providerOrg->id,
        ]);
        $provider->assignRole('Provider Admin');

        $this->actingAs($provider, 'sanctum')
            ->patchJson('/api/v1/organizations/'.$providerOrg->id.'/commission-rate', [
                'commission_rate' => 0.01,
            ])
            ->assertForbidden();
    }

    public function test_commission_rate_cannot_be_set_on_a_customer(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $customer = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/organizations/'.$customer->id.'/commission-rate', [
                'commission_rate' => 0.15,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commission_rate']);
    }

    private function makeProvider(string $name, ?float $commissionRate = null): Organization
    {
        return Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => $name,
            'status' => OrganizationStatus::Active,
            'commission_rate' => $commissionRate,
        ]);
    }
}
