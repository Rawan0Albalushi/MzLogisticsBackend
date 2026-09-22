<?php

namespace App\Services;

use App\Enums\EquipmentStatus;
use App\Models\Equipment;
use App\Models\Truck;
use App\Models\User;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EquipmentImportService
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'name' => 'name',
        'الاسم' => 'name',
        'اسم المعدة' => 'name',
        'type' => 'type',
        'النوع' => 'type',
        'quantity' => 'quantity',
        'الكمية' => 'quantity',
        'status' => 'status',
        'الحالة' => 'status',
        'truck_plate' => 'truck_plate',
        'truck plate' => 'truck_plate',
        'plate' => 'truck_plate',
        'لوحة الشاحنة' => 'truck_plate',
        'رقم اللوحة' => 'truck_plate',
        'الشاحنة' => 'truck_plate',
    ];

    /**
     * @var array<string, string>
     */
    private const STATUSES = [
        'available' => 'available',
        'متاح' => 'available',
        'متاحة' => 'available',
        'in use' => 'in_use',
        'قيد الاستخدام' => 'in_use',
        'مستخدم' => 'in_use',
        'مستخدمة' => 'in_use',
        'maintenance' => 'maintenance',
        'صيانة' => 'maintenance',
        'inactive' => 'inactive',
        'غير نشط' => 'inactive',
        'غير نشطة' => 'inactive',
    ];

    public function templateDownload(): StreamedResponse
    {
        return $this->spreadsheet()->template('Equipment', [
            'name / الاسم',
            'type / النوع',
            'quantity / الكمية',
            'status / الحالة',
            'truck_plate / لوحة الشاحنة',
        ], 'equipment-import-template.xlsx');
    }

    /**
     * @return array{
     *     created: int,
     *     failed: int,
     *     items: list<array{row: int, label: string}>,
     *     errors: list<array{row: int, field: string, message: string, name: string|null, truck_plate: string|null}>
     * }
     */
    public function import(User $actor, UploadedFile $file): array
    {
        $organizationId = $actor->organization_id;
        if (! $organizationId) {
            throw ValidationException::withMessages([
                'file' => ['Your account is not linked to a company.'],
            ]);
        }

        $rows = $this->spreadsheet()->rows($file);
        $maxRows = (int) config('mz.fleet_import_max_rows', 200);
        if (count($rows) > $maxRows) {
            throw ValidationException::withMessages([
                'file' => ["The file may contain at most {$maxRows} equipment rows."],
            ]);
        }

        $created = [];
        $errors = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            try {
                $payload = $this->validated((int) $organizationId, $data);
                $item = Equipment::query()->create([
                    ...$payload,
                    'organization_id' => $organizationId,
                ]);
                $plate = filled($data['truck_plate'] ?? null) ? (string) $data['truck_plate'] : null;
                $created[] = [
                    'row' => $row['number'],
                    'label' => $plate ? "{$item->name} · {$plate}" : (string) $item->name,
                ];
            } catch (ValidationException $exception) {
                $errors[] = $this->error($row['number'], $exception, $data);
            }
        }

        return [
            'created' => count($created),
            'failed' => count($errors),
            'items' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, type: ?string, quantity: int, status: string, truck_id: ?int}
     */
    private function validated(int $organizationId, array $data): array
    {
        $plate = filled($data['truck_plate'] ?? null) ? trim((string) $data['truck_plate']) : null;
        $truckId = null;
        if ($plate !== null && $plate !== '') {
            $truckId = Truck::query()
                ->where('organization_id', $organizationId)
                ->where('plate_number', $plate)
                ->value('id');
            if (! $truckId) {
                throw ValidationException::withMessages([
                    'truck_plate' => ['No truck with this plate number belongs to your company.'],
                ]);
            }
        }

        $payload = Validator::make([
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'status' => $this->status($data['status'] ?? null) ?? EquipmentStatus::Available->value,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(EquipmentStatus::class)],
        ])->validate();

        $payload['type'] = filled($payload['type'] ?? null) ? $payload['type'] : null;
        $payload['truck_id'] = $truckId;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{row: int, field: string, message: string, name: string|null, truck_plate: string|null}
     */
    private function error(int $row, ValidationException $exception, array $data): array
    {
        return [
            'row' => $row,
            'field' => (string) array_key_first($exception->errors()),
            'message' => (string) Arr::first(Arr::flatten($exception->errors())),
            'name' => filled($data['name'] ?? null) ? (string) $data['name'] : null,
            'truck_plate' => filled($data['truck_plate'] ?? null) ? (string) $data['truck_plate'] : null,
        ];
    }

    private function status(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $key = trim(str_replace('_', ' ', (string) $value));
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;
        $key = mb_strtolower($key);

        return self::STATUSES[$key] ?? (string) $value;
    }

    private function spreadsheet(): SpreadsheetImport
    {
        return new SpreadsheetImport(
            headers: self::HEADERS,
            requiredHeaders: ['name', 'quantity'],
            integerFields: ['quantity'],
            missingHeaderMessage: 'The first row must include name and quantity columns.',
        );
    }
}
