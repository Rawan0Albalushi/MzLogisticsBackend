<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\JobStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\QuotationStatus;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\User;
use App\Support\StaffRoles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_creates_a_project_and_links_a_job(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeOrganization(OrganizationType::Customer, 'Coastal Build');
        $providerUser = $this->makeProvider('Fast Haul');
        $otherProvider = $this->makeProvider('Other Haul', 'other@example.com');
        $job = $this->makeJob($customer, $providerUser, 'JOB-1');
        $otherJob = $this->makeJob($customer, $otherProvider, 'JOB-2');

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/projects', [
                'project_id' => 'SITE-12',
                'name_en' => 'Coastal site',
                'name_ar' => 'موقع الساحل',
            ])
            ->assertCreated()
            ->assertJsonPath('data.project_id', 'SITE-12')
            ->assertJsonPath('data.name_ar', 'موقع الساحل');

        $projectId = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/projects', [
                'project_id' => 'SITE-12',
                'name_en' => 'Duplicate',
                'name_ar' => 'مكرر',
            ])
            ->assertStatus(422);

        $this->actingAs($providerUser, 'sanctum')
            ->postJson('/api/v1/projects', [
                'project_id' => 'SITE-99',
                'name_en' => 'Blocked',
                'name_ar' => 'مرفوض',
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/projects/{$projectId}/jobs", ['job_id' => $job->id])
            ->assertOk()
            ->assertJsonPath('data.jobs_count', 1)
            ->assertJsonPath('data.jobs.0.reference', 'JOB-1');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.project.project_id', 'SITE-12');

        $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.project_id', 'SITE-12');

        $this->actingAs($otherProvider, 'sanctum')
            ->getJson("/api/v1/projects/{$projectId}")
            ->assertForbidden();

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/projects', [
                'project_id' => 'SITE-13',
                'name_en' => 'Second site',
                'name_ar' => 'الموقع الثاني',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/projects/'.$second->json('data.id').'/jobs', ['job_id' => $job->id])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/projects/{$projectId}/jobs", ['job_id' => $otherJob->id])
            ->assertOk()
            ->assertJsonPath('data.jobs_count', 2);

        $this->actingAs($providerUser, 'sanctum')
            ->getJson("/api/v1/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.jobs_count', 1)
            ->assertJsonCount(1, 'data.jobs');

        $this->actingAs($providerUser, 'sanctum')
            ->deleteJson("/api/v1/projects/{$projectId}/jobs/{$job->id}")
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/projects/{$projectId}/jobs/{$job->id}")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.project', null);

        $this->assertDatabaseHas('transport_jobs', [
            'id' => $job->id,
            'project_id' => null,
        ]);

        $this->actingAs($providerUser, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_customer_cannot_list_projects(): void
    {
        $customer = $this->makeOrganization(OrganizationType::Customer, 'Coastal Build');
        $user = User::factory()->create([
            'user_type' => UserType::Customer,
            'organization_id' => $customer->id,
        ]);
        $user->assignRole('Customer Viewer');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/projects')
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => UserType::Platform]);
        $admin->assignRole(StaffRoles::SUPER_ADMIN);

        return $admin;
    }

    private function makeOrganization(OrganizationType $type, string $name): Organization
    {
        return Organization::query()->create([
            'type' => $type,
            'account_type' => AccountType::Company,
            'name' => $name,
            'status' => OrganizationStatus::Active,
        ]);
    }

    private function makeProvider(string $name, string $email = 'provider@example.com'): User
    {
        $organization = $this->makeOrganization(OrganizationType::Provider, $name);
        $provider = User::factory()->create([
            'email' => $email,
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }

    private function makeJob(Organization $customer, User $provider, string $reference): TransportJob
    {
        $shipment = ShipmentRequest::query()->create([
            'reference' => 'SHP-'.$reference,
            'customer_organization_id' => $customer->id,
            'created_by' => $provider->id,
            'cargo_type' => 'rebar',
            'weight_tons' => 10,
            'quantity' => 10,
            'pickup_address' => 'Muscat yard',
            'pickup_city' => 'Muscat',
            'delivery_address' => 'Sohar site',
            'delivery_city' => 'Sohar',
            'required_date' => now()->toDateString(),
        ]);

        $quotation = Quotation::query()->create([
            'reference' => 'QTE-'.$reference,
            'shipment_request_id' => $shipment->id,
            'provider_organization_id' => $provider->organization_id,
            'created_by' => $provider->id,
            'total_price' => 100,
            'truck_count' => 1,
            'truck_type' => 'flatbed',
            'truck_capacity_tons' => 10,
            'trip_count' => 1,
            'quantity_per_trip' => 10,
            'duration_days' => 1,
            'transport_start_date' => now()->toDateString(),
            'status' => QuotationStatus::Accepted,
        ]);

        return TransportJob::query()->create([
            'reference' => $reference,
            'shipment_request_id' => $shipment->id,
            'quotation_id' => $quotation->id,
            'customer_organization_id' => $customer->id,
            'provider_organization_id' => $provider->organization_id,
            'total_price' => 100,
            'total_quantity' => 10,
            'status' => JobStatus::PendingDispatch,
        ]);
    }
}
