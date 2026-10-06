<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\DriverStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\PaymentContractService;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('email', 'superadmin@mzlogistics.om')->exists()) {
            return;
        }

        $password = 'Password123!';

        $platformUsers = [
            ['name' => 'Maha Al Busaidi', 'email' => 'superadmin@mzlogistics.om', 'role' => 'Super Admin'],
            ['name' => 'Salim Al Harthy', 'email' => 'operations@mzlogistics.om', 'role' => 'Operations Manager'],
            ['name' => 'Aisha Al Lawati', 'email' => 'finance@mzlogistics.om', 'role' => 'Finance Manager'],
            ['name' => 'Huda Al Zadjali', 'email' => 'support@mzlogistics.om', 'role' => 'Customer Support'],
        ];

        foreach ($platformUsers as $item) {
            $user = User::query()->create([
                'name' => $item['name'],
                'email' => $item['email'],
                'password' => $password,
                'phone' => '+968 2400 1000',
                'locale' => 'ar',
                'user_type' => UserType::Platform,
                'is_active' => true,
            ]);
            $user->assignRole($item['role']);
        }

        $customerOrg = Organization::query()->create([
            'type' => OrganizationType::Customer,
            'account_type' => AccountType::Company,
            'name' => 'Gulf Materials Trading',
            'name_ar' => 'تجارة مواد الخليج',
            'email' => 'ops@gulfmaterials.om',
            'phone' => '+968 2456 1100',
            'city' => 'Muscat',
            'country' => 'OM',
            'address' => 'Ghala Industrial Area',
            'status' => OrganizationStatus::Active,
        ]);

        $customer = User::query()->create([
            'name' => 'Nasser Al Hinai',
            'email' => 'customer@gulfmaterials.om',
            'password' => $password,
            'phone' => '+968 9900 2211',
            'locale' => 'ar',
            'user_type' => UserType::Customer,
            'organization_id' => $customerOrg->id,
            'is_active' => true,
        ]);
        $customer->assignRole('Company Admin');
        app(PaymentContractService::class)->ensureForCustomer($customerOrg);

        $providerOrg = Organization::query()->create([
            'type' => OrganizationType::Provider,
            'account_type' => AccountType::Company,
            'name' => 'Oman Haulers',
            'name_ar' => 'ناقلات عُمان',
            'commercial_register' => 'CR-112233',
            'tax_number' => 'OM-TAX-7788',
            'email' => 'dispatch@omanhaulers.om',
            'phone' => '+968 2447 3300',
            'city' => 'Sohar',
            'country' => 'OM',
            'address' => 'Sohar Port Freezone',
            'status' => OrganizationStatus::Active,
        ]);

        $provider = User::query()->create([
            'name' => 'Yousuf Al Balushi',
            'email' => 'provider@omanhaulers.om',
            'password' => $password,
            'phone' => '+968 9911 4400',
            'locale' => 'ar',
            'user_type' => UserType::Provider,
            'organization_id' => $providerOrg->id,
            'is_active' => true,
        ]);
        $provider->assignRole('Provider Admin');

        $driver = User::query()->create([
            'name' => 'Khalid Al Mamari',
            'email' => 'driver@omanhaulers.om',
            'password' => $password,
            'phone' => '+968 9922 5500',
            'locale' => 'ar',
            'user_type' => UserType::Driver,
            'organization_id' => $providerOrg->id,
            'is_active' => true,
        ]);
        $driver->assignRole('Driver');
        DriverProfile::query()->create([
            'user_id' => $driver->id,
            'organization_id' => $providerOrg->id,
            'license_number' => 'OM-DL-458821',
            'license_expires_at' => now()->addYears(3),
            'status' => DriverStatus::Available,
        ]);
    }
}
