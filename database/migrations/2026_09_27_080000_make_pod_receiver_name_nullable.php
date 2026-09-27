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

        Schema::table('proofs_of_delivery', function (Blueprint $table) {
            $table->string('receiver_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('proofs_of_delivery', function (Blueprint $table) {
            $table->string('receiver_name')->nullable(false)->change();
        });
    }
};
