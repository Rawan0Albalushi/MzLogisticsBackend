<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use App\Support\StaffRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $roles = [
            'Super Admin' => Permissions::all(),
            'Operations Manager' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::CUSTOMERS_VIEW,
                Permissions::PROVIDERS_VIEW,
                Permissions::PROVIDERS_VERIFY,
                Permissions::FLEET_VIEW,
                Permissions::FLEET_MANAGE,
                Permissions::DRIVERS_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::SHIPMENTS_MANAGE,
                Permissions::QUOTATIONS_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::JOBS_MANAGE,
                Permissions::TRIPS_VIEW,
                Permissions::TRIPS_UPDATE,
                Permissions::TRACKING_VIEW,
                Permissions::POD_VIEW,
                Permissions::REPORTS_VIEW,
            ],
            'Operations Staff' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::QUOTATIONS_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::TRACKING_VIEW,
                Permissions::POD_VIEW,
            ],
            'Finance Manager' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::PROVIDERS_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::PAYMENTS_MANAGE,
                Permissions::INVOICES_VIEW,
                Permissions::SETTLEMENTS_VIEW,
                Permissions::SETTLEMENTS_MANAGE,
                Permissions::WALLETS_VIEW,
                Permissions::WALLETS_MANAGE,
                Permissions::REPORTS_VIEW,
                Permissions::JOBS_VIEW,
            ],
            'Finance Staff' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::INVOICES_VIEW,
                Permissions::SETTLEMENTS_VIEW,
                Permissions::WALLETS_VIEW,
            ],
            'Customer Support' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::CUSTOMERS_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::POD_VIEW,
            ],
            'Management / Viewer' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::REPORTS_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::PAYMENTS_VIEW,
            ],
            'Company Admin' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::SHIPMENTS_CREATE,
                Permissions::SHIPMENTS_MANAGE,
                Permissions::QUOTATIONS_VIEW,
                Permissions::QUOTATIONS_ACCEPT,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::TRACKING_VIEW,
                Permissions::POD_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::INVOICES_VIEW,
                Permissions::USERS_MANAGE,
                Permissions::COMPANY_MANAGE,
            ],
            'Logistics Manager' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::SHIPMENTS_CREATE,
                Permissions::SHIPMENTS_MANAGE,
                Permissions::QUOTATIONS_VIEW,
                Permissions::QUOTATIONS_ACCEPT,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::TRACKING_VIEW,
                Permissions::POD_VIEW,
            ],
            'Request Creator' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::SHIPMENTS_CREATE,
                Permissions::QUOTATIONS_VIEW,
            ],
            'Customer Finance' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::INVOICES_VIEW,
                Permissions::JOBS_VIEW,
            ],
            'Customer Viewer' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::QUOTATIONS_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
            ],
            'Provider Admin' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::COMPANY_MANAGE,
                Permissions::USERS_MANAGE,
                Permissions::ROLES_MANAGE,
                Permissions::FLEET_VIEW,
                Permissions::FLEET_MANAGE,
                Permissions::DRIVERS_VIEW,
                Permissions::DRIVERS_MANAGE,
                Permissions::SHIPMENTS_VIEW,
                Permissions::QUOTATIONS_VIEW,
                Permissions::QUOTATIONS_CREATE,
                Permissions::QUOTATIONS_MANAGE,
                Permissions::JOBS_VIEW,
                Permissions::JOBS_MANAGE,
                Permissions::TRIPS_VIEW,
                Permissions::TRIPS_ASSIGN,
                Permissions::TRIPS_UPDATE,
                Permissions::TRACKING_VIEW,
                Permissions::POD_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::INVOICES_VIEW,
                Permissions::SETTLEMENTS_VIEW,
                Permissions::SETTLEMENTS_REQUEST,
                Permissions::WALLETS_VIEW,
            ],
            'Operations' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::TRIPS_ASSIGN,
                Permissions::TRIPS_UPDATE,
                Permissions::FLEET_VIEW,
                Permissions::DRIVERS_VIEW,
                Permissions::TRACKING_VIEW,
                Permissions::POD_VIEW,
            ],
            'Quotation / Sales' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::QUOTATIONS_VIEW,
                Permissions::QUOTATIONS_CREATE,
                Permissions::QUOTATIONS_MANAGE,
            ],
            'Dispatcher' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::TRIPS_ASSIGN,
                Permissions::FLEET_VIEW,
                Permissions::DRIVERS_VIEW,
                Permissions::JOBS_VIEW,
            ],
            'Fleet Manager' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::FLEET_VIEW,
                Permissions::FLEET_MANAGE,
                Permissions::DRIVERS_VIEW,
            ],
            'Finance' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::PAYMENTS_VIEW,
                Permissions::INVOICES_VIEW,
                Permissions::SETTLEMENTS_VIEW,
                Permissions::SETTLEMENTS_REQUEST,
                Permissions::WALLETS_VIEW,
            ],
            'Viewer' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::SHIPMENTS_VIEW,
                Permissions::QUOTATIONS_VIEW,
                Permissions::JOBS_VIEW,
                Permissions::TRIPS_VIEW,
            ],
            'Driver' => [
                Permissions::DASHBOARD_VIEW,
                Permissions::TRIPS_VIEW,
                Permissions::TRIPS_UPDATE,
                Permissions::POD_CREATE,
                Permissions::TRACKING_VIEW,
            ],
        ];

        foreach ($roles as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');
            $role->fill([
                'display_name' => $name,
                'scope' => StaffRoles::scopeOf($name),
                'organization_id' => null,
                'is_system' => true,
            ])->save();
            $role->syncPermissions($permissions);
        }
    }
}
