<?php

namespace Database\Seeders;

use App\Enums\PaymentMethodProcessor;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'code' => 'thawani',
                'name' => 'Thawani',
                'name_ar' => 'ثواني',
                'processor' => PaymentMethodProcessor::Thawani,
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'cash',
                'name' => 'Cash',
                'name_ar' => 'كاش',
                'processor' => PaymentMethodProcessor::Cash,
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 2,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $method['code']],
                $method,
            );
        }
    }
}
