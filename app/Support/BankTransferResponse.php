<?php

namespace App\Support;

use App\Http\Resources\JobResource;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\TransportJob;
use App\Services\PlatformSettingService;

final class BankTransferResponse
{
    /**
     * @return array{requires_checkout: bool, awaiting_transfer: bool, payment: array<string, mixed>, bank_account: array<string, string>, job: array<string, mixed>|null}
     */
    public static function awaiting(Payment $payment, ?TransportJob $job = null): array
    {
        return [
            'requires_checkout' => false,
            'awaiting_transfer' => true,
            'payment' => PaymentResource::make($payment)->resolve(),
            'bank_account' => app(PlatformSettingService::class)->bankAccount(),
            'job' => $job ? JobResource::make($job)->resolve() : null,
        ];
    }
}
