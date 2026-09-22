<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetImport
{
    /**
     * @var array<string, string>
     */
    private readonly array $headers;

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $requiredHeaders
     * @param  list<string>  $dateFields
     * @param  list<string>  $integerFields
     */
    public function __construct(
        array $headers,
        private readonly array $requiredHeaders,
        private readonly array $dateFields = [],
        private readonly array $integerFields = [],
        private readonly string $missingHeaderMessage = 'The first row must include the required columns.',
    ) {
        $normalized = [];
        foreach ($headers as $label => $field) {
            $normalized[$this->normalizeHeader((string) $label)] = $field;
        }
        $this->headers = $normalized;
    }

    /**
     * @param  list<string>  $headerRow
     */
    public function template(string $sheetTitle, array $headerRow, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->fromArray([$headerRow], null, 'A1', true);
        $lastColumn = chr(ord('A') + count($headerRow) - 1);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return list<array{number: int, data: array<string, mixed>}>
     */
    public function rows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        if (! in_array($extension, ['xlsx', 'xls'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Upload an Excel file (.xlsx).'],
            ]);
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $matrix = $sheet->toArray(null, true, true, false);

        if ($matrix === []) {
            throw ValidationException::withMessages([
                'file' => ['The Excel file is empty.'],
            ]);
        }

        $headerRowIndex = $this->findHeaderRow($matrix);
        if ($headerRowIndex === null) {
            throw ValidationException::withMessages([
                'file' => [$this->missingHeaderMessage],
            ]);
        }

        $map = $this->headerMap($matrix[$headerRowIndex]);
        $rows = [];

        foreach ($matrix as $index => $cells) {
            if ($index <= $headerRowIndex) {
                continue;
            }

            $data = [];
            foreach ($map as $columnIndex => $field) {
                $data[$field] = $this->cellValue($cells[$columnIndex] ?? null, $field);
            }

            if ($this->isBlankRow($data) || $this->isHeaderLabelRow($data)) {
                continue;
            }

            $rows[] = [
                'number' => $index + 1,
                'data' => $data,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<list<mixed>>  $matrix
     */
    private function findHeaderRow(array $matrix): ?int
    {
        foreach ($matrix as $index => $cells) {
            $map = $this->headerMap($cells);
            $fields = array_values($map);
            $matches = true;
            foreach ($this->requiredHeaders as $required) {
                if (! in_array($required, $fields, true)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $cells
     * @return array<int, string>
     */
    private function headerMap(array $cells): array
    {
        $map = [];
        foreach ($cells as $index => $cell) {
            $field = $this->headerField((string) $cell);
            if ($field !== null) {
                $map[$index] = $field;
            }
        }

        return $map;
    }

    private function headerField(string $value): ?string
    {
        $normalized = $this->normalizeHeader($value);
        if ($normalized !== '' && isset($this->headers[$normalized])) {
            return $this->headers[$normalized];
        }

        foreach (preg_split('/\s*\/\s*|\s*\|\s*/', $normalized) ?: [] as $part) {
            $part = $this->normalizeHeader($part);
            if ($part !== '' && isset($this->headers[$part])) {
                return $this->headers[$part];
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = str_replace(["\u{00A0}", '_'], [' ', ' '], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isHeaderLabelRow(array $data): bool
    {
        $filled = [];
        foreach ($data as $value) {
            if (filled($value)) {
                $filled[] = $this->normalizeHeader((string) $value);
            }
        }

        if ($filled === []) {
            return true;
        }

        foreach ($filled as $value) {
            if ($this->headerField($value) === null) {
                return false;
            }
        }

        return true;
    }

    private function cellValue(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, $this->dateFields, true)) {
            if (is_numeric($value)) {
                try {
                    return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
                } catch (\Throwable) {
                    return trim((string) $value);
                }
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d');
            }
        }

        if (in_array($field, $this->integerFields, true) && is_numeric($value)) {
            $number = (float) $value;
            if (floor($number) === $number) {
                return (string) (int) $number;
            }
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isBlankRow(array $data): bool
    {
        foreach ($data as $value) {
            if (filled($value)) {
                return false;
            }
        }

        return true;
    }
}
