<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\DriverStatus;
use App\Enums\EquipmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TripStatus;
use App\Enums\TruckStatus;
use App\Enums\TruckType;
use App\Enums\UserType;
use App\Models\DriverProfile;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Truck;
use App\Models\User;
use App\Services\JobOrchestrationService;
use App\Services\PaymentContractService;
use App\Services\QuotationService;
use App\Services\ShipmentService;
use App\Services\TripService;
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

        $truck = Truck::query()->create([
            'organization_id' => $providerOrg->id,
            'plate_number' => 'H 45882',
            'type' => TruckType::Flatbed->value,
            'capacity_tons' => 30,
            'volume_cbm' => 86,
            'cargo_length_m' => 13.6,
            'cargo_width_m' => 2.45,
            'cargo_height_m' => 2.70,
            'axle_count' => 4,
            'year' => 2022,
            'make' => 'Mercedes-Benz',
            'model' => 'Actros',
            'status' => TruckStatus::Available,
            'insurance_expires_at' => now()->addYear(),
        ]);

        Truck::query()->create([
            'organization_id' => $providerOrg->id,
            'plate_number' => 'H 77120',
            'type' => TruckType::Box->value,
            'capacity_tons' => 18,
            'volume_cbm' => 48,
            'cargo_length_m' => 7.2,
            'cargo_width_m' => 2.40,
            'cargo_height_m' => 2.50,
            'axle_count' => 3,
            'year' => 2021,
            'make' => 'Volvo',
            'model' => 'FH',
            'status' => TruckStatus::Available,
            'insurance_expires_at' => now()->addMonths(8),
        ]);

        Equipment::query()->create([
            'organization_id' => $providerOrg->id,
            'name' => '20ft container twist locks',
            'type' => 'securing',
            'quantity' => 12,
            'status' => EquipmentStatus::Available,
        ]);

        Equipment::query()->create([
            'organization_id' => $providerOrg->id,
            'truck_id' => $truck->id,
            'name' => 'Truck-mounted crane',
            'type' => 'lifting',
            'quantity' => 1,
            'status' => EquipmentStatus::Available,
        ]);

        $shipmentService = app(ShipmentService::class);
        $quotationService = app(QuotationService::class);
        $jobService = app(JobOrchestrationService::class);
        $tripService = app(TripService::class);

        $openShipment = $shipmentService->create($customer, [
            'cargo_type' => 'Construction steel',
            'cargo_description' => 'Rebar bundles for a coastal project',
            'weight_tons' => 48,
            'volume_cbm' => 32,
            'quantity' => 48,
            'quantity_unit' => 'tons',
            'pickup_address' => 'Sohar Port, Gate 4',
            'pickup_city' => 'Sohar',
            'pickup_lat' => 24.3471,
            'pickup_lng' => 56.7094,
            'delivery_address' => 'Duqm Special Economic Zone',
            'delivery_city' => 'Duqm',
            'delivery_lat' => 19.6620,
            'delivery_lng' => 57.7040,
            'required_date' => now()->addDays(5)->toDateString(),
            'notes' => 'Require tarpaulin cover and two-day delivery window.',
            'publish' => true,
        ]);

        $quotationService->submit($provider, $openShipment, [
            'total_price' => 1860,
            'truck_count' => 2,
            'truck_type' => TruckType::Flatbed->value,
            'truck_capacity_tons' => 30,
            'trip_count' => 2,
            'quantity_per_trip' => 24,
            'duration_days' => 3,
            'additional_costs' => 80,
            'conditions' => 'Waiting time after 3 hours billed separately.',
        ]);

        $awardedShipment = $shipmentService->create($customer, [
            'cargo_type' => 'Packaged food',
            'cargo_description' => 'Palletized dry food for retail DC',
            'weight_tons' => 16,
            'volume_cbm' => 42,
            'quantity' => 24,
            'quantity_unit' => 'pallets',
            'pickup_address' => 'Rusayl Industrial Estate, Warehouse 12',
            'pickup_city' => 'Muscat',
            'pickup_lat' => 23.5528,
            'pickup_lng' => 58.2066,
            'delivery_address' => 'Nizwa Central Market',
            'delivery_city' => 'Nizwa',
            'delivery_lat' => 22.9333,
            'delivery_lng' => 57.5333,
            'required_date' => now()->addDays(2)->toDateString(),
            'notes' => 'Keep dry. Delivery before 16:00.',
            'publish' => true,
        ]);

        $acceptedQuote = $quotationService->submit($provider, $awardedShipment, [
            'total_price' => 420,
            'truck_count' => 1,
            'truck_type' => TruckType::Box->value,
            'truck_capacity_tons' => 18,
            'trip_count' => 1,
            'quantity_per_trip' => 24,
            'duration_days' => 1,
            'additional_costs' => 0,
            'conditions' => 'Includes loading assistance.',
        ]);

        $previousSandbox = config('mz.sandbox_payments');
        config(['mz.sandbox_payments' => true]);
        $job = $jobService->acceptQuotation($customer, $acceptedQuote->fresh())->job;
        config(['mz.sandbox_payments' => $previousSandbox]);
        $trip = $job->trips()->first();
        $tripService->assign($provider, $trip, [
            'truck_id' => $truck->id,
            'driver_id' => $driver->id,
        ]);
        $tripService->transition($driver, $trip->fresh(), TripStatus::ArrivedAtPickup);
        $tripService->transition($driver, $trip->fresh(), TripStatus::Loaded);
        $tripService->transition($driver, $trip->fresh(), TripStatus::InTransit);
        $tripService->recordLocation($driver, $trip->fresh(), 23.2100, 57.9800, now()->addHours(3)->toIso8601String());
    }
}
