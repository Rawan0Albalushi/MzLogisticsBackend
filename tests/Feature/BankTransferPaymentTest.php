<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DriverStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TripStatus;
use App\Enums\TruckStatus;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BankTransferPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        Http::fake();
    }

    public function test_bank_transfer_acceptance_holds_the_job_until_finance_confirms_the_receipt(): void
    {
        [$customer, $quotationId] = $this->publishedQuotation();
        $finance = $this->financeManager();

        $this->actingAs($finance, 'sanctum')
            ->putJson('/api/v1/settings/bank-account', [
                'bank_name' => 'Bank Muscat',
                'account_name' => 'MZ Logistics',
                'account_number' => '0123456789',
                'iban' => 'OM123',
            ])
            ->assertOk();

        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/quotations/{$quotationId}/accept", ['payment_method' => 'bank_transfer'])
            ->assertOk();

        $accept->assertJsonPath('data.awaiting_transfer', true);
        $accept->assertJsonPath('data.requires_checkout', false);
        $accept->assertJsonPath('data.job', null);
        $accept->assertJsonPath('data.bank_account.bank_name', 'Bank Muscat');
        $accept->assertJsonPath('data.payment.status', 'pending');
        $accept->assertJsonPath('data.payment.method', 'bank_transfer');

        $this->assertDatabaseMissing('transport_jobs', [
            'quotation_id' => $quotationId,
        ]);
        Http::assertNothingSent();

        $paymentId = (int) $accept->json('data.payment.id');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/payments/'.$paymentId.'/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseMissing('transport_jobs', [
            'quotation_id' => $quotationId,
        ]);

        $this->actingAs($finance, 'sanctum')
            ->post('/api/v1/payments/'.$paymentId.'/confirm-transfer', [])
            ->assertUnprocessable();

        $this->actingAs($customer, 'sanctum')
            ->post('/api/v1/payments/'.$paymentId.'/confirm-transfer', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertForbidden();

        $staff = User::factory()->create();
        $staff->assignRole('Finance Staff');
        $this->actingAs($staff, 'sanctum')
            ->post('/api/v1/payments/'.$paymentId.'/confirm-transfer', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertForbidden();

        $confirmed = $this->actingAs($finance, 'sanctum')
            ->post('/api/v1/payments/'.$paymentId.'/confirm-transfer', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
                'transfer_reference' => 'TRX-100',
            ])
            ->assertOk();

        $confirmed->assertJsonPath('data.payment.status', 'completed');
        $confirmed->assertJsonPath('data.payment.transfer_reference', 'TRX-100');
        $confirmed->assertJsonPath('data.payment.has_receipt', true);
        $confirmed->assertJsonPath('data.job.status', 'pending_dispatch');

        $payment = Payment::query()->findOrFail($paymentId);
        $this->assertNotNull($payment->receipt_path);
        $this->assertSame($finance->id, $payment->confirmed_by);
        Storage::disk('local')->assertExists($payment->receipt_path);

        $this->actingAs($customer, 'sanctum')
            ->get('/api/v1/payments/'.$paymentId.'/receipt')
            ->assertForbidden();

        $this->actingAs($finance, 'sanctum')
            ->get('/api/v1/payments/'.$paymentId.'/receipt')
            ->assertOk();
    }

    public function test_deferred_invoice_stays_open_until_the_transfer_receipt_is_confirmed(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->approveDeferred($customer);

        $quotationId = $this->createPublishedQuotation($customer, $provider, 500);
        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk();

        $this->completeJob($provider, $driver, $truck, (int) $accept->json('data.trips.0.id'));

        $invoice = Invoice::query()
            ->where('transport_job_id', $accept->json('data.id'))
            ->where('type', InvoiceType::Customer)
            ->firstOrFail();

        $pay = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/pay', ['payment_method' => 'bank_transfer'])
            ->assertOk();

        $pay->assertJsonPath('data.awaiting_transfer', true);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        Http::assertNothingSent();

        $finance = $this->financeManager();
        $this->actingAs($finance, 'sanctum')
            ->post('/api/v1/payments/'.$pay->json('data.payment.id').'/confirm-transfer', [
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
            ])
            ->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_finance_can_record_a_bank_transfer_on_an_issued_invoice(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->approveDeferred($customer);

        $quotationId = $this->createPublishedQuotation($customer, $provider, 500);
        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk();

        $this->completeJob($provider, $driver, $truck, (int) $accept->json('data.trips.0.id'));

        $invoice = Invoice::query()
            ->where('transport_job_id', $accept->json('data.id'))
            ->where('type', InvoiceType::Customer)
            ->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->post('/api/v1/invoices/'.$invoice->id.'/record-transfer', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertForbidden();

        $finance = $this->financeManager();
        $recorded = $this->actingAs($finance, 'sanctum')
            ->post('/api/v1/invoices/'.$invoice->id.'/record-transfer', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
                'transfer_reference' => 'OM-88421',
            ])
            ->assertOk();

        $recorded->assertJsonPath('data.payment.method', 'bank_transfer');
        $recorded->assertJsonPath('data.payment.status', 'completed');
        $recorded->assertJsonPath('data.payment.has_receipt', true);
        $recorded->assertJsonPath('data.payment.transfer_reference', 'OM-88421');

        $payment = Payment::query()->findOrFail($recorded->json('data.payment.id'));
        $this->assertSame($finance->id, $payment->confirmed_by);
        $this->assertSame($customer->organization_id, $payment->payer_organization_id);
        $this->assertSame($invoice->id, $payment->invoice_id);
        Storage::disk('local')->assertExists($payment->receipt_path);
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $this->actingAs($finance, 'sanctum')
            ->post('/api/v1/invoices/'.$invoice->id.'/record-transfer', [
                'receipt' => UploadedFile::fake()->image('again.jpg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');
    }

    public function test_finance_cannot_record_a_transfer_before_the_invoice_is_payable(): void
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();
        $this->approveDeferred($customer);

        $quotationId = $this->createPublishedQuotation($customer, $provider, 500);
        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk();

        $invoice = Invoice::query()
            ->where('transport_job_id', $accept->json('data.id'))
            ->where('type', InvoiceType::Customer)
            ->firstOrFail();

        $this->actingAs($this->financeManager(), 'sanctum')
            ->post('/api/v1/invoices/'.$invoice->id.'/record-transfer', [
                'receipt' => UploadedFile::fake()->image('receipt.jpg'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertDatabaseMissing('payments', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_recording_a_transfer_confirms_a_transfer_the_customer_already_started(): void
    {
        [$customer, $provider, $driver, $truck] = $this->makeCustomerAndProvider(true);
        $this->approveDeferred($customer);

        $quotationId = $this->createPublishedQuotation($customer, $provider, 500);
        $accept = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/quotations/'.$quotationId.'/accept')
            ->assertOk();

        $this->completeJob($provider, $driver, $truck, (int) $accept->json('data.trips.0.id'));

        $invoice = Invoice::query()
            ->where('transport_job_id', $accept->json('data.id'))
            ->where('type', InvoiceType::Customer)
            ->firstOrFail();

        $pay = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/invoices/'.$invoice->id.'/pay', ['payment_method' => 'bank_transfer'])
            ->assertOk();

        $paymentId = (int) $pay->json('data.payment.id');
        $finance = $this->financeManager();

        $this->actingAs($finance, 'sanctum')
            ->post('/api/v1/invoices/'.$invoice->id.'/record-transfer', [
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
                'transfer_reference' => 'TRX-19',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment.id', $paymentId)
            ->assertJsonPath('data.payment.transfer_reference', 'TRX-19');

        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame($finance->id, Payment::query()->findOrFail($paymentId)->confirmed_by);
    }

    private function financeManager(): User
    {
        $finance = User::factory()->create();
        $finance->assignRole('Finance Manager');

        return $finance;
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function publishedQuotation(): array
    {
        [$customer, $provider] = $this->makeCustomerAndProvider();

        return [$customer, $this->createPublishedQuotation($customer, $provider, 500)];
    }

    private function approveDeferred(User $customer): void
    {
        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/organizations/'.$customer->organization_id.'/payment-contract', [
                'billing_trigger' => 'on_delivery',
                'due_days' => 0,
                'billing_unit' => 'job',
            ])
            ->assertOk();
    }

    private function createPublishedQuotation(User $customer, User $provider, float $totalPrice): int
    {
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

        return (int) $this->actingAs($provider, 'sanctum')->postJson('/api/v1/shipments/'.$shipment->json('data.id').'/quotations', [
            'total_price' => $totalPrice,
            'truck_count' => 1,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 2,
            'transport_start_date' => now()->addDay()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    private function completeJob(User $provider, User $driver, Truck $truck, int $tripId): void
    {
        $this->actingAs($provider, 'sanctum')->postJson("/api/v1/trips/{$tripId}/assign", [
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
            'departure_time' => '15:00',
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
