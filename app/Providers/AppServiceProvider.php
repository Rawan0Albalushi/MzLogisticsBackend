<?php

namespace App\Providers;

use App\Models\Quotation;
use App\Models\ShipmentRequest;
use App\Models\TransportJob;
use App\Models\Trip;
use App\Policies\QuotationPolicy;
use App\Policies\ShipmentRequestPolicy;
use App\Policies\TransportJobPolicy;
use App\Policies\TripPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Gate::policy(ShipmentRequest::class, ShipmentRequestPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(TransportJob::class, TransportJobPolicy::class);
        Gate::policy(Trip::class, TripPolicy::class);

        Route::bind('shipment', fn (string $value) => ShipmentRequest::query()->findOrFail($value));
        Route::bind('job', fn (string $value) => TransportJob::query()->findOrFail($value));
    }
}
