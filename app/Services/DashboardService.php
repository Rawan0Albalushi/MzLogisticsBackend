<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SettlementStatus;
use App\Enums\ShipmentStatus;
use App\Enums\TripStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Settlement;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $jobs = TransportJob::query()->when(
            $user->isCustomer(),
            fn (Builder $q) => $q->where('customer_organization_id', $user->organization_id)
        )->when(
            $user->isProvider(),
            fn (Builder $q) => $q->where('provider_organization_id', $user->organization_id)
        )->when(
            $user->isDriver(),
            fn (Builder $q) => $q->whereHas('trips', fn (Builder $trips) => $trips->where('driver_user_id', $user->id))
        );

        $trips = Trip::query()->when(
            $user->isCustomer(),
            fn (Builder $q) => $q->whereHas('transportJob', fn (Builder $job) => $job->where('customer_organization_id', $user->organization_id))
        )->when(
            $user->isProvider(),
            fn (Builder $q) => $q->whereHas('transportJob', fn (Builder $job) => $job->where('provider_organization_id', $user->organization_id))
        )->when(
            $user->isDriver(),
            fn (Builder $q) => $q->where('driver_user_id', $user->id)
        );

        $shipments = ShipmentRequest::query()->when(
            $user->isCustomer(),
            fn (Builder $q) => $q->where('customer_organization_id', $user->organization_id)
        )->when(
            $user->isProvider(),
            fn (Builder $q) => $q->where('status', ShipmentStatus::Published)
        );

        $payments = Payment::query()->when(
            $user->isCustomer(),
            fn (Builder $q) => $q->where('payer_organization_id', $user->organization_id)
        )->when(
            $user->isProvider(),
            fn (Builder $q) => $q->whereHas('quotation', fn (Builder $quotation) => $quotation->where('provider_organization_id', $user->organization_id))
        );

        $openTrips = (clone $trips)->whereNotIn('status', [TripStatus::Completed, TripStatus::Cancelled])->count();

        $invoices = Invoice::query()->when(
            ! $user->isPlatform(),
            fn (Builder $q) => $q->where('organization_id', $user->organization_id)
        );

        $settlements = Settlement::query()
            ->when($user->isProvider(), fn (Builder $q) => $q->where('provider_organization_id', $user->organization_id))
            ->when($user->isCustomer() || $user->isDriver(), fn (Builder $q) => $q->whereRaw('1 = 0'));

        $wallets = Wallet::query()
            ->when($user->isProvider(), fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->when($user->isCustomer() || $user->isDriver(), fn (Builder $q) => $q->whereRaw('1 = 0'));

        $walletPending = (float) (clone $wallets)->sum('pending_balance');
        $walletAvailable = (float) (clone $wallets)->sum('available_balance');
        $walletReserved = (float) (clone $wallets)->sum('reserved_balance');

        return [
            'shipments_open' => (clone $shipments)->where('status', ShipmentStatus::Published)->count(),
            'shipments_total' => (clone $shipments)->count(),
            'quotations_pending' => Quotation::query()
                ->when($user->isCustomer(), fn (Builder $q) => $q->whereHas('shipmentRequest', fn (Builder $s) => $s->where('customer_organization_id', $user->organization_id)))
                ->when($user->isProvider(), fn (Builder $q) => $q->where('provider_organization_id', $user->organization_id))
                ->where('status', QuotationStatus::Submitted)
                ->count(),
            'jobs_active' => (clone $jobs)->whereIn('status', [JobStatus::PendingDispatch, JobStatus::InProgress])->count(),
            'jobs_pending_dispatch' => (clone $jobs)->where('status', JobStatus::PendingDispatch)->count(),
            'jobs_completed' => (clone $jobs)->where('status', JobStatus::Completed)->count(),
            'trips_active' => $openTrips,
            'trips_unassigned' => (clone $trips)->where('status', TripStatus::Unassigned)->count(),
            'trips_in_transit' => (clone $trips)->where('status', TripStatus::InTransit)->count(),
            'payments_pending' => (clone $payments)->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])->count(),
            'payments_completed_amount' => (float) (clone $payments)->where('status', PaymentStatus::Completed)->sum('amount'),
            'commission_amount' => (float) (clone $payments)->where('status', PaymentStatus::Completed)->sum('commission_amount'),
            'wallet_pending' => $walletPending,
            'wallet_available' => $walletAvailable,
            'wallet_reserved' => $walletReserved,
            'provider_receivable' => round($walletPending + $walletAvailable + $walletReserved, 3),
            'settlements_pending' => (clone $settlements)->whereIn('status', [SettlementStatus::Pending, SettlementStatus::Processing])->count(),
            'invoices_count' => (clone $invoices)->count(),
            'invoices_unpaid' => (clone $invoices)->where('status', InvoiceStatus::Issued)->count(),
            'providers_pending' => $user->isPlatform()
                ? Organization::query()->where('type', OrganizationType::Provider)->where('status', OrganizationStatus::Pending)->count()
                : 0,
            'customers_pending' => $user->isPlatform()
                ? Organization::query()->where('type', OrganizationType::Customer)->where('status', OrganizationStatus::Pending)->count()
                : 0,
        ];
    }
}
