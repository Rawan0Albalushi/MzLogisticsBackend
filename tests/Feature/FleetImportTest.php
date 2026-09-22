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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FleetImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_provider_can_download_truck_and_equipment_templates(): void
    {
        $provider = $this->makeProvider();

        $this->actingAs($provider, 'sanctum')
            ->get('/api/v1/trucks/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($provider, 'sanctum')
            ->get('/api/v1/equipment/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_provider_can_import_trucks_with_partial_success(): void
    {
        $provider = $this->makeProvider();
        Truck::query()->create([
            'organization_id' => $provider->organization_id,
            'plate_number' => 'H 1001',
            'type' => 'flatbed',
            'capacity_tons' => 20,
        ]);

        $file = $this->excelFile([
            ['plate_number', 'type', 'capacity_tons', 'make', 'status'],
            ['رقم اللوحة', 'النوع', 'السعة', 'الشركة', 'الحالة'],
            ['H 2002', 'سطحة', '32', 'Volvo', 'متاحة'],
            ['H 1001', 'box', '18', '', ''],
            ['', '', '', '', ''],
            ['H 2003', 'unknown', '10', '', ''],
        ], 'trucks');

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/trucks/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 2)
            ->assertJsonPath('data.items.0.label', 'H 2002')
            ->assertJsonPath('data.errors.0.field', 'plate_number')
            ->assertJsonPath('data.errors.0.plate_number', 'H 1001')
            ->assertJsonPath('data.errors.1.field', 'type');

        $this->assertDatabaseHas('trucks', [
            'organization_id' => $provider->organization_id,
            'plate_number' => 'H 2002',
            'type' => 'flatbed',
            'make' => 'Volvo',
            'status' => 'available',
        ]);
    }

    public function test_provider_can_import_equipment_and_attach_it_by_plate(): void
    {
        $provider = $this->makeProvider();
        $truck = Truck::query()->create([
            'organization_id' => $provider->organization_id,
            'plate_number' => 'H 3001',
            'type' => 'box',
            'capacity_tons' => 12,
        ]);

        $file = $this->excelFile([
            ['name', 'type', 'quantity', 'status', 'truck_plate'],
            ['ونش', 'رفع', '2', 'متاحة', 'H 3001'],
            ['سلاسل', 'تثبيت', '4', '', ''],
            ['رافعات', '', '', '', 'H 9999'],
        ], 'equipment');

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/equipment/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.failed', 1)
            ->assertJsonPath('data.items.0.label', 'ونش · H 3001')
            ->assertJsonPath('data.errors.0.field', 'truck_plate')
            ->assertJsonPath('data.errors.0.name', 'رافعات');

        $this->assertDatabaseHas('equipment', [
            'organization_id' => $provider->organization_id,
            'name' => 'ونش',
            'quantity' => 2,
            'truck_id' => $truck->id,
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('equipment', [
            'organization_id' => $provider->organization_id,
            'name' => 'سلاسل',
            'truck_id' => null,
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function excelFile(array $rows, string $name): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = storage_path("framework/testing-{$name}.xlsx");
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, "{$name}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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
            'email' => 'provider-fleet-import@example.com',
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }
}
