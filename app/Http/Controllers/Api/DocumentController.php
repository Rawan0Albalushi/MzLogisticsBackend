<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use App\Services\DocumentService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function storeForTruck(Request $request, Truck $truck): JsonResponse
    {
        $this->authorizePermission($request, Permissions::FLEET_MANAGE);
        $this->assertSameOrganization($request, $truck->organization_id);

        $document = $this->documents->store(
            $truck,
            $this->validatedFile($request, $truck),
            $this->validatedType($request, $truck),
            $request->input('expires_at'),
            $request->user(),
        );

        return ApiResponse::success(DocumentResource::make($document), 'Document uploaded.', 201);
    }

    public function storeForDriver(Request $request, User $driver): JsonResponse
    {
        $this->authorizePermission($request, Permissions::DRIVERS_MANAGE);
        abort_unless($driver->isDriver(), 404);
        $this->assertSameOrganization($request, (int) $driver->organization_id);

        $document = $this->documents->store(
            $driver,
            $this->validatedFile($request, $driver),
            $this->validatedType($request, $driver),
            $request->input('expires_at'),
            $request->user(),
        );

        return ApiResponse::success(DocumentResource::make($document), 'Document uploaded.', 201);
    }

    public function storeForOrganization(Request $request, Organization $organization): JsonResponse
    {
        abort_unless(
            $request->user()->isPlatform()
            || ((int) $request->user()->organization_id === $organization->id && $request->user()->can(Permissions::COMPANY_MANAGE)),
            403
        );

        $document = $this->documents->store(
            $organization,
            $this->validatedFile($request, $organization),
            $this->validatedType($request, $organization),
            $request->input('expires_at'),
            $request->user(),
        );

        return ApiResponse::success(DocumentResource::make($document), 'Document uploaded.', 201);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->assertCanView($request, $document);

        return $this->documents->stream($document);
    }

    private function validatedType(Request $request, Truck|User|Organization $owner): DocumentType
    {
        $allowed = array_map(
            fn (DocumentType $type) => $type->value,
            $this->documents->allowedTypes($owner),
        );
        $data = $request->validate([
            'type' => ['required', Rule::in($allowed)],
            'expires_at' => ['nullable', 'date'],
        ]);

        return DocumentType::from($data['type']);
    }

    private function validatedFile(Request $request, Truck|User|Organization $owner): \Illuminate\Http\UploadedFile
    {
        $this->validatedType($request, $owner);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        return $data['file'];
    }

    private function assertCanView(Request $request, Document $document): void
    {
        $owner = $document->documentable;
        abort_unless($owner !== null, 404);

        if ($owner instanceof Truck) {
            $this->authorizePermission($request, Permissions::FLEET_VIEW);
            $this->assertSameOrganization($request, $owner->organization_id);

            return;
        }

        if ($owner instanceof User) {
            abort_unless($owner->isDriver(), 404);
            $this->authorizePermission($request, Permissions::DRIVERS_VIEW);
            $this->assertSameOrganization($request, (int) $owner->organization_id);

            return;
        }

        if ($owner instanceof Organization) {
            abort_unless(
                $request->user()->isPlatform()
                || ((int) $request->user()->organization_id === $owner->id && $request->user()->can(Permissions::COMPANY_MANAGE)),
                403
            );

            return;
        }

        abort(404);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission) || $request->user()->isPlatform(), 403);
    }

    private function assertSameOrganization(Request $request, int $organizationId): void
    {
        if ($request->user()->isPlatform()) {
            return;
        }

        abort_unless((int) $request->user()->organization_id === $organizationId, 403);
    }
}
