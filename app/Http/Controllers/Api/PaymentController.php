<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\JobOrchestrationService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly JobOrchestrationService $jobs,
    ) {}

    public function success(Request $request): View|JsonResponse
    {
        $payment = $this->findPayment($request->integer('payment_id'));

        if (! $payment) {
            return $this->returnToApp($request, '/payment/cancel', ['error' => 'payment_not_found'], cancelled: true);
        }

        try {
            $job = $this->jobs->completePaidQuotation($payment, $payment->payerOrganization?->users()->first());

            return $this->returnToApp($request, '/payment/success', [
                'payment_id' => (string) $payment->id,
                'job_id' => (string) $job->id,
                'success' => '1',
            ]);
        } catch (ValidationException $e) {
            Log::warning('Thawani success callback could not complete payment', [
                'payment_id' => $payment->id,
                'errors' => $e->errors(),
            ]);

            return $this->returnToApp($request, '/payment/success', [
                'payment_id' => (string) $payment->id,
                'success' => '0',
                'status' => $payment->fresh()?->status?->value ?? 'processing',
            ]);
        }
    }

    public function cancel(Request $request): View|JsonResponse
    {
        $payment = $this->findPayment($request->integer('payment_id'));
        $query = ['cancelled' => '1'];

        if ($payment) {
            $this->jobs->cancelCheckout($payment);
            $query['payment_id'] = (string) $payment->id;
        }

        return $this->returnToApp($request, '/payment/cancel', $query, cancelled: true);
    }

    public function webhook(Request $request): JsonResponse
    {
        $sessionId = $request->input('session_id')
            ?? $request->input('payment_intent_id')
            ?? $request->input('order_id');

        if (! $sessionId) {
            return response()->json(['message' => 'Missing session identifier.'], 422);
        }

        $payment = Payment::query()->where('gateway_reference', (string) $sessionId)->first();

        if (! $payment) {
            return response()->json(['received' => true, 'message' => 'Payment not found.'], 404);
        }

        try {
            $this->jobs->completePaidQuotation($payment, $payment->payerOrganization?->users()->first());
        } catch (ValidationException) {
            // Pending or failed sessions are acknowledged so Thawani does not retry forever.
        }

        return response()->json(['received' => true]);
    }

    public function status(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYMENTS_VIEW), 403);

        $user = $request->user();
        $payment->loadMissing('quotation');
        if ($user->isCustomer() && $payment->payer_organization_id !== $user->organization_id) {
            abort(403);
        }
        if ($user->isProvider() && $payment->quotation?->provider_organization_id !== $user->organization_id) {
            abort(403);
        }

        $job = $payment->transportJob;

        if ($payment->status !== PaymentStatus::Completed) {
            try {
                $job = $this->jobs->completePaidQuotation($payment, $user);
                $payment = $payment->fresh();
            } catch (ValidationException) {
                $payment = $payment->fresh();
                $job = $payment?->transportJob;
            }
        }

        return ApiResponse::success([
            'status' => $payment?->status?->value,
            'payment' => $payment ? PaymentResource::make($payment)->resolve() : null,
            'job' => $job ? JobResource::make($job->load(['trips', 'shipmentRequest', 'quotation']))->resolve() : null,
        ]);
    }

    private function findPayment(int $paymentId): ?Payment
    {
        if ($paymentId < 1) {
            return null;
        }

        return Payment::query()->with(['quotation', 'payerOrganization.users'])->find($paymentId);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function returnToApp(Request $request, string $path, array $query = [], bool $cancelled = false): View|JsonResponse
    {
        $webLink = $this->frontendUrl($path, $query);
        $deepLink = $this->deepLink($path, $query);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => ! $cancelled,
                'redirect' => $deepLink,
                'web_redirect' => $webLink,
            ]);
        }

        return view('payments.return', [
            'title' => $cancelled ? 'Payment cancelled' : 'Payment completed',
            'body' => $cancelled
                ? 'You can return to the MoveX app to choose another payment method.'
                : 'Return to the MoveX app to continue with your job.',
            'button' => 'Return to the app',
            'deep_link' => $deepLink,
            'web_link' => $webLink,
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function frontendUrl(string $path, array $query = []): string
    {
        $base = rtrim((string) config('payment.frontend_url'), '/');
        $url = $base.$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function deepLink(string $path, array $query = []): string
    {
        $scheme = (string) config('payment.app_scheme', 'mzlogistics');
        $url = $scheme.'://'.$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }
}
