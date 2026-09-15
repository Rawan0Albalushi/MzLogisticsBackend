<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payment_contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('due_days')->default(0)->change();
            $table->unsignedSmallInteger('pending_due_days')->nullable()->change();
        });

        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_due_days')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('payment_contracts', function (Blueprint $table) {
            $table->unsignedTinyInteger('due_days')->default(0)->change();
            $table->unsignedTinyInteger('pending_due_days')->nullable()->change();
        });

        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_due_days')->nullable()->change();
        });
    }
};
