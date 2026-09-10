<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_can_register_and_login(): void
    {
        $register = $this->postJson('/api/v1/auth/register/customer', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'individual',
            'phone' => '+968 99001122',
        ]);

        $register->assertCreated()->assertJsonPath('success', true);
        $this->assertNotEmpty($register->json('data.token'));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sara@example.com',
            'password' => 'Password123!',
        ]);

        $login->assertOk()->assertJsonPath('data.user.user_type', 'customer');
    }

    public function test_invalid_login_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
}
