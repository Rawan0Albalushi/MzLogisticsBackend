<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\DriverPayableStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PaymentStatus;
use App\Enums\TripStatus;
use App\Enums\UserType;
use App\Models\DriverPayable;
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

class RecordScopeFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_linked_lists_filter_by_project_and_job(): void
    {
        $customer = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Acme Trading',
            'status' => OrganizationStatus::Active,
        ]);
        $provider = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Desert Haul',
            'status' => OrganizationStatus::Active,
        ]);
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);
        $driver = User::factory()->create(['user_type' => UserType::Driver]);

        $sohar = Project::query()->create([
            'project_id' => 'SITE-12',
            'name_en' => 'Sohar site',
            'name_ar' => 'موقع صحار',
        ]);
        $nizwa = Project::query()->create([
            'project_id' => 'SITE-20',
            'name_en' => 'Nizwa site',
            'name_ar' => 'موقع نزوى',
        ]);

        $first = $this->job($admin, $customer, $provider, $sohar);
        $second = $this->job($admin, $customer, $provider, $nizwa);
        $this->payment($first, $customer, 'PAY-A');
        $this->payment($second, $customer, 'PAY-B');
        $tripA = $this->trip($first, $driver, 'TRP-A');
        $tripB = $this->trip($second, $driver, 'TRP-B');
        $this->payable($first, $driver, $tripA, 'DPY-A');
        $this->payable($second, $driver, $tripB, 'DPY-B');

        $this->actingAs($admin, 'sanctum');

        $this->assertSame(
            [$first->reference],
            collect($this->getJson('/api/v1/jobs?project='.$sohar->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );

        $this->assertSame(
            ['TRP-A'],
            collect($this->getJson('/api/v1/trips?project='.$sohar->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );
        $this->assertSame(
            ['TRP-B'],
            collect($this->getJson('/api/v1/trips?job_id='.$second->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );
        $this->assertSame(
            [],
            $this->getJson('/api/v1/trips?project='.$sohar->id.'&job_id='.$second->id)->assertOk()->json('data'),
        );

        $this->assertSame(
            ['PAY-A'],
            collect($this->getJson('/api/v1/payments?project='.$sohar->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );
        $this->assertSame(
            ['PAY-B'],
            collect($this->getJson('/api/v1/payments?job_id='.$second->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );

        $this->assertSame(
            ['DPY-A'],
            collect($this->getJson('/api/v1/driver-payables?project='.$sohar->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );
        $this->assertSame(
            ['DPY-B'],
            collect($this->getJson('/api/v1/driver-payables?job_id='.$second->id)->assertOk()->json('data'))->pluck('reference')->all(),
        );

        $statement = $this->getJson('/api/v1/finance/statement?job_id='.$first->id)->assertOk();
        $this->assertSame([$first->reference], collect($statement->json('data.jobs'))->pluck('reference')->all());
    }

    private function job(User $actor, Organization $customer, Organization $provider, Project $project): TransportJob
    {
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
            'reference' => $this->ref('JOB'),
            'project_id' => $project->id,
            'shipment_request_id' => $shipment->id,
            'quotation_id' => $quotation->id,
            'customer_organization_id' => $customer->id,
            'provider_organization_id' => $provider->id,
            'total_price' => 100,
            'total_quantity' => 20,
            'currency' => 'OMR',
        ]);
    }

    private function payment(TransportJob $job, Organization $payer, string $reference): void
    {
        Payment::query()->create([
            'reference' => $reference,
            'idempotency_key' => $reference,
            'shipment_request_id' => $job->shipment_request_id,
            'quotation_id' => $job->quotation_id,
            'payer_organization_id' => $payer->id,
            'amount' => 100,
            'commission_amount' => 10,
            'provider_amount' => 90,
            'currency' => 'OMR',
            'status' => PaymentStatus::Completed,
            'paid_at' => now(),
        ]);
    }

    private function trip(TransportJob $job, User $driver, string $reference): Trip
    {
        return Trip::query()->create([
            'reference' => $reference,
            'transport_job_id' => $job->id,
            'sequence' => 1,
            'driver_user_id' => $driver->id,
            'driver_pay_amount' => 15,
            'planned_quantity' => 20,
            'status' => TripStatus::Completed,
            'pickup_address' => 'Sohar Port',
            'pickup_city' => 'Sohar',
            'delivery_address' => 'Nizwa',
            'delivery_city' => 'Nizwa',
        ]);
    }

    private function payable(TransportJob $job, User $driver, Trip $trip, string $reference): void
    {
        DriverPayable::query()->create([
            'reference' => $reference,
            'trip_id' => $trip->id,
            'driver_user_id' => $driver->id,
            'transport_job_id' => $job->id,
            'amount' => 15,
            'currency' => 'OMR',
            'status' => DriverPayableStatus::Pending,
        ]);
    }

    private function ref(string $prefix): string
    {
        $this->sequence++;

        return $prefix.'-'.$this->sequence;
    }
}
