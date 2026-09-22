<?php

namespace App\Services;

use App\Enums\TruckStatus;
use App\Models\Truck;
use App\Models\User;
use App\Rules\UsableTruckType;
use App\Support\SpreadsheetImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TruckImportService
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'plate_number' => 'plate_number',
        'plate' => 'plate_number',
        'plate number' => 'plate_number',
        'رقم اللوحة' => 'plate_number',
        'اللوحة' => 'plate_number',
        'type' => 'type',
        'النوع' => 'type',
        'نوع الشاحنة' => 'type',
        'capacity_tons' => 'capacity_tons',
        'capacity' => 'capacity_tons',
        'السعة' => 'capacity_tons',
        'الحمولة' => 'capacity_tons',
        'volume_cbm' => 'volume_cbm',
        'volume' => 'volume_cbm',
        'الحجم' => 'volume_cbm',
        'cargo_length_m' => 'cargo_length_m',
        'length' => 'cargo_length_m',
        'الطول' => 'cargo_length_m',
        'cargo_width_m' => 'cargo_width_m',
        'width' => 'cargo_width_m',
        'العرض' => 'cargo_width_m',
        'cargo_height_m' => 'cargo_height_m',
        'height' => 'cargo_height_m',
        'الارتفاع' => 'cargo_height_m',
        'axle_count' => 'axle_count',
        'axles' => 'axle_count',
        'المحاور' => 'axle_count',
        'عدد المحاور' => 'axle_count',
        'year' => 'year',
        'السنة' => 'year',
        'make' => 'make',
        'الشركة' => 'make',
        'الشركة المصنعة' => 'make',
        'model' => 'model',
        'الطراز' => 'model',
        'الموديل' => 'model',
        'status' => 'status',
        'الحالة' => 'status',
        'insurance_expires_at' => 'insurance_expires_at',
        'insurance' => 'insurance_expires_at',
        'انتهاء التأمين' => 'insurance_expires_at',
        'التأمين' => 'insurance_expires_at',
    ];

    /**
     * @var array<string, string>
     */
    private const STATUSES = [
        'available' => 'available',
        'متاح' => 'available',
        'متاحة' => 'available',
        'assigned' => 'assigned',
        'معين' => 'assigned',
        'معينة' => 'assigned',
        'معيّن' => 'assigned',
        'معيّنة' => 'assigned',
        'maintenance' => 'maintenance',
        'صيانة' => 'maintenance',
        'inactive' => 'inactive',
        'غير نشط' => 'inactive',
        'غير نشطة' => 'inactive',
        'متوقف' => 'inactive',
        'متوقفة' => 'inactive',
    ];

    public function __construct(private readonly TruckTypeService $truckTypes) {}

    public function templateDownload(): StreamedResponse
    {
        return $this->spreadsheet()->template('Trucks', [
            'plate_number / رقم اللوحة',
            'type / النوع',
            'capacity_tons / السعة',
            'volume_cbm / الحجم',
            'cargo_length_m / الطول',
            'cargo_width_m / العرض',
            'cargo_height_m / الارتفاع',
            'axle_count / المحاور',
            'year / السنة',
            'make / الشركة',
            'model / الطراز',
            'status / الحالة',
            'insurance_expires_at / انتهاء التأمين',
        ], 'trucks-import-template.xlsx');
    }

    /**
     * @return array{
     *     created: int,
     *     failed: int,
     *     items: list<array{row: int, label: string}>,
     *     errors: list<array{row: int, field: string, message: string, plate_number: string|null, type: string|null}>
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
                'file' => ["The file may contain at most {$maxRows} truck rows."],
            ]);
        }

        $typeMap = $this->typeMap($actor);
        $created = [];
        $errors = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            try {
                $payload = $this->validated($actor, (int) $organizationId, $data, $typeMap);
                $truck = Truck::query()->create([
                    ...$payload,
                    'organization_id' => $organizationId,
                ]);
                $created[] = [
                    'row' => $row['number'],
                    'label' => (string) $truck->plate_number,
                ];
            } catch (ValidationException $exception) {
                $errors[] = $this->error($row['number'], $exception, $data);
            } catch (UniqueConstraintViolationException) {
                $errors[] = [
                    'row' => $row['number'],
                    'field' => 'plate_number',
                    'message' => 'A truck with this plate number already exists.',
                    'plate_number' => filled($data['plate_number'] ?? null) ? (string) $data['plate_number'] : null,
                    'type' => filled($data['type'] ?? null) ? (string) $data['type'] : null,
                ];
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
     * @param  array<string, string>  $typeMap
     * @return array<string, mixed>
     */
    private function validated(User $actor, int $organizationId, array $data, array $typeMap): array
    {
        $type = filled($data['type'] ?? null) ? (string) $data['type'] : null;
        if ($type !== null) {
            $type = $typeMap[$this->normalizeLabel($type)] ?? $type;
        }

        $payload = [
            'plate_number' => $data['plate_number'] ?? null,
            'type' => $type,
            'capacity_tons' => $data['capacity_tons'] ?? null,
            'volume_cbm' => $data['volume_cbm'] ?? null,
            'cargo_length_m' => $data['cargo_length_m'] ?? null,
            'cargo_width_m' => $data['cargo_width_m'] ?? null,
            'cargo_height_m' => $data['cargo_height_m'] ?? null,
            'axle_count' => $data['axle_count'] ?? null,
            'year' => $data['year'] ?? null,
            'make' => $data['make'] ?? null,
            'model' => $data['model'] ?? null,
            'status' => $this->status($data['status'] ?? null) ?? TruckStatus::Available->value,
            'insurance_expires_at' => $data['insurance_expires_at'] ?? null,
        ];

        return Validator::make($payload, [
            'plate_number' => [
                'required',
                'string',
                'max:32',
                Rule::unique('trucks', 'plate_number')->where(
                    fn ($query) => $query->where('organization_id', $organizationId),
                ),
            ],
            'type' => ['required', 'string', 'max:32', new UsableTruckType($actor)],
            'capacity_tons' => ['required', 'numeric', 'min:0.1'],
            'volume_cbm' => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
            'cargo_length_m' => ['nullable', 'numeric', 'min:0.01', 'max:50'],
            'cargo_width_m' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'cargo_height_m' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'axle_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(TruckStatus::class)],
            'insurance_expires_at' => ['nullable', 'date'],
        ], [
            'plate_number.unique' => 'A truck with this plate number already exists.',
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{row: int, field: string, message: string, plate_number: string|null, type: string|null}
     */
    private function error(int $row, ValidationException $exception, array $data): array
    {
        return [
            'row' => $row,
            'field' => (string) array_key_first($exception->errors()),
            'message' => (string) Arr::first(Arr::flatten($exception->errors())),
            'plate_number' => filled($data['plate_number'] ?? null) ? (string) $data['plate_number'] : null,
            'type' => filled($data['type'] ?? null) ? (string) $data['type'] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeMap(User $user): array
    {
        $map = [];
        foreach ($this->truckTypes->visibleActive($user) as $type) {
            foreach ([$type->code, $type->name, $type->name_ar] as $label) {
                if (filled($label)) {
                    $map[$this->normalizeLabel((string) $label)] = $type->code;
                }
            }
        }

        return $map;
    }

    private function status(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $key = $this->normalizeLabel((string) $value);

        return self::STATUSES[$key] ?? (string) $value;
    }

    private function normalizeLabel(string $value): string
    {
        $value = trim(str_replace('_', ' ', $value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    private function spreadsheet(): SpreadsheetImport
    {
        return new SpreadsheetImport(
            headers: self::HEADERS,
            requiredHeaders: ['plate_number', 'type', 'capacity_tons'],
            dateFields: ['insurance_expires_at'],
            integerFields: ['axle_count', 'year'],
            missingHeaderMessage: 'The first row must include plate number, type, and capacity columns.',
        );
    }
}
