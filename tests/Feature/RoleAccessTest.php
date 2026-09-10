<?php

namespace Tests\Feature;

use App\Models\User;
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
        $admin = User::factory()->create();
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/roles');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'roles' => [
                        ['name', 'users_count', 'permissions'],
                    ],
                    'permissions',
                    'platform_roles',
                ],
            ]);

        $this->assertContains(StaffRoles::SUPER_ADMIN, $response->json('data.platform_roles'));
        $this->assertTrue(
            collect($response->json('data.roles'))->contains(fn (array $role) => $role['name'] === StaffRoles::SUPER_ADMIN)
        );
    }
}
