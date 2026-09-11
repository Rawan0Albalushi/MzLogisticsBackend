<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\TruckType;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TruckTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_catalog_exposes_active_platform_truck_types(): void
    {
        $codes = collect($this->getJson('/api/v1/catalog')->assertOk()->json('data.truck_types'))->pluck('code')->all();

        $this->assertContains('flatbed', $codes);
        $this->assertContains('box', $codes);
        $this->assertContains('reefer', $codes);
    }

    public function test_admin_can_add_update_and_disable_a_platform_truck_type(): void
    {
        $admin = $this->makeAdmin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/truck-types', [
                'code' => 'heavy_haul',
                'name' => 'Heavy haul',
                'name_ar' => 'نقل ثقيل',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'heavy_haul')
            ->assertJsonPath('data.is_platform', true)
            ->assertJsonPath('data.can_manage', true);

        $id = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/truck-types/'.$id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $catalogCodes = collect($this->getJson('/api/v1/catalog')->assertOk()->json('data.truck_types'))->pluck('code')->all();
        $this->assertNotContains('heavy_haul', $catalogCodes);
        $this->assertContains('flatbed', $catalogCodes);
    }

    public function test_system_truck_types_cannot_be_deleted(): void
    {
        $admin = $this->makeAdmin();
        $flatbed = TruckType::query()->where('code', 'flatbed')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/truck-types/'.$flatbed->id)
            ->assertStatus(422);
    }

    public function test_provider_can_add_a_company_truck_type_and_see_it_in_catalog(): void
    {
        $provider = $this->makeProvider();

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/truck-types', [
                'code' => 'sideloader',
                'name' => 'Sideloader',
                'name_ar' => 'سايد لودر',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'sideloader')
            ->assertJsonPath('data.is_platform', false)
            ->assertJsonPath('data.organization_id', $provider->organization_id);

        $providerCodes = collect($this->actingAs($provider, 'sanctum')->getJson('/api/v1/catalog')->json('data.truck_types'))->pluck('code')->all();
        $this->assertContains('sideloader', $providerCodes);
        $this->assertContains('flatbed', $providerCodes);

        $this->app['auth']->forgetGuards();
        $guestCodes = collect($this->getJson('/api/v1/catalog')->json('data.truck_types'))->pluck('code')->all();
        $this->assertNotContains('sideloader', $guestCodes);
    }

    public function test_provider_cannot_update_platform_truck_types(): void
    {
        $provider = $this->makeProvider();
        $flatbed = TruckType::query()->where('code', 'flatbed')->firstOrFail();

        $this->actingAs($provider, 'sanctum')
            ->patchJson('/api/v1/truck-types/'.$flatbed->id, ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_provider_can_quote_with_a_company_truck_type(): void
    {
        $provider = $this->makeProvider();
        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/truck-types', [
                'code' => 'sideloader',
                'name' => 'Sideloader',
                'name_ar' => 'سايد لودر',
            ])
            ->assertCreated();

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/trucks', [
                'plate_number' => 'H 9001',
                'type' => 'sideloader',
                'capacity_tons' => 40,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'sideloader');
    }

    public function test_provider_cannot_use_another_company_truck_type(): void
    {
        $owner = $this->makeProvider('Alpha Haul');
        $other = $this->makeProvider('Beta Haul', 'beta.provider@example.com');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/truck-types', [
                'code' => 'alpha_only',
                'name' => 'Alpha only',
                'name_ar' => 'خاص ألفا',
            ])
            ->assertCreated();

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/trucks', [
                'plate_number' => 'H 9002',
                'type' => 'alpha_only',
                'capacity_tons' => 20,
            ])
            ->assertStatus(422);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return $admin;
    }

    private function makeProvider(string $name = 'Fast Haul', string $email = 'provider@example.com'): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => $name,
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'email' => $email,
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }
}
