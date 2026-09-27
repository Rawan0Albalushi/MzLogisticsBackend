<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_id')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
        });

        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('reference')
                ->constrained('projects')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transport_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::dropIfExists('projects');
    }
};
