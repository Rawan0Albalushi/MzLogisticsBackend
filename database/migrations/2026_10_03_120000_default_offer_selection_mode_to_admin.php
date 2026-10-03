<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $updated = DB::table('platform_settings')
            ->where('key', 'offer_selection_mode')
            ->update([
                'value' => 'admin',
                'updated_at' => $now,
            ]);

        if ($updated === 0 && ! DB::table('platform_settings')->where('key', 'offer_selection_mode')->exists()) {
            DB::table('platform_settings')->insert([
                'key' => 'offer_selection_mode',
                'value' => 'admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')
            ->where('key', 'offer_selection_mode')
            ->update([
                'value' => 'customer',
                'updated_at' => now(),
            ]);
    }
};
