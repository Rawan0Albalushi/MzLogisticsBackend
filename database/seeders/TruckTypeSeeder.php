<?php

namespace Database\Seeders;

use App\Models\TruckType;
use Illuminate\Database\Seeder;

class TruckTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'flatbed', 'name' => 'Flatbed', 'name_ar' => 'سطحة', 'sort_order' => 1],
            ['code' => 'box', 'name' => 'Box', 'name_ar' => 'صندوق', 'sort_order' => 2],
            ['code' => 'reefer', 'name' => 'Reefer', 'name_ar' => 'مبرد', 'sort_order' => 3],
            ['code' => 'tanker', 'name' => 'Tanker', 'name_ar' => 'صهريج', 'sort_order' => 4],
            ['code' => 'lowbed', 'name' => 'Lowbed', 'name_ar' => 'لودبد', 'sort_order' => 5],
            ['code' => 'dump', 'name' => 'Dump', 'name_ar' => 'قلاب', 'sort_order' => 6],
            ['code' => 'curtain', 'name' => 'Curtain', 'name_ar' => 'ستائري', 'sort_order' => 7],
        ];

        foreach ($types as $type) {
            TruckType::query()->updateOrCreate(
                ['code' => $type['code']],
                [
                    ...$type,
                    'organization_id' => null,
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
        }
    }
}
