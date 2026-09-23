<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FleetController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaymentContractController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PlacesController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TruckTypeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/catalog', CatalogController::class);

    Route::get('/payments/success', [PaymentController::class, 'success'])->name('payment.success');
    Route::get('/payments/cancel', [PaymentController::class, 'cancel'])->name('payment.cancel');
    Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payment.webhook');

    Route::prefix('auth')->group(function (): void {
        Route::post('/register/customer', [AuthController::class, 'registerCustomer']);
        Route::post('/register/provider', [AuthController::class, 'registerProvider']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/driver/activate', [AuthController::class, 'activateDriver']);
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

        Route::get('/places/autocomplete', [PlacesController::class, 'autocomplete']);
        Route::get('/places/details', [PlacesController::class, 'details']);
        Route::get('/places/reverse', [PlacesController::class, 'reverse']);

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
        Route::get('/trips/{trip}/pod/photos/{index}', [TripController::class, 'podPhoto'])->whereNumber('index');
        Route::get('/trips/{trip}/pod/signature', [TripController::class, 'podSignature']);

        Route::get('/truck-types', [TruckTypeController::class, 'index']);
        Route::post('/truck-types', [TruckTypeController::class, 'store']);
        Route::patch('/truck-types/{truck_type}', [TruckTypeController::class, 'update']);
        Route::delete('/truck-types/{truck_type}', [TruckTypeController::class, 'destroy']);

        Route::get('/trucks', [FleetController::class, 'trucks']);
        Route::post('/trucks', [FleetController::class, 'storeTruck']);
        Route::get('/trucks/import-template', [FleetController::class, 'truckImportTemplate']);
        Route::post('/trucks/import', [FleetController::class, 'importTrucks']);
        Route::put('/trucks/{truck}', [FleetController::class, 'updateTruck']);
        Route::post('/trucks/{truck}/documents', [DocumentController::class, 'storeForTruck']);
        Route::get('/equipment', [FleetController::class, 'equipment']);
        Route::post('/equipment', [FleetController::class, 'storeEquipment']);
        Route::get('/equipment/import-template', [FleetController::class, 'equipmentImportTemplate']);
        Route::post('/equipment/import', [FleetController::class, 'importEquipment']);
        Route::put('/equipment/{equipment}', [FleetController::class, 'updateEquipment']);
        Route::get('/drivers', [FleetController::class, 'drivers']);
        Route::post('/drivers', [FleetController::class, 'storeDriver']);
        Route::get('/drivers/import-template', [FleetController::class, 'importTemplate']);
        Route::post('/drivers/import', [FleetController::class, 'importDrivers']);
        Route::put('/drivers/{driver}', [FleetController::class, 'updateDriver']);
        Route::post('/drivers/{driver}/resend-invite', [FleetController::class, 'resendInvite']);
        Route::post('/drivers/{driver}/documents', [DocumentController::class, 'storeForDriver']);

        Route::get('/customers', [OrganizationController::class, 'customers']);
        Route::get('/providers', [OrganizationController::class, 'providers']);
        Route::get('/documents/{document}/file', [DocumentController::class, 'download']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::post('/organizations/{organization}/documents', [DocumentController::class, 'storeForOrganization']);
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::patch('/organizations/{organization}/commission-rate', [OrganizationController::class, 'updateCommissionRate']);
        Route::post('/organizations/{organization}/verify', [OrganizationController::class, 'verify']);

        Route::get('/payments', [FinanceController::class, 'payments']);
        Route::get('/payments/{payment}/status', [PaymentController::class, 'status']);
        Route::get('/payment-contract', [PaymentContractController::class, 'mine']);
        Route::get('/payment-contracts', [PaymentContractController::class, 'index']);
        Route::get('/organizations/{organization}/payment-contract', [PaymentContractController::class, 'show']);
        Route::put('/organizations/{organization}/payment-contract', [PaymentContractController::class, 'update']);
        Route::post('/organizations/{organization}/payment-contract/approve', [PaymentContractController::class, 'approve']);
        Route::post('/organizations/{organization}/payment-contract/reject', [PaymentContractController::class, 'reject']);
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
        Route::patch('/payment-methods/{payment_method}', [PaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{payment_method}', [PaymentMethodController::class, 'destroy']);
        Route::get('/invoices', [FinanceController::class, 'invoices']);
        Route::post('/invoices/{invoice}/pay', [FinanceController::class, 'payInvoice']);
        Route::get('/settlements', [FinanceController::class, 'settlements']);
        Route::post('/settlements', [FinanceController::class, 'storeSettlement']);
        Route::post('/settlements/request', [FinanceController::class, 'requestSettlement']);
        Route::post('/settlements/{settlement}/complete', [FinanceController::class, 'completeSettlement']);
        Route::get('/wallets', [WalletController::class, 'index']);
        Route::get('/wallets/{wallet}', [WalletController::class, 'show']);
        Route::get('/wallets/{wallet}/transactions', [WalletController::class, 'transactions']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/password', [UserController::class, 'updatePassword']);

        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->where('role', '.*');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->where('role', '.*');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    });
});
