<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceListFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_invoices_can_be_filtered_by_project_and_job(): void
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
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

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

        $first = $this->job($admin, $customerOrg, $providerOrg, $sohar);
        $second = $this->job($admin, $customerOrg, $providerOrg, $sohar);
        $other = $this->job($admin, $customerOrg, $providerOrg, $nizwa);

        $this->invoice($customerOrg, $first, 'INV-A');
        $this->invoice($customerOrg, $second, 'INV-B');
        $this->invoice($customerOrg, $other, 'INV-C');

        $byProject = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/invoices?project='.$sohar->id)
            ->assertOk();
        $projectRefs = collect($byProject->json('data'))->pluck('reference')->sort()->values()->all();
        $this->assertSame(['INV-A', 'INV-B'], $projectRefs);

        $byJob = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/invoices?job_id='.$second->id)
            ->assertOk();
        $this->assertSame(['INV-B'], collect($byJob->json('data'))->pluck('reference')->all());

        $both = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/invoices?project='.$sohar->id.'&job_id='.$other->id)
            ->assertOk();
        $this->assertSame([], $both->json('data'));
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

    private function invoice(Organization $customer, TransportJob $job, string $reference): void
    {
        Invoice::query()->create([
            'reference' => $reference,
            'organization_id' => $customer->id,
            'transport_job_id' => $job->id,
            'type' => InvoiceType::Customer,
            'amount' => 100,
            'currency' => 'OMR',
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);
    }

    private function ref(string $prefix): string
    {
        $this->sequence++;

        return $prefix.'-'.$this->sequence;
    }
}
