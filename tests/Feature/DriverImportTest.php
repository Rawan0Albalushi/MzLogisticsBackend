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
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DriverImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_provider_can_download_import_template(): void
    {
        $provider = $this->makeProvider();

        $this->actingAs($provider, 'sanctum')
            ->get('/api/v1/drivers/import-template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_provider_can_import_drivers_with_partial_success(): void
    {
        $provider = $this->makeProvider();
        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/v1/drivers', [
                'name' => 'Existing',
                'phone' => '99226601',
            ])
            ->assertCreated();

        $file = $this->excelFile([
            ['name', 'phone', 'email', 'license_number', 'license_expires_at'],
            ['New Driver', '99226602', '', 'OM-1', '2028-01-01'],
            ['Duplicate', '99226601', '', '', ''],
            ['', '', '', '', ''],
            ['Missing Phone', '', '', '', ''],
        ]);

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/drivers/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 2)
            ->assertJsonPath('data.invites_sent', 0)
            ->assertJsonPath('data.drivers.0.phone', '+96899226602')
            ->assertJsonPath('data.errors.0.field', 'phone')
            ->assertJsonPath('data.errors.0.name', 'Duplicate')
            ->assertJsonPath('data.errors.0.phone', '99226601')
            ->assertJsonPath('data.errors.1.name', 'Missing Phone');

        $this->assertDatabaseHas('users', [
            'name' => 'New Driver',
            'phone' => '+96899226602',
        ]);
    }

    public function test_import_skips_translated_header_row(): void
    {
        $provider = $this->makeProvider();
        $file = $this->excelFile([
            ['name', 'phone', 'email', 'license_number', 'license_expires_at'],
            ['الاسم', 'الجوال', 'البريد', 'رقم الرخصة', 'انتهاء الرخصة'],
            ['سالم السائق', '99226604', '', 'OM-99', ''],
        ]);

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/drivers/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.drivers.0.name', 'سالم السائق');
    }

    public function test_import_accepts_arabic_headers(): void
    {
        $provider = $this->makeProvider();
        $file = $this->excelFile([
            ['الاسم', 'الجوال', 'رقم الرخصة'],
            ['سالم السائق', '99226603', 'OM-88'],
        ]);

        $this->actingAs($provider, 'sanctum')
            ->post('/api/v1/drivers/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.drivers.0.name', 'سالم السائق');
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function excelFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
        $path = storage_path('framework/testing-drivers.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'drivers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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
            'email' => 'provider-import@example.com',
            'user_type' => UserType::Provider,
            'organization_id' => $organization->id,
        ]);
        $provider->assignRole('Provider Admin');

        return $provider;
    }
}
