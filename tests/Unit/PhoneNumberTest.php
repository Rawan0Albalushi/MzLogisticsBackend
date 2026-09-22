<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_it_normalizes_oman_mobile_formats(): void
    {
        $this->assertSame('+96899225500', PhoneNumber::normalize('99225500'));
        $this->assertSame('+96899225500', PhoneNumber::normalize('+968 9922 5500'));
        $this->assertSame('+96899225500', PhoneNumber::normalize('0096899225500'));
        $this->assertSame('+96899225500', PhoneNumber::normalize('96899225500'));
    }

    public function test_it_rejects_invalid_numbers(): void
    {
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('123'));
        $this->assertNull(PhoneNumber::normalize('abc'));
    }
}
