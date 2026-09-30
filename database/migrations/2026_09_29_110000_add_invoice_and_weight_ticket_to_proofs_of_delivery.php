<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proofs_of_delivery', function (Blueprint $table) {
            $table->string('invoice_path')->nullable()->after('document_path');
            $table->string('weight_ticket_path')->nullable()->after('invoice_path');
        });
    }

    public function down(): void
    {
        Schema::table('proofs_of_delivery', function (Blueprint $table) {
            $table->dropColumn(['invoice_path', 'weight_ticket_path']);
        });
    }
};
