<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('trailer_plate', 32)->nullable()->after('driver_pay_amount');
            $table->string('delivery_note_number', 40)->nullable()->after('trailer_plate');
            $table->text('operations_notes')->nullable()->after('delivery_note_number');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('civil_id', 20)->nullable()->unique()->after('license_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropUnique(['civil_id']);
            $table->dropColumn('civil_id');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['trailer_plate', 'delivery_note_number', 'operations_notes']);
        });
    }
};
