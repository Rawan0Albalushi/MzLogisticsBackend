<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('currency', 8)->default('OMR');
            $table->decimal('pending_balance', 12, 3)->default(0);
            $table->decimal('available_balance', 12, 3)->default(0);
            $table->decimal('reserved_balance', 12, 3)->default(0);
            $table->decimal('lifetime_earned', 12, 3)->default(0);
            $table->decimal('lifetime_withdrawn', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('idempotency_key')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 12, 3);
            $table->decimal('pending_delta', 12, 3)->default(0);
            $table->decimal('available_delta', 12, 3)->default(0);
            $table->decimal('reserved_delta', 12, 3)->default(0);
            $table->string('currency', 8)->default('OMR');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transport_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
