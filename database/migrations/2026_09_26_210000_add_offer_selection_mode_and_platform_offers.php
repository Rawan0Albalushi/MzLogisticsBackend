<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });

        DB::table('platform_settings')->insert([
            'key' => 'offer_selection_mode',
            'value' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->string('offer_selection_mode', 20)->default('customer')->after('status');
        });

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->decimal('provider_price', 12, 3)->nullable()->after('total_price');
        });

        Schema::create('platform_offers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('shipment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('provider_price', 12, 3);
            $table->decimal('customer_price', 12, 3);
            $table->decimal('margin_amount', 12, 3);
            $table->string('currency', 8)->default('OMR');
            $table->unsignedInteger('truck_count');
            $table->string('truck_type', 32);
            $table->decimal('truck_capacity_tons', 8, 2);
            $table->unsignedInteger('trip_count');
            $table->decimal('quantity_per_trip', 10, 2);
            $table->unsignedInteger('duration_days');
            $table->text('conditions')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status', 32)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['shipment_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_offers');

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropColumn('provider_price');
        });

        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->dropColumn('offer_selection_mode');
        });

        Schema::dropIfExists('platform_settings');
    }
};
