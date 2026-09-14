<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Permissions;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_platform_admin_can_list_roles_while_authenticated_via_sanctum(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/roles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'roles' => [
                        ['name', 'display_name', 'users_count', 'permissions', 'is_system', 'can_manage', 'can_delete'],
                    ],
                    'permissions',
                    'platform_roles',
                    'assignable_roles',
                ],
            ]);

        $this->assertContains(StaffRoles::SUPER_ADMIN, $response->json('data.platform_roles'));
        $this->assertTrue(
            collect($response->json('data.roles'))->contains(fn (array $role) => $role['name'] === StaffRoles::SUPER_ADMIN)
        );
    }

    public function test_admin_can_create_and_assign_a_custom_role(): void
    {
        $admin = $this->makeAdmin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'Night Operations',
                'permissions' => [
                    Permissions::DASHBOARD_VIEW,
                    Permissions::SHIPMENTS_VIEW,
                    Permissions::JOBS_VIEW,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Night Operations')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.can_delete', true);

        $roleName = $created->json('data.name');
        $this->assertStringStartsWith('custom.platform.', $roleName);

        $staff = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Night Desk',
                'email' => 'night@mzlogistics.om',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'role' => $roleName,
            ])
            ->assertCreated();

        $this->assertContains($roleName, $staff->json('data.roles'));

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/roles/'.$roleName)
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/users/'.$staff->json('data.id'), [
                'role' => 'Operations Staff',
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/roles/'.$roleName)
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['name' => $roleName]);
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/roles/'.rawurlencode(StaffRoles::SUPER_ADMIN))
            ->assertStatus(422);
    }

    public function test_provider_can_create_a_company_role_with_selected_permissions(): void
    {
        $provider = $this->makeProvider();

        $created = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'Warehouse Supervisor',
                'permissions' => [
                    Permissions::DASHBOARD_VIEW,
                    Permissions::FLEET_VIEW,
                    Permissions::TRIPS_VIEW,
                    Permissions::TRIPS_ASSIGN,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Warehouse Supervisor')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.organization_id', $provider->organization_id);

        $this->assertContains(Permissions::ROLES_MANAGE, $provider->getAllPermissions()->pluck('name'));
        $this->assertStringStartsWith('custom.provider.'.$provider->organization_id.'.', $created->json('data.name'));
    }

    public function test_provider_cannot_edit_system_role_permissions(): void
    {
        $provider = $this->makeProvider();

        $this->actingAs($provider, 'sanctum')
            ->patchJson('/api/v1/roles/Dispatcher', [
                'permissions' => [Permissions::DASHBOARD_VIEW],
            ])
            ->assertForbidden();
    }

    public function test_provider_cannot_grant_platform_only_permissions(): void
    {
        $provider = $this->makeProvider();

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'Illegal Access',
                'permissions' => [
                    Permissions::CUSTOMERS_MANAGE,
                    Permissions::PROVIDERS_VERIFY,
                ],
            ])
            ->assertStatus(422);
    }

    public function test_provider_cannot_see_another_company_custom_role(): void
    {
        $provider = $this->makeProvider();
        $other = $this->makeProvider('Other Haul', 'other-provider@example.com');

        $created = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'Night Dispatcher',
                'permissions' => [Permissions::DASHBOARD_VIEW, Permissions::TRIPS_VIEW],
            ])
            ->assertCreated();

        $names = collect($this->actingAs($other, 'sanctum')->getJson('/api/v1/roles')->json('data.roles'))
            ->pluck('name');

        $this->assertFalse($names->contains($created->json('data.name')));
        $this->assertTrue($names->contains('Provider Admin'));
    }

    public function test_staff_without_roles_manage_cannot_create_roles(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole('Finance Manager');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => 'Hidden Role',
                'permissions' => [Permissions::DASHBOARD_VIEW],
            ])
            ->assertForbidden();
    }

    public function test_reserved_system_role_names_cannot_be_reused(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/roles', [
                'name' => StaffRoles::SUPER_ADMIN,
                'permissions' => [Permissions::DASHBOARD_VIEW],
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
        $provider->assignRole(StaffRoles::PROVIDER_ADMIN);

        return $provider;
    }
}
