<?php

use App\Enums\AccountType;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('organizations')->where('type', OrganizationType::Platform->value)->exists();
        if ($exists) {
            return;
        }

        $now = now();
        DB::table('organizations')->insert([
            'type' => OrganizationType::Platform->value,
            'account_type' => AccountType::Company->value,
            'name' => 'MoveX',
            'name_ar' => 'المنصة',
            'country' => 'OM',
            'status' => OrganizationStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('organizations')->where('type', OrganizationType::Platform->value)->delete();
    }
};
