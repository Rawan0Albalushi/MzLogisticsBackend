<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $types = [
            ['code' => 'flatbed', 'name' => 'Flatbed', 'name_ar' => 'سطحة', 'sort_order' => 1],
            ['code' => 'box', 'name' => 'Box', 'name_ar' => 'صندوق', 'sort_order' => 2],
            ['code' => 'reefer', 'name' => 'Reefer', 'name_ar' => 'مبرد', 'sort_order' => 3],
            ['code' => 'tanker', 'name' => 'Tanker', 'name_ar' => 'صهريج', 'sort_order' => 4],
            ['code' => 'lowbed', 'name' => 'Lowbed', 'name_ar' => 'لودبد', 'sort_order' => 5],
            ['code' => 'dump', 'name' => 'Dump', 'name_ar' => 'قلاب', 'sort_order' => 6],
            ['code' => 'curtain', 'name' => 'Curtain', 'name_ar' => 'ستائري', 'sort_order' => 7],
        ];

        DB::table('truck_types')->insert(array_map(
            fn (array $type) => [
                ...$type,
                'organization_id' => null,
                'is_active' => true,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $types,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('truck_types');
    }
};
