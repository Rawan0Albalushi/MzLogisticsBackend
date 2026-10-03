<?php

namespace App\Services;

use App\Enums\OfferSelectionMode;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\AuditLogger;

class PlatformSettingService
{
    public const OFFER_SELECTION_MODE = 'offer_selection_mode';

    public const BANK_NAME = 'bank_name';

    public const BANK_ACCOUNT_NAME = 'bank_account_name';

    public const BANK_ACCOUNT_NUMBER = 'bank_account_number';

    public const BANK_IBAN = 'bank_iban';

    public function offerSelectionMode(): OfferSelectionMode
    {
        $value = PlatformSetting::query()
            ->where('key', self::OFFER_SELECTION_MODE)
            ->value('value');

        return OfferSelectionMode::tryFrom((string) $value) ?? OfferSelectionMode::Customer;
    }

    public function updateOfferSelectionMode(User $user, OfferSelectionMode $mode): OfferSelectionMode
    {
        $current = $this->offerSelectionMode();

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::OFFER_SELECTION_MODE],
            ['value' => $mode->value],
        );

        AuditLogger::record(
            'settings.offer_selection_updated',
            null,
            ['offer_selection_mode' => $current->value],
            ['offer_selection_mode' => $mode->value],
            $user,
        );

        return $mode;
    }

    /**
     * @return array{bank_name: string, account_name: string, account_number: string, iban: string}
     */
    public function bankAccount(): array
    {
        $stored = PlatformSetting::query()
            ->whereIn('key', $this->bankAccountKeys())
            ->pluck('value', 'key');

        return [
            'bank_name' => (string) ($stored[self::BANK_NAME] ?? ''),
            'account_name' => (string) ($stored[self::BANK_ACCOUNT_NAME] ?? ''),
            'account_number' => (string) ($stored[self::BANK_ACCOUNT_NUMBER] ?? ''),
            'iban' => (string) ($stored[self::BANK_IBAN] ?? ''),
        ];
    }

    /**
     * @param  array{bank_name?: string|null, account_name?: string|null, account_number?: string|null, iban?: string|null}  $data
     * @return array{bank_name: string, account_name: string, account_number: string, iban: string}
     */
    public function updateBankAccount(User $user, array $data): array
    {
        $current = $this->bankAccount();
        $next = [
            'bank_name' => trim((string) ($data['bank_name'] ?? '')),
            'account_name' => trim((string) ($data['account_name'] ?? '')),
            'account_number' => trim((string) ($data['account_number'] ?? '')),
            'iban' => trim((string) ($data['iban'] ?? '')),
        ];

        $keys = [
            'bank_name' => self::BANK_NAME,
            'account_name' => self::BANK_ACCOUNT_NAME,
            'account_number' => self::BANK_ACCOUNT_NUMBER,
            'iban' => self::BANK_IBAN,
        ];

        foreach ($keys as $field => $key) {
            PlatformSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $next[$field]],
            );
        }

        AuditLogger::record('settings.bank_account_updated', null, $current, $next, $user);

        return $next;
    }

    /**
     * @return list<string>
     */
    private function bankAccountKeys(): array
    {
        return [
            self::BANK_NAME,
            self::BANK_ACCOUNT_NAME,
            self::BANK_ACCOUNT_NUMBER,
            self::BANK_IBAN,
        ];
    }
}
