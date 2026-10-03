<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DriverPayableStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Enums\UserType;
use App\Models\DriverPayable;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceStatementTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_marketplace_job_earns_commission_and_refunds_reduce_it(): void
    {
        [$customer, $admin, $customerOrg, $providerOrg] = $this->actors();
        $job = $this->makeJob($customer, $customerOrg, $providerOrg);

        $this->payment($job, $customerOrg, 100, 10, 90, PaymentStatus::Completed);
        $this->payment($job, $customerOrg, 20, 2, 18, PaymentStatus::Refunded);

        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/finance/statement')->assertForbidden();

        $statement = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/finance/statement')->assertOk();
        $statement->assertJsonPath('data.summary.collected', 80);
        $statement->assertJsonPath('data.summary.platform_revenue', 8);
        $statement->assertJsonPath('data.summary.provider_share', 72);
        $statement->assertJsonPath('data.summary.driver_expense', 0);
        $statement->assertJsonPath('data.summary.net_profit', 8);
        $statement->assertJsonPath('data.jobs.0.execution', 'marketplace');
        $statement->assertJsonPath('data.jobs.0.reference', $job->reference);

        $detail = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/jobs/'.$job->id)->assertOk();
        $detail->assertJsonPath('data.platform_statement.platform_revenue', 8);
        $detail->assertJsonPath('data.platform_statement.provider_share', 72);
        $detail->assertJsonPath('data.platform_statement.net_profit', 8);
        $this->assertArrayNotHasKey(
            'platform_statement',
            $this->actingAs($customer, 'sanctum')->getJson('/api/v1/jobs/'.$job->id)->json('data'),
        );
    }

    public function test_platform_fleet_job_nets_collected_customer_amount_against_paid_driver_wages(): void
    {
        [, $admin, $customerOrg] = $this->actors();
        $platform = Organization::platform();
        $customer = User::query()->where('organization_id', $customerOrg->id)->firstOrFail();
        $job = $this->makeJob($customer, $customerOrg, $platform);
        $driver = User::factory()->create(['user_type' => UserType::Driver]);

        $this->payment($job, $customerOrg, 150, 150, 0, PaymentStatus::Completed);
        $this->driverPayable($job, $driver, 15, DriverPayableStatus::Paid);
        $this->driverPayable($job, $driver, 7, DriverPayableStatus::Pending);
        $cancelled = $this->driverPayable($job, $driver, 9, DriverPayableStatus::Paid);
        $cancelled->trip()->update(['status' => TripStatus::Cancelled->value]);

        $statement = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/finance/statement')->assertOk();
        $statement->assertJsonPath('data.summary.collected', 150);
        $statement->assertJsonPath('data.summary.platform_revenue', 150);
        $statement->assertJsonPath('data.summary.provider_share', 0);
        $statement->assertJsonPath('data.summary.driver_expense', 15);
        $statement->assertJsonPath('data.summary.driver_outstanding', 7);
        $statement->assertJsonPath('data.summary.net_profit', 135);
        $statement->assertJsonPath('data.jobs.0.execution', 'fleet');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/jobs/'.$job->id)
            ->assertOk()
            ->assertJsonPath('data.platform_statement.net_profit', 135)
            ->assertJsonPath('data.platform_statement.driver_expense', 15)
            ->assertJsonPath('data.platform_statement.driver_outstanding', 7);
    }

    public function test_incomplete_payment_is_excluded_and_unpaid_invoice_stays_outstanding(): void
    {
        [, $admin, $customerOrg, $providerOrg] = $this->actors();
        $customer = User::query()->where('organization_id', $customerOrg->id)->firstOrFail();
        $open = $this->makeJob($customer, $customerOrg, $providerOrg, 'OPEN');
        $settled = $this->makeJob($customer, $customerOrg, $providerOrg, 'PAID');

        $this->payment($open, $customerOrg, 40, 4, 36, PaymentStatus::Pending);
        Invoice::query()->create([
            'reference' => $this->ref('INV'),
            'organization_id' => $customerOrg->id,
            'transport_job_id' => $open->id,
            'type' => InvoiceType::Customer,
            'amount' => 40,
            'currency' => 'OMR',
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);
        $old = $this->payment($settled, $customerOrg, 30, 3, 27, PaymentStatus::Completed);
        $old->forceFill(['paid_at' => now()->subYear()])->save();
        $this->payment($settled, $customerOrg, 50, 5, 45, PaymentStatus::Completed);

        $statement = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/finance/statement?'.http_build_query([
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))->assertOk();

        $statement->assertJsonPath('data.summary.collected', 50);
        $statement->assertJsonPath('data.summary.platform_revenue', 5);
        $statement->assertJsonPath('data.summary.customer_outstanding', 40);
        $statement->assertJsonPath('data.summary.net_profit', 5);
        $references = collect($statement->json('data.jobs'))->pluck('reference');
        $this->assertTrue($references->contains($open->reference));
        $this->assertTrue($references->contains($settled->reference));
    }

    public function test_project_statement_rolls_up_its_jobs(): void
    {
        [, $admin, $customerOrg, $providerOrg] = $this->actors();
        $customer = User::query()->where('organization_id', $customerOrg->id)->firstOrFail();
        $project = Project::query()->create([
            'project_id' => 'SITE-12',
            'name_en' => 'Sohar site',
            'name_ar' => 'موقع صحار',
        ]);
        $marketplace = $this->makeJob($customer, $customerOrg, $providerOrg, 'MKT', $project);
        $fleet = $this->makeJob($customer, $customerOrg, Organization::platform(), 'FLT', $project);
        $driver = User::factory()->create(['user_type' => UserType::Driver]);
        $loose = $this->makeJob($customer, $customerOrg, $providerOrg, 'LOOSE');

        $this->payment($marketplace, $customerOrg, 100, 10, 90, PaymentStatus::Completed);
        $this->payment($fleet, $customerOrg, 50, 50, 0, PaymentStatus::Completed);
        $this->driverPayable($fleet, $driver, 20, DriverPayableStatus::Paid);
        $this->payment($loose, $customerOrg, 80, 8, 72, PaymentStatus::Completed);

        $statement = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/finance/statement')->assertOk();
        $projectRow = collect($statement->json('data.projects'))->firstWhere('project_id', 'SITE-12');
        $this->assertNotNull($projectRow);
        $this->assertSame(2, $projectRow['jobs_count']);
        $this->assertEquals(60, $projectRow['platform_revenue']);
        $this->assertEquals(20, $projectRow['driver_expense']);
        $this->assertEquals(40, $projectRow['net_profit']);
        $this->assertEquals(48, $statement->json('data.summary.net_profit'));

        $filtered = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/finance/statement?project_id='.$project->id)
            ->assertOk();
        $filtered->assertJsonPath('data.summary.net_profit', 40);
        $filtered->assertJsonCount(2, 'data.jobs');
        $this->assertNull(collect($filtered->json('data.jobs'))->firstWhere('reference', $loose->reference));
    }

    /**
     * @return array{0: User, 1: User, 2: Organization, 3: Organization}
     */
    private function actors(): array
    {
        $customerOrg = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $providerOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Desert Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $customerOrg->id,
        ]);
        $customer->assignRole(StaffRoles::COMPANY_ADMIN);
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return [$customer, $admin, $customerOrg, $providerOrg];
    }

    private function makeJob(
        User $actor,
        Organization $customer,
        Organization $provider,
        string $label = 'JOB',
        ?Project $project = null,
    ): TransportJob {
        $shipment = ShipmentRequest::query()->create([
            'reference' => $this->ref('SHP'),
            'customer_organization_id' => $customer->id,
            'created_by' => $actor->id,
            'cargo_type' => 'Cement',
            'weight_tons' => 20,
            'quantity' => 20,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
            'required_date' => now()->toDateString(),
        ]);
        $quotation = Quotation::query()->create([
            'reference' => $this->ref('QTN'),
            'shipment_request_id' => $shipment->id,
            'provider_organization_id' => $provider->id,
            'created_by' => $actor->id,
            'total_price' => 100,
            'currency' => 'OMR',
            'truck_count' => 1,
            'truck_type' => 'flatbed',
            'truck_capacity_tons' => 30,
            'trip_count' => 1,
            'quantity_per_trip' => 20,
            'duration_days' => 1,
        ]);

        return TransportJob::query()->create([
            'reference' => $this->ref($label),
            'project_id' => $project?->id,
            'shipment_request_id' => $shipment->id,
            'quotation_id' => $quotation->id,
            'customer_organization_id' => $customer->id,
            'provider_organization_id' => $provider->id,
            'total_price' => 100,
            'total_quantity' => 20,
            'currency' => 'OMR',
        ]);
    }

    private function payment(
        TransportJob $job,
        Organization $payer,
        float $amount,
        float $commission,
        float $providerAmount,
        PaymentStatus $status,
    ): Payment {
        return Payment::query()->create([
            'reference' => $this->ref('PAY'),
            'idempotency_key' => $this->ref('idem'),
            'shipment_request_id' => $job->shipment_request_id,
            'quotation_id' => $job->quotation_id,
            'payer_organization_id' => $payer->id,
            'amount' => $amount,
            'commission_amount' => $commission,
            'provider_amount' => $providerAmount,
            'currency' => 'OMR',
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Pending ? null : now(),
        ]);
    }

    private function driverPayable(TransportJob $job, User $driver, float $amount, DriverPayableStatus $status): DriverPayable
    {
        $trip = Trip::query()->create([
            'reference' => $this->ref('TRP'),
            'transport_job_id' => $job->id,
            'sequence' => Trip::query()->where('transport_job_id', $job->id)->count() + 1,
            'driver_user_id' => $driver->id,
            'driver_pay_amount' => $amount,
            'planned_quantity' => 20,
            'status' => TripStatus::Completed,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
        ]);

        return DriverPayable::query()->create([
            'reference' => $this->ref('DPY'),
            'trip_id' => $trip->id,
            'driver_user_id' => $driver->id,
            'transport_job_id' => $job->id,
            'amount' => $amount,
            'currency' => 'OMR',
            'status' => $status,
            'paid_at' => $status === DriverPayableStatus::Paid ? now() : null,
        ]);
    }

    private function ref(string $prefix): string
    {
        $this->sequence++;

        return $prefix.'-'.$this->sequence;
    }
}
