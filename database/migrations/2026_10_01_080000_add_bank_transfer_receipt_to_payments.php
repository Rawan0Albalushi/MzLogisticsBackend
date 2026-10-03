<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('gateway_payload');
            $table->string('transfer_reference', 64)->nullable()->after('receipt_path');
            $table->foreignId('confirmed_by')->nullable()->after('transfer_reference')->constrained('users')->nullOnDelete();
        });

        $exists = DB::table('payment_methods')->where('code', 'bank_transfer')->exists();
        if (! $exists) {
            DB::table('payment_methods')->insert([
                'code' => 'bank_transfer',
                'name' => 'Bank transfer',
                'name_ar' => 'تحويل بنكي',
                'processor' => 'bank_transfer',
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['receipt_path', 'transfer_reference']);
        });

        DB::table('payment_methods')->where('code', 'bank_transfer')->where('is_system', true)->delete();
    }
};
