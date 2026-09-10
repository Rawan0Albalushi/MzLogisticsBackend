<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('account_type', 32)->default('company');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('commercial_register')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('OM');
            $table->text('address')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('verification_notes')->nullable();
            $table->decimal('commission_rate', 5, 4)->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('license_number')->nullable();
            $table->date('license_expires_at')->nullable();
            $table->string('status', 32)->default('available');
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number');
            $table->string('type', 32);
            $table->decimal('capacity_tons', 8, 2);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('status', 32)->default('available');
            $table->foreignId('assigned_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('insurance_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'plate_number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 32)->default('available');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('type', 64);
            $table->string('title');
            $table->string('file_path');
            $table->date('expires_at')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('cargo_type');
            $table->text('cargo_description')->nullable();
            $table->decimal('weight_tons', 10, 2);
            $table->decimal('volume_cbm', 10, 2)->nullable();
            $table->decimal('quantity', 10, 2);
            $table->string('quantity_unit')->default('ton');
            $table->string('pickup_address');
            $table->string('pickup_city');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->string('delivery_address');
            $table->string('delivery_city');
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->date('required_date');
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('awarded_quotation_id')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['customer_organization_id', 'status']);
            $table->index(['status', 'required_date']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('shipment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('total_price', 12, 3);
            $table->string('currency', 8)->default('OMR');
            $table->unsignedInteger('truck_count');
            $table->string('truck_type', 32);
            $table->decimal('truck_capacity_tons', 8, 2);
            $table->unsignedInteger('trip_count');
            $table->decimal('quantity_per_trip', 10, 2);
            $table->unsignedInteger('duration_days');
            $table->decimal('additional_costs', 12, 3)->default(0);
            $table->text('conditions')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 32)->default('submitted');
            $table->timestamps();

            $table->unique(['shipment_request_id', 'provider_organization_id']);
            $table->index(['provider_organization_id', 'status']);
        });

        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->foreign('awarded_quotation_id')->references('id')->on('quotations')->nullOnDelete();
        });

        Schema::create('transport_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('shipment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('provider_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('total_price', 12, 3);
            $table->decimal('total_quantity', 10, 2);
            $table->decimal('delivered_quantity', 10, 2)->default(0);
            $table->string('currency', 8)->default('OMR');
            $table->string('status', 32)->default('pending_dispatch');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_organization_id', 'status']);
            $table->index(['provider_organization_id', 'status']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('transport_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->foreignId('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('planned_quantity', 10, 2);
            $table->decimal('delivered_quantity', 10, 2)->default(0);
            $table->string('status', 32)->default('unassigned');
            $table->string('pickup_address');
            $table->string('pickup_city');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();
            $table->string('delivery_address');
            $table->string('delivery_city');
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('eta_at')->nullable();
            $table->string('otp_code', 8)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('arrived_pickup_at')->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('in_transit_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['transport_job_id', 'status']);
            $table->index(['driver_user_id', 'status']);
            $table->index(['truck_id', 'status']);
        });

        Schema::create('trip_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['trip_id', 'recorded_at']);
        });

        Schema::create('proofs_of_delivery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('receiver_name');
            $table->boolean('otp_verified')->default(false);
            $table->json('photo_paths')->nullable();
            $table->decimal('received_quantity', 10, 2);
            $table->string('signature_path')->nullable();
            $table->string('document_path')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('idempotency_key')->unique();
            $table->foreignId('shipment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payer_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->decimal('commission_amount', 12, 3);
            $table->decimal('provider_amount', 12, 3);
            $table->string('currency', 8)->default('OMR');
            $table->string('method', 32)->default('card');
            $table->string('status', 32)->default('pending');
            $table->string('gateway', 32)->default('sandbox');
            $table->string('gateway_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('transport_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 12, 3);
            $table->string('currency', 8)->default('OMR');
            $table->string('status', 32)->default('issued');
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type', 'status']);
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('provider_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->decimal('commission_amount', 12, 3);
            $table->decimal('net_amount', 12, 3);
            $table->string('currency', 8)->default('OMR');
            $table->string('status', 32)->default('pending');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('proofs_of_delivery');
        Schema::dropIfExists('trip_locations');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('transport_jobs');
        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->dropForeign(['awarded_quotation_id']);
        });
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('shipment_requests');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('trucks');
        Schema::dropIfExists('driver_profiles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });
        Schema::dropIfExists('organizations');
    }
};
