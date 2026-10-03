<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordInvoiceBankTransferRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\JobResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Settlement;
use App\Services\FinanceStatementService;
use App\Services\JobOrchestrationService;
use App\Services\SettlementService;
use App\Support\ApiResponse;
use App\Support\BankTransferResponse;
use App\Support\ListFilters;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class FinanceController extends Controller
{
    public function __construct(
        private readonly SettlementService $settlements,
        private readonly JobOrchestrationService $jobs,
    ) {}

    public function statement(Request $request, FinanceStatementService $statement): JsonResponse
    {
        abort_unless($request->user()->isPlatform() && $request->user()->can(Permissions::PAYMENTS_VIEW), 403);

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'job_id' => ['nullable', 'integer', 'exists:transport_jobs,id'],
            'unassigned' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return ApiResponse::success($statement->build($filters));
    }

    public function payments(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW), 403);
        $user = $request->user();

        $items = Payment::query()
            ->with(['quotation.providerOrganization', 'quotation.shipmentRequest', 'payerOrganization'])
            ->when($user->isCustomer(), fn ($q) => $q->where('payer_organization_id', $user->organization_id))
            ->when($user->isProvider(), fn ($q) => $q->whereHas('quotation', fn ($quotation) => $quotation->where('provider_organization_id', $user->organization_id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('job_id'), fn ($q) => $q->whereHas('transportJob', fn ($job) => $job->whereKey($request->integer('job_id'))))
            ->when($request->filled('project'), function ($query) use ($request) {
                $query->whereHas('transportJob', fn ($job) => $job->where('project_id', $request->integer('project')));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['reference', 'method', 'gateway'],
                    ['payerOrganization' => ['name', 'name_ar']],
                );
            })
            ->tap(fn ($q) => ListFilters::dateRange($q, $request->all(), 'created_at'))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(PaymentResource::collection($items));
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVOICES_VIEW), 403);
        $user = $request->user();

        $items = Invoice::query()
            ->with(['organization', 'transportJob', 'payment', 'sourcePayment', 'trip'])
            ->when(! $user->isPlatform(), fn ($q) => $q->where('organization_id', $user->organization_id))
            ->when($user->isProvider(), function ($query) {
                $query->where(function ($inner) {
                    $inner->where('type', '!=', InvoiceType::Commission->value)
                        ->orWhereHas('transportJob', fn ($job) => $job->whereNull('provider_price'));
                });
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('job_id'), fn ($q) => $q->where('transport_job_id', $request->integer('job_id')))
            ->when($request->filled('project'), function ($query) use ($request) {
                $query->whereHas('transportJob', fn ($job) => $job->where('project_id', $request->integer('project')));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['reference'],
                    ['organization' => ['name', 'name_ar']],
                );
            })
            ->tap(fn ($q) => ListFilters::dateRange($q, $request->all(), 'issued_at'))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(InvoiceResource::collection($items));
    }

    public function payInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW) || $request->user()->can(Permissions::INVOICES_VIEW), 403);

        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $this->jobs->payCustomerInvoice(
            $request->user(),
            $invoice,
            $data['payment_method'] ?? null,
            $request->header('X-Payment-Callback-Base'),
        );

        if ($result->awaitingTransfer) {
            return ApiResponse::success(
                BankTransferResponse::awaiting($result->payment, $result->job),
                'Bank transfer recorded. Finance will confirm the receipt before this invoice is settled.',
            );
        }

        if ($result->requiresCheckout) {
            return ApiResponse::success([
                'requires_checkout' => true,
                'payment_link' => $result->paymentLink,
                'session_id' => $result->sessionId,
                'payment' => PaymentResource::make($result->payment)->resolve(),
                'job' => $result->job ? JobResource::make($result->job)->resolve() : null,
            ], 'Complete payment with Thawani to settle this invoice.');
        }

        return ApiResponse::success([
            'requires_checkout' => false,
            'payment' => PaymentResource::make($result->payment)->resolve(),
            'job' => $result->job ? JobResource::make($result->job)->resolve() : null,
        ], 'Invoice paid.');
    }

    public function recordTransfer(RecordInvoiceBankTransferRequest $request, Invoice $invoice): JsonResponse
    {
        $receipt = $request->file('receipt');
        abort_unless($receipt instanceof UploadedFile, 422);

        $result = $this->jobs->recordInvoiceBankTransfer(
            $request->user(),
            $invoice,
            $receipt,
            $request->validated('transfer_reference'),
        );

        return ApiResponse::success([
            'payment' => PaymentResource::make($result['payment'])->resolve(),
            'job' => $result['job'] ? JobResource::make($result['job'])->resolve() : null,
        ], 'Bank transfer recorded.');
    }

    public function settlements(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTLEMENTS_VIEW), 403);
        $user = $request->user();

        $items = Settlement::query()
            ->with(['providerOrganization', 'requester:id,name'])
            ->when($user->isProvider(), fn ($q) => $q->where('provider_organization_id', $user->organization_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                ListFilters::search(
                    $q,
                    $request->string('search')->toString(),
                    ['reference'],
                    ['providerOrganization' => ['name', 'name_ar']],
                );
            })
            ->tap(fn ($q) => ListFilters::dateRange($q, $request->all(), 'period_start'))
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success($items);
    }

    public function storeSettlement(Request $request): JsonResponse
    {
        abort_unless($request->user()->isPlatform() && $request->user()->can(Permissions::SETTLEMENTS_MANAGE), 403);
        $data = $request->validate([
            'provider_organization_id' => ['required', 'exists:organizations,id'],
            'amount' => ['required', 'numeric', 'min:0.001'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $settlement = $this->settlements->create($request->user(), $data);

        return ApiResponse::success($settlement, 'Settlement created.', 201);
    }

    public function requestSettlement(Request $request): JsonResponse
    {
        abort_unless($request->user()->isProvider() && $request->user()->can(Permissions::SETTLEMENTS_REQUEST), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $settlement = $this->settlements->request($request->user(), $data);

        return ApiResponse::success($settlement, 'Withdrawal requested.', 201);
    }

    public function completeSettlement(Request $request, Settlement $settlement): JsonResponse
    {
        abort_unless($request->user()->isPlatform() && $request->user()->can(Permissions::SETTLEMENTS_MANAGE), 403);

        return ApiResponse::success($this->settlements->complete($request->user(), $settlement), 'Settlement completed.');
    }
}
