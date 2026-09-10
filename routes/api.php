<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/catalog', CatalogController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/register/customer', [AuthController::class, 'registerCustomer']);
        Route::post('/register/provider', [AuthController::class, 'registerProvider']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::patch('/me', [AuthController::class, 'updateMe']);
            Route::patch('/password', [AuthController::class, 'updatePassword']);
        });

        Route::get('/dashboard', DashboardController::class);

        Route::get('/shipments', [ShipmentController::class, 'index']);
        Route::post('/shipments', [ShipmentController::class, 'store']);
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show']);
        Route::put('/shipments/{shipment}', [ShipmentController::class, 'update']);
        Route::post('/shipments/{shipment}/publish', [ShipmentController::class, 'publish']);
        Route::post('/shipments/{shipment}/cancel', [ShipmentController::class, 'cancel']);
        Route::post('/shipments/{shipment}/quotations', [QuotationController::class, 'store']);

        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
        Route::post('/quotations/{quotation}/withdraw', [QuotationController::class, 'withdraw']);
        Route::post('/quotations/{quotation}/accept', [QuotationController::class, 'accept']);

        Route::get('/jobs', [JobController::class, 'index']);
        Route::get('/jobs/{job}', [JobController::class, 'show']);

        Route::get('/trips', [TripController::class, 'index']);
        Route::get('/trips/{trip}', [TripController::class, 'show']);
        Route::post('/trips/{trip}/assign', [TripController::class, 'assign']);
        Route::post('/trips/{trip}/status', [TripController::class, 'updateStatus']);
        Route::post('/trips/{trip}/location', [TripController::class, 'location']);
        Route::post('/trips/{trip}/pod', [TripController::class, 'storePod']);

        Route::get('/trucks', [FleetController::class, 'trucks']);
        Route::post('/trucks', [FleetController::class, 'storeTruck']);
        Route::put('/trucks/{truck}', [FleetController::class, 'updateTruck']);
        Route::get('/equipment', [FleetController::class, 'equipment']);
        Route::post('/equipment', [FleetController::class, 'storeEquipment']);
        Route::get('/drivers', [FleetController::class, 'drivers']);
        Route::post('/drivers', [FleetController::class, 'storeDriver']);

        Route::get('/customers', [OrganizationController::class, 'customers']);
        Route::get('/providers', [OrganizationController::class, 'providers']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::post('/organizations/{organization}/verify', [OrganizationController::class, 'verify']);

        Route::get('/payments', [FinanceController::class, 'payments']);
        Route::get('/invoices', [FinanceController::class, 'invoices']);
        Route::get('/settlements', [FinanceController::class, 'settlements']);
        Route::post('/settlements', [FinanceController::class, 'storeSettlement']);
        Route::post('/settlements/{settlement}/complete', [FinanceController::class, 'completeSettlement']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/password', [UserController::class, 'updatePassword']);

        Route::get('/roles', [RoleController::class, 'index']);
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->where('role', '.*');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    });
});
