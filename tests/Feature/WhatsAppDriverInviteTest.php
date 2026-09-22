<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppDriverInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_enabled_whatsapp_sender_posts_invite_and_marks_sent(): void
    {
        config([
            'mz.whatsapp.driver_invite_enabled' => true,
            'mz.whatsapp.api_url' => 'https://whatsapp.test/messages',
            'mz.whatsapp.api_token' => 'test-token',
            'mz.whatsapp.from' => 'MZ',
        ]);
        Http::fake([
            'https://whatsapp.test/messages' => Http::response(['ok' => true], 200),
        ]);

        $provider = $this->makeProvider();
        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'WhatsApp Driver',
                'phone' => '99227701',
            ])
            ->assertCreated()
            ->assertJsonPath('data.whatsapp_sent', true);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://whatsapp.test/messages'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['to'] === '96899227701';
        });
    }

    public function test_whatsapp_failure_does_not_fail_driver_creation(): void
    {
        config([
            'mz.whatsapp.driver_invite_enabled' => true,
            'mz.whatsapp.api_url' => 'https://whatsapp.test/messages',
            'mz.whatsapp.api_token' => 'test-token',
        ]);
        Http::fake([
            'https://whatsapp.test/messages' => Http::response(['error' => 'no'], 500),
        ]);

        $provider = $this->makeProvider();
        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Fallback Driver',
                'phone' => '99227702',
            ])
            ->assertCreated()
            ->assertJsonPath('data.whatsapp_sent', false);

        $this->assertNotEmpty(
            $this->actingAs($provider, 'sanctum')
                ->postJson('/api/v1/drivers', [
                    'name' => 'Another',
                    'phone' => '99227703',
                ])
                ->json('data.invite_url')
        );
    }

    private function makeProvider(): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $provider = User::factory()->create([
            'email' => 'provider-wa@example.com',
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }
}
