<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shipment_requests')->whereIn('quantity_unit', ['ton', 'TON', 'Tons'])->update(['quantity_unit' => 'tons']);
        DB::table('shipment_requests')->where('quantity_unit', 'pallet')->update(['quantity_unit' => 'pallets']);
        DB::table('shipment_requests')->where('quantity_unit', 'unit')->update(['quantity_unit' => 'units']);
    }

    public function down(): void
    {
        DB::table('shipment_requests')->where('quantity_unit', 'tons')->update(['quantity_unit' => 'ton']);
        DB::table('shipment_requests')->where('quantity_unit', 'pallets')->update(['quantity_unit' => 'pallet']);
        DB::table('shipment_requests')->where('quantity_unit', 'units')->update(['quantity_unit' => 'unit']);
    }
};
