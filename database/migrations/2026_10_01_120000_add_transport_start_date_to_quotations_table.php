<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->date('transport_start_date')->nullable()->after('duration_days');
        });

        $requiredDates = DB::table('shipment_requests')->pluck('required_date', 'id');

        DB::table('quotations')->orderBy('id')->chunkById(200, function ($rows) use ($requiredDates) {
            foreach ($rows as $row) {
                $required = $requiredDates[$row->shipment_request_id] ?? null;
                if ($required) {
                    DB::table('quotations')->where('id', $row->id)->update([
                        'transport_start_date' => $required,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('transport_start_date');
        });
    }
};
