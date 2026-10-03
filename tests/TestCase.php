<?php

namespace Tests;

use App\Enums\OfferSelectionMode;
use App\Models\PlatformSetting;
use App\Services\PlatformSettingService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function useCustomerOfferSelection(): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => PlatformSettingService::OFFER_SELECTION_MODE],
            ['value' => OfferSelectionMode::Customer->value],
        );
    }
}
