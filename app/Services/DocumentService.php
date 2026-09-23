<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentService
{
    /**
     * @return list<DocumentType>
     */
    public function allowedTypes(Model $owner): array
    {
        return match ($owner::class) {
            Truck::class => [DocumentType::Insurance, DocumentType::VehicleRegistration],
            User::class => [DocumentType::DriverLicense, DocumentType::Identity],
            Organization::class => [DocumentType::CommercialLicense],
            default => [],
        };
    }

    public function store(Model $owner, UploadedFile $file, DocumentType $type, ?string $expiresAt, User $actor): Document
    {
        if (! in_array($type, $this->allowedTypes($owner), true)) {
            throw ValidationException::withMessages([
                'type' => ['This document type is not accepted for this record.'],
            ]);
        }

        $storedPath = $file->store($this->directory($owner), 'local');
        $replacedPaths = [];

        $document = DB::transaction(function () use ($owner, $file, $type, $expiresAt, $storedPath, &$replacedPaths) {
            $previous = $owner->documents()->where('type', $type)->get();
            $document = $owner->documents()->create([
                'type' => $type,
                'title' => $this->originalName($file),
                'file_path' => $storedPath,
                'expires_at' => $expiresAt,
                'status' => DocumentStatus::Pending,
            ]);

            foreach ($previous as $old) {
                $replacedPaths[] = $old->file_path;
                $old->delete();
            }

            return $document;
        });

        foreach ($replacedPaths as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        AuditLogger::record('document.uploaded', $document, [], [
            'type' => $type->value,
            'title' => $document->title,
        ], $actor);

        return $document;
    }

    public function stream(Document $document): StreamedResponse
    {
        $path = $document->file_path;
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $this->downloadName($document->title), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function directory(Model $owner): string
    {
        return 'documents/'.strtolower(class_basename($owner)).'/'.$owner->getKey();
    }

    private function originalName(UploadedFile $file): string
    {
        $name = str_replace(['\\', '/', "\0"], '', $file->getClientOriginalName());
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $extension = $file->guessExtension() ?: 'bin';
            $name = 'document.'.$extension;
        }

        return mb_substr($name, 0, 180);
    }

    private function downloadName(string $title): string
    {
        $name = str_replace(['\\', '/', "\r", "\n", '"'], '', $title);

        return $name !== '' ? $name : 'document';
    }
}
