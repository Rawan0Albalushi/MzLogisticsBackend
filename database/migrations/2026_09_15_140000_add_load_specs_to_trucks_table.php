<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->decimal('volume_cbm', 10, 2)->nullable()->after('capacity_tons');
            $table->decimal('cargo_length_m', 8, 2)->nullable()->after('volume_cbm');
            $table->decimal('cargo_width_m', 8, 2)->nullable()->after('cargo_length_m');
            $table->decimal('cargo_height_m', 8, 2)->nullable()->after('cargo_width_m');
            $table->unsignedTinyInteger('axle_count')->nullable()->after('cargo_height_m');
        });
    }

    public function down(): void
    {
        Schema::table('trucks', function (Blueprint $table) {
            $table->dropColumn([
                'volume_cbm',
                'cargo_length_m',
                'cargo_width_m',
                'cargo_height_m',
                'axle_count',
            ]);
        });
    }
};
