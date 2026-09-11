<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_catalog_exposes_active_thawani_and_cash_methods(): void
    {
        $response = $this->getJson('/api/v1/catalog')->assertOk();
        $codes = collect($response->json('data.payment_methods'))->pluck('code')->all();

        $this->assertContains('thawani', $codes);
        $this->assertContains('cash', $codes);
    }

    public function test_admin_can_disable_and_add_a_payment_method(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(StaffRoles::SUPER_ADMIN);
        $thawani = PaymentMethod::query()->where('code', 'thawani')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/payment-methods/'.$thawani->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/payment-methods', [
                'code' => 'office_cash',
                'name' => 'Office cash',
                'name_ar' => 'كاش المكتب',
                'processor' => 'cash',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'office_cash')
            ->assertJsonPath('data.processor', 'cash');

        $catalog = $this->getJson('/api/v1/catalog')->assertOk();
        $codes = collect($catalog->json('data.payment_methods'))->pluck('code')->all();
        $this->assertNotContains('thawani', $codes);
        $this->assertContains('cash', $codes);
        $this->assertContains('office_cash', $codes);
    }

    public function test_system_payment_methods_cannot_be_deleted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(StaffRoles::SUPER_ADMIN);
        $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/payment-methods/'.$cash->id)
            ->assertStatus(422);
    }
}
