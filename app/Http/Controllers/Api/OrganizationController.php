<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\ListFilters;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CUSTOMERS_VIEW), 403);

        $items = Organization::query()
            ->customers()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('account_type'), function ($q) use ($request) {
                $accountType = AccountType::tryFrom((string) $request->string('account_type'));
                if ($accountType) {
                    $q->where('account_type', $accountType);
                }
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['name', 'name_ar', 'email', 'phone', 'city', 'commercial_register'],
                );
            })
            ->when($request->filled('city'), fn ($q) => ListFilters::city($q, $request->string('city')->toString()))
            ->tap(fn ($q) => ListFilters::dateRange($q, $request->all(), 'created_at'))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(OrganizationResource::collection($items));
    }

    public function providers(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PROVIDERS_VIEW), 403);

        $items = Organization::query()
            ->providers()
            ->withCount(['trucks', 'driverProfiles', 'providerJobs'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['name', 'name_ar', 'commercial_register', 'email', 'phone', 'city'],
                );
            })
            ->when($request->filled('city'), fn ($q) => ListFilters::city($q, $request->string('city')->toString()))
            ->tap(fn ($q) => ListFilters::dateRange($q, $request->all(), 'created_at'))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(OrganizationResource::collection($items));
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->assertCanView($request, $organization);
        $organization->load(['users', 'trucks', 'documents', 'driverProfiles.user']);

        return ApiResponse::success(OrganizationResource::make($organization)->additional([
            'users' => $organization->users,
            'trucks' => $organization->trucks,
            'documents' => $organization->documents,
        ]));
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        abort_unless(
            $request->user()->isPlatform()
            || ((int) $request->user()->organization_id === $organization->id && $request->user()->can(Permissions::COMPANY_MANAGE)),
            403
        );

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'name_ar' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'commercial_register' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:80'],
        ]);

        $organization->fill($data)->save();

        return ApiResponse::success(OrganizationResource::make($organization), 'Organization updated.');
    }

    public function verify(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PROVIDERS_VERIFY), 403);
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrganizationStatus::class)],
            'verification_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $organization->fill($data)->save();
        AuditLogger::record('provider.verified', $organization, [], $data, $request->user());

        return ApiResponse::success(OrganizationResource::make($organization), 'Organization status updated.');
    }

    public function updateCommissionRate(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($request->user()->isPlatform(), 403);
        abort_unless(
            $request->user()->can(Permissions::PROVIDERS_MANAGE)
            || $request->user()->can(Permissions::SETTLEMENTS_MANAGE),
            403
        );

        if (! $organization->isProvider()) {
            throw ValidationException::withMessages([
                'commission_rate' => ['Commission rate can only be set for service providers.'],
            ]);
        }

        $data = $request->validate([
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $previous = ['commission_rate' => $organization->commission_rate];
        $organization->forceFill([
            'commission_rate' => $data['commission_rate'] ?? null,
        ])->save();

        AuditLogger::record('provider.commission_updated', $organization, $previous, $data, $request->user());

        return ApiResponse::success(OrganizationResource::make($organization->fresh()), 'Commission rate updated.');
    }

    private function assertCanView(Request $request, Organization $organization): void
    {
        $user = $request->user();
        if ($user->isPlatform()) {
            return;
        }

        abort_unless((int) $user->organization_id === $organization->id, 403);
    }
}
