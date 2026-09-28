<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('trip_rate', 12, 3)->nullable()->after('license_expires_at');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('driver_pay_amount', 12, 3)->nullable()->after('assigned_by');
        });

        Schema::create('driver_payables', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('trip_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('transport_job_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 3);
            $table->string('currency', 3)->default('OMR');
            $table->string('status')->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_payables');

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('driver_pay_amount');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('trip_rate');
        });
    }
};
