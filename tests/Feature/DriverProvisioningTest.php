<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\DriverActivationToken;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_provider_can_create_driver_with_phone_and_invite(): void
    {
        $provider = $this->makeProvider();

        $response = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Khalid Al Mamari',
                'phone' => '99225501',
                'license_number' => 'OM-DL-111',
            ])
            ->assertCreated()
            ->assertJsonPath('data.driver.name', 'Khalid Al Mamari')
            ->assertJsonPath('data.driver.phone', '+96899225501')
            ->assertJsonPath('data.driver.email', null)
            ->assertJsonPath('data.driver.must_set_password', true)
            ->assertJsonPath('data.whatsapp_sent', false);

        $this->assertNotEmpty($response->json('data.invite_url'));
        $this->assertDatabaseHas('users', [
            'phone' => '+96899225501',
            'must_set_password' => true,
            'email' => '96899225501@drivers.mz.local',
        ]);
        $this->assertDatabaseCount('driver_activation_tokens', 1);
    }

    public function test_pending_driver_cannot_login_until_activated(): void
    {
        $provider = $this->makeProvider();
        $created = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Sara Driver',
                'phone' => '+968 9922 5502',
            ])
            ->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'login' => '99225502',
            'password' => 'Password123!',
        ])->assertStatus(422);

        $token = DriverActivationToken::query()->firstOrFail();
        $plain = $this->plainTokenFromUrl($created->json('data.invite_url'));

        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        $activated = $this->postJson('/api/v1/auth/driver/activate', [
            'token' => $plain,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertOk();

        $this->assertNotEmpty($activated->json('data.token'));
        $this->assertFalse($activated->json('data.user.must_set_password'));

        $this->postJson('/api/v1/auth/login', [
            'login' => '99225502',
            'password' => 'Password123!',
        ])->assertOk()->assertJsonPath('data.user.user_type', 'driver');
    }

    public function test_duplicate_driver_phone_is_rejected(): void
    {
        $provider = $this->makeProvider();
        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'First',
                'phone' => '99225503',
            ])
            ->assertCreated();

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Second',
                'phone' => '+96899225503',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_existing_email_login_still_works(): void
    {
        $this->postJson('/api/v1/auth/register/customer', [
            'name' => 'Sara Al Lawati',
            'email' => 'sara@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'account_type' => 'individual',
            'phone' => '+968 99001122',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'sara@example.com',
            'password' => 'Password123!',
        ])->assertOk()->assertJsonPath('data.user.user_type', 'customer');
    }

    public function test_provider_can_update_driver_details(): void
    {
        $provider = $this->makeProvider();
        $created = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Khalid Al Mamari',
                'phone' => '99225511',
                'license_number' => 'OM-DL-111',
            ])
            ->assertCreated();

        $driverId = $created->json('data.driver.id');

        $this->actingAs($provider, 'sanctum')
            ->putJson("/api/v1/drivers/{$driverId}", [
                'name' => 'Khalid Updated',
                'phone' => '99225512',
                'email' => 'khalid.driver@example.com',
                'license_number' => 'OM-DL-222',
                'license_expires_at' => '2027-04-01',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Khalid Updated')
            ->assertJsonPath('data.phone', '+96899225512')
            ->assertJsonPath('data.email', 'khalid.driver@example.com')
            ->assertJsonPath('data.driver_profile.license_number', 'OM-DL-222')
            ->assertJsonPath('data.driver_profile.status', 'inactive');
    }

    public function test_driver_update_rejects_a_phone_used_by_another_driver(): void
    {
        $provider = $this->makeProvider();
        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'First',
                'phone' => '99225513',
            ])
            ->assertCreated();

        $second = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Second',
                'phone' => '99225514',
            ])
            ->assertCreated();

        $this->actingAs($provider, 'sanctum')
            ->putJson('/api/v1/drivers/'.$second->json('data.driver.id'), [
                'name' => 'Second',
                'phone' => '99225513',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_provider_cannot_update_another_companys_driver(): void
    {
        $owner = $this->makeProvider();
        $created = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Owned Driver',
                'phone' => '99225515',
            ])
            ->assertCreated();

        $other = $this->makeProvider('other@example.com', 'Other Haul');

        $this->actingAs($other, 'sanctum')
            ->putJson('/api/v1/drivers/'.$created->json('data.driver.id'), [
                'name' => 'Taken Over',
                'phone' => '99225516',
            ])
            ->assertForbidden();
    }

    public function test_provider_can_resend_pending_invite(): void
    {
        $provider = $this->makeProvider();
        $created = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Resend Me',
                'phone' => '99225504',
            ])
            ->assertCreated();

        $driverId = $created->json('data.driver.id');
        $firstUrl = $created->json('data.invite_url');

        $resent = $this->actingAs($provider, 'sanctum')
            ->postJson("/api/v1/drivers/{$driverId}/resend-invite")
            ->assertOk();

        $this->assertNotSame($firstUrl, $resent->json('data.invite_url'));
        $this->assertSame(1, DriverActivationToken::query()->whereNull('used_at')->count());
    }

    private function makeProvider(string $email = 'provider@example.com', string $name = 'Fast Haul'): User
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

    private function plainTokenFromUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY) ?: '';
        parse_str($query, $parts);

        return (string) ($parts['token'] ?? '');
    }
}
