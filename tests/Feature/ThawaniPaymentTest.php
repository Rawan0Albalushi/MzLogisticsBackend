<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThawaniPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'mz.sandbox_payments' => false,
            'app.url' => 'http://localhost:8000',
            'payment.frontend_url' => 'http://customer.test',
            'payment.gateways.thawani.public_key' => 'HGvTMLDssJghr9tlN9gr4DVYt0qyBy',
            'payment.gateways.thawani.secret_key' => 'rRQ26GcsZzoEhbrP2HZvLYDbn9C9et',
            'payment.gateways.thawani.mode' => 'test',
        ]);
    }

    public function test_accepting_a_quotation_creates_a_thawani_checkout_session(): void
    {
        Http::fake([
            'uatcheckout.thawani.om/api/v1/checkout/session' => Http::response([
                'data' => ['session_id' => 'checkout_session_mz_test'],
            ], 200),
        ]);

        [$customer, $quotationId] = $this->publishedQuotation();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/accept", ['payment_method' => 'card'])
            ->assertOk();

        $this->assertTrue($response->json('data.requires_checkout'));
        $this->assertSame(
            'https://uatcheckout.thawani.om/pay/checkout_session_mz_test?key=HGvTMLDssJghr9tlN9gr4DVYt0qyBy',
            $response->json('data.payment_link')
        );
        $this->assertDatabaseHas('payments', [
            'quotation_id' => $quotationId,
            'status' => 'processing',
            'gateway' => 'thawani',
            'gateway_reference' => 'checkout_session_mz_test',
        ]);
        $this->assertDatabaseMissing('transport_jobs', [
            'quotation_id' => $quotationId,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://uatcheckout.thawani.om/api/v1/checkout/session'
                && $request->hasHeader('thawani-api-key', 'rRQ26GcsZzoEhbrP2HZvLYDbn9C9et')
                && $request['products'][0]['unit_amount'] === 500000;
        });
    }

    public function test_cash_acceptance_creates_the_job_without_thawani(): void
    {
        Http::fake();

        [$customer, $quotationId] = $this->publishedQuotation();

        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/accept", ['payment_method' => 'cash'])
            ->assertOk();

        $this->assertSame('pending_dispatch', $accept->json('data.status'));
        $this->assertDatabaseHas('payments', [
            'quotation_id' => $quotationId,
            'method' => 'cash',
            'gateway' => 'cash',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('transport_jobs', [
            'quotation_id' => $quotationId,
        ]);
        Http::assertNothingSent();
    }

    public function test_thawani_success_callback_verifies_payment_and_creates_the_job(): void
    {
        Http::fake([
            'uatcheckout.thawani.om/api/v1/checkout/session' => Http::response([
                'data' => ['session_id' => 'checkout_session_mz_paid'],
            ], 200),
            'uatcheckout.thawani.om/api/v1/checkout/session/checkout_session_mz_paid' => Http::response([
                'data' => ['payment_status' => 'paid', 'session_id' => 'checkout_session_mz_paid'],
            ], 200),
        ]);

        [$customer, $quotationId] = $this->publishedQuotation();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/accept")
            ->assertOk();

        $payment = Payment::query()->where('quotation_id', $quotationId)->firstOrFail();

        $this->get('/api/v1/payments/success?payment_id='.$payment->id)
            ->assertOk()
            ->assertSee('mzlogistics://', false);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'completed',
            'gateway' => 'thawani',
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('transport_jobs', [
            'quotation_id' => $quotationId,
            'status' => 'pending_dispatch',
        ]);
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function publishedQuotation(): array
    {
        $customerOrg = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $customerOrg->id,
        ]);
        $customer->assignRole('Company Admin');

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

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            'cargo_type' => 'Cement',
            'weight_tons' => 20,
            'quantity' => 20,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
            'publish' => true,
        ])->assertCreated();

        $quotation = $this->actingAs($provider, 'sanctum')->postJson('/api/v1/shipments/'.$shipment->json('data.id').'/quotations', [
            'total_price' => 500,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 2,
            'quantity_per_trip' => 10,
            'duration_days' => 2,
        ])->assertCreated();

        return [$customer, (int) $quotation->json('data.id')];
    }
}
