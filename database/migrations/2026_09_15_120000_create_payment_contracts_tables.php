<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('billing_trigger')->default('on_award');
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->string('pending_billing_trigger')->nullable();
            $table->unsignedSmallInteger('pending_due_days')->nullable();
            $table->string('pending_status')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->foreignId('payment_contract_id')->nullable()->after('awarded_quotation_id')->constrained('payment_contracts')->nullOnDelete();
            $table->string('payment_billing_trigger')->nullable()->after('payment_contract_id');
            $table->unsignedSmallInteger('payment_due_days')->nullable()->after('payment_billing_trigger');
        });

        $now = now();
        $customerIds = DB::table('organizations')->where('type', 'customer')->pluck('id');
        foreach ($customerIds as $organizationId) {
            DB::table('payment_contracts')->insert([
                'organization_id' => $organizationId,
                'billing_trigger' => 'on_award',
                'due_days' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('shipment_requests')
            ->whereNull('payment_billing_trigger')
            ->update([
                'payment_billing_trigger' => 'on_award',
                'payment_due_days' => 0,
            ]);
    }

    public function down(): void
    {
        Schema::table('shipment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_contract_id');
            $table->dropColumn(['payment_billing_trigger', 'payment_due_days']);
        });

        Schema::dropIfExists('payment_contracts');
    }
};
