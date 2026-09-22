<?php

namespace App\Services;

use App\Models\User;
use App\Support\SpreadsheetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverImportService
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'name' => 'name',
        'الاسم' => 'name',
        'اسم' => 'name',
        'phone' => 'phone',
        'الجوال' => 'phone',
        'الهاتف' => 'phone',
        'رقم الجوال' => 'phone',
        'email' => 'email',
        'البريد' => 'email',
        'البريد الإلكتروني' => 'email',
        'license_number' => 'license_number',
        'رقم الرخصة' => 'license_number',
        'الرخصة' => 'license_number',
        'license_expires_at' => 'license_expires_at',
        'انتهاء الرخصة' => 'license_expires_at',
        'تاريخ انتهاء الرخصة' => 'license_expires_at',
    ];

    public function __construct(private readonly DriverProvisioningService $provisioning) {}

    public function templateDownload(): StreamedResponse
    {
        return $this->spreadsheet()->template('Drivers', [
            'name / الاسم',
            'phone / الجوال',
            'email / البريد',
            'license_number / رقم الرخصة',
            'license_expires_at / انتهاء الرخصة',
        ], 'drivers-import-template.xlsx');
    }

    /**
     * @return array{
     *     created: int,
     *     failed: int,
     *     invites_sent: int,
     *     drivers: list<array{row: int, name: string, phone: string|null, invite_url: string, whatsapp_sent: bool}>,
     *     errors: list<array{row: int, field: string, message: string, name: string|null, phone: string|null, email: string|null, license_number: string|null}>
     * }
     */
    public function import(User $actor, UploadedFile $file): array
    {
        $rows = $this->spreadsheet()->rows($file);
        $maxRows = (int) config('mz.driver_activation.import_max_rows', 200);
        if (count($rows) > $maxRows) {
            throw ValidationException::withMessages([
                'file' => ["The file may contain at most {$maxRows} driver rows."],
            ]);
        }

        $created = [];
        $errors = [];
        $invitesSent = 0;

        foreach ($rows as $row) {
            try {
                $result = $this->provisioning->provision($actor, $row['data']);
                $created[] = [
                    'row' => $row['number'],
                    'name' => (string) $result['driver']->name,
                    'phone' => $result['driver']->phone,
                    'invite_url' => $result['invite_url'],
                    'whatsapp_sent' => $result['whatsapp_sent'],
                ];
                if ($result['whatsapp_sent']) {
                    $invitesSent++;
                }
            } catch (ValidationException $exception) {
                $field = (string) array_key_first($exception->errors());
                $data = $row['data'];
                $errors[] = [
                    'row' => $row['number'],
                    'field' => $field,
                    'message' => (string) Arr::first(Arr::flatten($exception->errors())),
                    'name' => filled($data['name'] ?? null) ? (string) $data['name'] : null,
                    'phone' => filled($data['phone'] ?? null) ? (string) $data['phone'] : null,
                    'email' => filled($data['email'] ?? null) ? (string) $data['email'] : null,
                    'license_number' => filled($data['license_number'] ?? null) ? (string) $data['license_number'] : null,
                ];
            }
        }

        return [
            'created' => count($created),
            'failed' => count($errors),
            'invites_sent' => $invitesSent,
            'drivers' => $created,
            'errors' => $errors,
        ];
    }

    private function spreadsheet(): SpreadsheetImport
    {
        return new SpreadsheetImport(
            headers: self::HEADERS,
            requiredHeaders: ['name', 'phone'],
            dateFields: ['license_expires_at'],
            missingHeaderMessage: 'The first row must include name and phone columns.',
        );
    }
}
