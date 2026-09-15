<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DriverStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Enums\TruckStatus;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_new_shipments_default_to_prepaid_terms(): void
    {
        [$customer] = $this->makeCustomerAndProvider();

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', $this->shipmentPayload())
            ->assertCreated()
            ->json('data');

        $this->assertSame('on_award', $shipment['payment_terms']['billing_trigger']);
        $this->assertSame(0, $shipment['payment_terms']['due_days']);
        $this->assertSame('job', $shipment['payment_terms']['billing_unit']);
        $this->assertTrue($shipment['payment_terms']['prepaid']);
        $this->assertFalse($shipment['payment_terms']['per_trip']);
    }

    public function test_deferred_terms_apply_immediately_and_do_not_change_existing_shipments(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();

        $published = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'publish' => true,
        ])->json('data');

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_delivery',
                'due_days' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.billing_trigger', 'on_delivery')
            ->assertJsonPath('data.due_days', 15)
            ->assertJsonPath('data.pending_status', null);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/shipments/'.$published['id'])
            ->assertOk()
            ->assertJsonPath('data.payment_terms.prepaid', true);

        $next = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'publish' => true,
        ])->json('data');

        $this->assertSame('on_delivery', $next['payment_terms']['billing_trigger']);
        $this->assertSame(15, $next['payment_terms']['due_days']);
        $this->assertSame('job', $next['payment_terms']['billing_unit']);
        $this->assertFalse($next['payment_terms']['prepaid']);

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/shipments/'.$next['id'])
            ->assertOk()
            ->assertJsonPath('data.payment_terms.due_days', 15);
    }

    public function test_shipment_can_set_payment_terms_independently_of_org_default(): void
    {
        [$customer] = $this->makeCustomerAndProvider();

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_award',
                'due_days' => 0,
            ])
            ->assertOk();

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'publish' => true,
            'billing_trigger' => 'on_delivery',
            'due_days' => 30,
            'billing_unit' => 'trip',
        ])->assertCreated()->json('data');

        $this->assertSame('on_delivery', $shipment['payment_terms']['billing_trigger']);
        $this->assertSame(30, $shipment['payment_terms']['due_days']);
        $this->assertSame('trip', $shipment['payment_terms']['billing_unit']);
        $this->assertTrue($shipment['payment_terms']['per_trip']);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/payment-contract')
            ->assertOk()
            ->assertJsonPath('data.billing_trigger', 'on_award');
    }

    public function test_shipment_can_use_a_custom_due_date_offset(): void
    {
        [$customer] = $this->makeCustomerAndProvider();

        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'publish' => true,
            'billing_trigger' => 'on_delivery',
            'due_days' => 21,
            'billing_unit' => 'job',
        ])->assertCreated()->json('data');

        $this->assertSame(21, $shipment['payment_terms']['due_days']);
        $this->assertFalse($shipment['payment_terms']['prepaid']);
    }

    public function test_prepaid_terms_apply_immediately(): void
    {
        [$customer] = $this->makeCustomerAndProvider();

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_delivery',
                'due_days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.billing_trigger', 'on_delivery');

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_award',
                'due_days' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.billing_trigger', 'on_award')
            ->assertJsonPath('data.pending_status', null);
    }

    public function test_deferred_acceptance_creates_job_without_collecting_payment(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->approveDeferred($customer, 0);

        $quotationId = $this->createPublishedQuotation($customer, $provider, 500);

        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk();

        $this->assertSame('pending_dispatch', $accept->json('data.status'));
        $this->assertCount(1, $accept->json('data.trips'));
        $this->assertDatabaseMissing('payments', [
            'quotation_id' => $quotationId,
            'status' => PaymentStatus::Completed->value,
        ]);

        $jobId = $accept->json('data.id');
        $invoice = Invoice::query()
            ->where('transport_job_id', $jobId)
            ->where('type', InvoiceType::Customer)
            ->first();

        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNull($invoice->due_at);
        $this->assertNull(Wallet::query()->where('organization_id', $provider->organization_id)->first());

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/pay')
            ->assertUnprocessable();

        $this->completeJob($provider, $driver, $truck, $accept->json('data.trips.0.id'));

        $invoice->refresh();
        $this->assertNotNull($invoice->due_at);
        $this->assertTrue($invoice->isPayable());

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/pay', ['payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.requires_checkout', false);

        $wallet = Wallet::query()->where('organization_id', $provider->organization_id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(0.0, (float) $wallet->pending_balance);
        $this->assertEquals(450.0, (float) $wallet->available_balance);
        $this->assertDatabaseHas('payments', [
            'quotation_id' => $quotationId,
            'status' => PaymentStatus::Completed->value,
        ]);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_net_days_invoice_is_due_after_the_configured_delay(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->approveDeferred($customer, 15);

        $quotationId = $this->createPublishedQuotation($customer, $provider, 200);
        $job = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->json('data');

        $this->completeJob($provider, $driver, $truck, $job['trips'][0]['id']);

        $invoice = Invoice::query()
            ->where('transport_job_id', $job['id'])
            ->where('type', InvoiceType::Customer)
            ->first();

        $this->assertNotNull($invoice?->due_at);
        $this->assertTrue($invoice->due_at->isSameDay(now()->addDays(15)));
    }

    public function test_per_trip_invoices_open_after_each_shipment(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->approveDeferred($customer, 0, 'trip');

        $quotationId = $this->createPublishedQuotation($customer, $provider, 500, trips: 2);
        $job = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $job['trips']);
        $invoices = Invoice::query()
            ->where('transport_job_id', $job['id'])
            ->where('type', InvoiceType::Customer)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $invoices);
        $this->assertEquals(250.0, (float) $invoices[0]->amount);
        $this->assertEquals(250.0, (float) $invoices[1]->amount);
        $this->assertNull($invoices[0]->due_at);
        $this->assertNull($invoices[1]->due_at);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoices[0]->id.'/pay')
            ->assertUnprocessable();

        $this->completeJob($provider, $driver, $truck, $job['trips'][0]['id']);

        $invoices[0]->refresh();
        $invoices[1]->refresh();
        $this->assertNotNull($invoices[0]->due_at);
        $this->assertTrue($invoices[0]->isPayable());
        $this->assertNull($invoices[1]->due_at);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoices[0]->id.'/pay', ['payment_method' => 'cash'])
            ->assertOk();

        $wallet = Wallet::query()->where('organization_id', $provider->organization_id)->first();
        $this->assertEquals(0.0, (float) $wallet->pending_balance);
        $this->assertEquals(225.0, (float) $wallet->available_balance);

        $this->completeJob($provider, $driver, $truck, $job['trips'][1]['id']);
        $invoices[1]->refresh();
        $this->assertTrue($invoices[1]->isPayable());

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoices[1]->id.'/pay', ['payment_method' => 'cash'])
            ->assertOk();

        $wallet->refresh();
        $this->assertEquals(450.0, (float) $wallet->available_balance);
        $this->assertSame(InvoiceStatus::Paid, $invoices[0]->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoices[1]->fresh()->status);
    }

    public function test_customer_cannot_approve_own_credit_terms(): void
    {
        [$customer] = $this->makeCustomerAndProvider();

        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_delivery',
                'due_days' => 15,
            ])
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract/approve')
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentPayload(): array
    {
        return [
            'cargo_type' => 'Cement',
            'weight_tons' => 20,
            'quantity' => 20,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->addDay()->toDateString(),
        ];
    }

    private function approveDeferred(User $customer, int $dueDays, string $billingUnit = 'job'): void
    {
        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_delivery',
                'due_days' => $dueDays,
                'billing_unit' => $billingUnit,
            ])
            ->assertOk()
            ->assertJsonPath('data.billing_trigger', 'on_delivery');
    }

    private function createPublishedQuotation(User $customer, User $provider, float $totalPrice, int $trips = 1): int
    {
        $shipment = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/shipments', [
            ...$this->shipmentPayload(),
            'publish' => true,
        ])->json('data');

        return $this->actingAs($provider, 'sanctum')->postJson('/api/v1/shipments/'.$shipment['id'].'/quotations', [
            'total_price' => $totalPrice,
            'truck_count' => $trips,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => $trips,
            'quantity_per_trip' => 20 / $trips,
            'duration_days' => 2,
        ])->json('data.id');
    }

    private function completeJob(User $provider, User $driver, Truck $truck, int $tripId): void
    {
        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
        ])->assertOk();

        foreach ([
            TripStatus::ArrivedAtPickup,
            TripStatus::Loaded,
            TripStatus::InTransit,
            TripStatus::Arrived,
            TripStatus::Delivered,
            TripStatus::Completed,
        ] as $status) {
            $this->actingAs($driver, 'sanctum')
                ->postJson("/api/v1/trips/{$tripId}/status", ['status' => $status->value])
                ->assertOk();
        }
    }

    /**
     * @return array{0: User, 1: User, 2?: User, 3?: Truck}
     */
    private function makeCustomerAndProvider(bool $withDriver = false): array
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

        if (! $withDriver) {
            return [$customer, $provider];
        }

        $driver = User::factory()->create([
            'user_type' => UserType::Driver,
            'organization_id' => $providerOrg->id,
        ]);
        $driver->assignRole('Driver');
        DriverProfile::query()->create([
            'user_id' => $driver->id,
            'organization_id' => $providerOrg->id,
            'status' => DriverStatus::Available,
        ]);
        $truck = Truck::query()->create([
            'organization_id' => $providerOrg->id,
            'plate_number' => 'T-300',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
            'status' => TruckStatus::Available,
        ]);

        return [$customer, $provider, $driver, $truck];
    }
}
