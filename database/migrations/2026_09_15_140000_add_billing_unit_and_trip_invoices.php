<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_contracts', 'billing_unit')) {
            Schema::table('payment_contracts', function (Blueprint $table) {
                $table->string('billing_unit')->default('job')->after('due_days');
            });
        }

        if (! Schema::hasColumn('payment_contracts', 'pending_billing_unit')) {
            Schema::table('payment_contracts', function (Blueprint $table) {
                $table->string('pending_billing_unit')->nullable()->after('pending_due_days');
            });
        }

        if (! Schema::hasColumn('shipment_requests', 'payment_billing_unit')) {
            Schema::table('shipment_requests', function (Blueprint $table) {
                $table->string('payment_billing_unit')->nullable()->after('payment_due_days');
            });
        }

        if (! Schema::hasColumn('invoices', 'trip_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('trip_id')->nullable()->after('transport_job_id')->constrained('trips')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('payments', 'invoice_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['quotation_id']);
                $table->dropUnique(['quotation_id']);
                $table->foreign('quotation_id')->references('id')->on('quotations')->restrictOnDelete();
                $table->foreignId('invoice_id')->nullable()->after('quotation_id')->constrained('invoices')->nullOnDelete();
                $table->unique('invoice_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'invoice_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('invoice_id');
                $table->dropForeign(['quotation_id']);
                $table->unique('quotation_id');
                $table->foreign('quotation_id')->references('id')->on('quotations')->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('invoices', 'trip_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('trip_id');
            });
        }

        if (Schema::hasColumn('shipment_requests', 'payment_billing_unit')) {
            Schema::table('shipment_requests', function (Blueprint $table) {
                $table->dropColumn('payment_billing_unit');
            });
        }

        if (Schema::hasColumn('payment_contracts', 'pending_billing_unit')) {
            Schema::table('payment_contracts', function (Blueprint $table) {
                $table->dropColumn('pending_billing_unit');
            });
        }

        if (Schema::hasColumn('payment_contracts', 'billing_unit')) {
            Schema::table('payment_contracts', function (Blueprint $table) {
                $table->dropColumn('billing_unit');
            });
        }
    }
};
