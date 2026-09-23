<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RequiredDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_provider_can_upload_and_view_truck_documents(): void
    {
        $provider = $this->makeProvider('owner@example.com');
        $truck = $this->makeTruck($provider);

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/trucks/'.$truck->id.'/documents', [
                'type' => 'insurance',
                'file' => UploadedFile::fake()->create('policy.pdf', 120, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'insurance')
            ->assertJsonPath('data.title', 'policy.pdf')
            ->assertJsonMissingPath('data.file_path');

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/trucks')
            ->assertOk()
            ->assertJsonPath('data.0.documents.0.type', 'insurance')
            ->assertJsonPath('data.0.documents.0.title', 'policy.pdf')
            ->assertJsonMissingPath('data.0.documents.0.file_path');

        $documentId = $truck->fresh()->documents()->value('id');

        $this->actingAs($provider, 'sanctum')
            ->get('/api/v1/documents/'.$documentId.'/file')
            ->assertOk();
    }

    public function test_another_provider_cannot_read_or_replace_truck_documents(): void
    {
        $owner = $this->makeProvider('owner@example.com');
        $other = $this->makeProvider('other@example.com');
        $truck = $this->makeTruck($owner);

        $this->actingAs($owner, 'sanctum')
            ->post('/api/v1/trucks/'.$truck->id.'/documents', [
                'type' => 'vehicle_registration',
                'file' => UploadedFile::fake()->create('registration.pdf', 80, 'application/pdf'),
            ])
            ->assertCreated();

        $documentId = $truck->fresh()->documents()->value('id');

        $this->actingAs($other, 'sanctum')
            ->get('/api/v1/documents/'.$documentId.'/file')
            ->assertForbidden();

        $this->actingAs($other, 'sanctum')
            ->post('/api/v1/trucks/'.$truck->id.'/documents', [
                'type' => 'vehicle_registration',
                'file' => UploadedFile::fake()->create('other.pdf', 80, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_provider_can_upload_driver_and_company_documents(): void
    {
        $provider = $this->makeProvider('owner@example.com');
        $driver = User::factory()->create([
            'user_type' => UserType::Driver,
            'organization_id' => $provider->organization_id,
        ]);

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/drivers/'.$driver->id.'/documents', [
                'type' => 'driver_license',
                'file' => UploadedFile::fake()->create('license.jpg', 40, 'image/jpeg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'driver_license');

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/drivers/'.$driver->id.'/documents', [
                'type' => 'insurance',
                'file' => UploadedFile::fake()->create('wrong.pdf', 40, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/organizations/'.$provider->organization_id.'/documents', [
                'type' => 'commercial_license',
                'file' => UploadedFile::fake()->create('cr.pdf', 90, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'commercial_license');

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/v1/organizations/'.$provider->organization_id)
            ->assertOk()
            ->assertJsonPath('data.documents.0.type', 'commercial_license')
            ->assertJsonMissingPath('data.documents.0.file_path');
    }

    public function test_replacing_a_document_keeps_a_single_file_for_that_type(): void
    {
        $provider = $this->makeProvider('owner@example.com');
        $truck = $this->makeTruck($provider);

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/trucks/'.$truck->id.'/documents', [
                'type' => 'insurance',
                'file' => UploadedFile::fake()->create('old.pdf', 50, 'application/pdf'),
            ])
            ->assertCreated();

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/trucks/'.$truck->id.'/documents', [
                'type' => 'insurance',
                'file' => UploadedFile::fake()->create('new.pdf', 50, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'new.pdf');

        $this->assertSame(1, $truck->documents()->where('type', 'insurance')->count());
    }

    private function makeProvider(string $email): User
    {
        $organization = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Fast Haul '.$email,
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

    private function makeTruck(User $provider): Truck
    {
        return Truck::query()->create([
            'organization_id' => $provider->organization_id,
            'plate_number' => 'A 1001',
            'type' => 'flatbed',
            'capacity_tons' => 20,
        ]);
    }
}
