<?php

namespace App\Support;

final class Permissions
{
    public const CUSTOMERS_VIEW = 'customers.view';
    public const CUSTOMERS_MANAGE = 'customers.manage';
    public const PROVIDERS_VIEW = 'providers.view';
    public const PROVIDERS_MANAGE = 'providers.manage';
    public const PROVIDERS_VERIFY = 'providers.verify';
    public const FLEET_VIEW = 'fleet.view';
    public const FLEET_MANAGE = 'fleet.manage';
    public const DRIVERS_VIEW = 'drivers.view';
    public const DRIVERS_MANAGE = 'drivers.manage';
    public const SHIPMENTS_VIEW = 'shipments.view';
    public const SHIPMENTS_CREATE = 'shipments.create';
    public const SHIPMENTS_MANAGE = 'shipments.manage';
    public const QUOTATIONS_VIEW = 'quotations.view';
    public const QUOTATIONS_CREATE = 'quotations.create';
    public const QUOTATIONS_MANAGE = 'quotations.manage';
    public const QUOTATIONS_ACCEPT = 'quotations.accept';
    public const JOBS_VIEW = 'jobs.view';
    public const JOBS_MANAGE = 'jobs.manage';
    public const TRIPS_VIEW = 'trips.view';
    public const TRIPS_ASSIGN = 'trips.assign';
    public const TRIPS_UPDATE = 'trips.update';
    public const TRACKING_VIEW = 'tracking.view';
    public const POD_VIEW = 'pod.view';
    public const POD_CREATE = 'pod.create';
    public const PAYMENTS_VIEW = 'payments.view';
    public const PAYMENTS_MANAGE = 'payments.manage';
    public const INVOICES_VIEW = 'invoices.view';
    public const SETTLEMENTS_VIEW = 'settlements.view';
    public const SETTLEMENTS_MANAGE = 'settlements.manage';
    public const REPORTS_VIEW = 'reports.view';
    public const USERS_MANAGE = 'users.manage';
    public const ROLES_MANAGE = 'roles.manage';
    public const COMPANY_MANAGE = 'company.manage';
    public const DASHBOARD_VIEW = 'dashboard.view';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CUSTOMERS_VIEW,
            self::CUSTOMERS_MANAGE,
            self::PROVIDERS_VIEW,
            self::PROVIDERS_MANAGE,
            self::PROVIDERS_VERIFY,
            self::FLEET_VIEW,
            self::FLEET_MANAGE,
            self::DRIVERS_VIEW,
            self::DRIVERS_MANAGE,
            self::SHIPMENTS_VIEW,
            self::SHIPMENTS_CREATE,
            self::SHIPMENTS_MANAGE,
            self::QUOTATIONS_VIEW,
            self::QUOTATIONS_CREATE,
            self::QUOTATIONS_MANAGE,
            self::QUOTATIONS_ACCEPT,
            self::JOBS_VIEW,
            self::JOBS_MANAGE,
            self::TRIPS_VIEW,
            self::TRIPS_ASSIGN,
            self::TRIPS_UPDATE,
            self::TRACKING_VIEW,
            self::POD_VIEW,
            self::POD_CREATE,
            self::PAYMENTS_VIEW,
            self::PAYMENTS_MANAGE,
            self::INVOICES_VIEW,
            self::SETTLEMENTS_VIEW,
            self::SETTLEMENTS_MANAGE,
            self::REPORTS_VIEW,
            self::USERS_MANAGE,
            self::ROLES_MANAGE,
            self::COMPANY_MANAGE,
            self::DASHBOARD_VIEW,
        ];
    }
}
