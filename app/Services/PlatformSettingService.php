<?php

namespace App\Services;

use App\Enums\OfferSelectionMode;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\AuditLogger;

class PlatformSettingService
{
    public const OFFER_SELECTION_MODE = 'offer_selection_mode';

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
}
