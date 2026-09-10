<?php

namespace App\Support;

final class StaffRoles
{
    public const SUPER_ADMIN = 'Super Admin';

    /**
     * @return list<string>
     */
    public static function platform(): array
    {
        return [
            self::SUPER_ADMIN,
            'Operations Manager',
            'Operations Staff',
            'Finance Manager',
            'Finance Staff',
            'Customer Support',
            'Management / Viewer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function customer(): array
    {
        return [
            'Company Admin',
            'Logistics Manager',
            'Request Creator',
            'Customer Finance',
            'Customer Viewer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function provider(): array
    {
        return [
            'Provider Admin',
            'Operations',
            'Quotation / Sales',
            'Dispatcher',
            'Fleet Manager',
            'Finance',
            'Viewer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function assignableBy(\App\Models\User $actor): array
    {
        if ($actor->isPlatform()) {
            return self::platform();
        }

        if ($actor->isCustomer()) {
            return self::customer();
        }

        if ($actor->isProvider()) {
            return self::provider();
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function protectedSuperAdminPermissions(): array
    {
        return [
            Permissions::DASHBOARD_VIEW,
            Permissions::USERS_MANAGE,
            Permissions::ROLES_MANAGE,
        ];
    }
}
