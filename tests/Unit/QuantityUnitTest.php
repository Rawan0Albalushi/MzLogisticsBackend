<?php

namespace Tests\Unit;

use App\Enums\QuantityUnit;
use PHPUnit\Framework\TestCase;

class QuantityUnitTest extends TestCase
{
    public function test_it_normalizes_legacy_aliases(): void
    {
        $this->assertSame(QuantityUnit::Tons, QuantityUnit::tryNormalize('ton'));
        $this->assertSame(QuantityUnit::Pallets, QuantityUnit::tryNormalize('pallet'));
        $this->assertSame(QuantityUnit::Units, QuantityUnit::tryNormalize('unit'));
        $this->assertSame(QuantityUnit::Tons, QuantityUnit::normalize(null));
    }

    public function test_it_rejects_unknown_units(): void
    {
        $this->assertNull(QuantityUnit::tryNormalize('loads'));
    }
}
