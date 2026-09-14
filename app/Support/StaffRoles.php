<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

final class StaffRoles
{
    public const SUPER_ADMIN = 'Super Admin';
    public const PROVIDER_ADMIN = 'Provider Admin';
    public const COMPANY_ADMIN = 'Company Admin';
    public const DRIVER = 'Driver';

    public const SCOPE_PLATFORM = 'platform';
    public const SCOPE_PROVIDER = 'provider';
    public const SCOPE_CUSTOMER = 'customer';
    public const SCOPE_DRIVER = 'driver';

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
            self::COMPANY_ADMIN,
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
            self::PROVIDER_ADMIN,
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
    public static function allSystem(): array
    {
        return [
            ...self::platform(),
            ...self::customer(),
            ...self::provider(),
            self::DRIVER,
        ];
    }

    public static function scopeOf(string $name): string
    {
        if (in_array($name, self::platform(), true)) {
            return self::SCOPE_PLATFORM;
        }

        if (in_array($name, self::customer(), true)) {
            return self::SCOPE_CUSTOMER;
        }

        if (in_array($name, self::provider(), true)) {
            return self::SCOPE_PROVIDER;
        }

        return self::SCOPE_DRIVER;
    }

    public static function scopeFor(User $actor): string
    {
        if ($actor->isPlatform()) {
            return self::SCOPE_PLATFORM;
        }

        if ($actor->isCustomer()) {
            return self::SCOPE_CUSTOMER;
        }

        if ($actor->isProvider()) {
            return self::SCOPE_PROVIDER;
        }

        return self::SCOPE_DRIVER;
    }

    public static function isSystemName(string $name): bool
    {
        return in_array($name, self::allSystem(), true);
    }

    /**
     * @return list<string>
     */
    public static function assignableBy(User $actor): array
    {
        $system = match (true) {
            $actor->isPlatform() => self::platform(),
            $actor->isCustomer() => self::customer(),
            $actor->isProvider() => self::provider(),
            default => [],
        };

        $custom = Role::query()
            ->where('guard_name', 'web')
            ->where('is_system', false)
            ->where('scope', self::scopeFor($actor))
            ->when(
                $actor->isPlatform(),
                fn ($query) => $query->whereNull('organization_id'),
                fn ($query) => $query->where('organization_id', $actor->organization_id),
            )
            ->orderBy('display_name')
            ->pluck('name')
            ->all();

        return array_values(array_unique([...$system, ...$custom]));
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
