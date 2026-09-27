<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('scheduled_departure_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('scheduled_departure_at');
        });
    }
};
