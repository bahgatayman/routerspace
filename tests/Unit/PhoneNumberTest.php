<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_equivalent_egyptian_formats_normalize_to_the_same_canonical_value(): void
    {
        $expected = '201012345678';

        $this->assertSame($expected, PhoneNumber::normalize('01012345678'));
        $this->assertSame($expected, PhoneNumber::normalize('+201012345678'));
        $this->assertSame($expected, PhoneNumber::normalize('201012345678'));
    }

    public function test_formatting_noise_is_stripped(): void
    {
        $this->assertSame('201012345678', PhoneNumber::normalize('010 1234 5678'));
        $this->assertSame('201012345678', PhoneNumber::normalize('010-1234-5678'));
        $this->assertSame('201012345678', PhoneNumber::normalize('(010) 1234-5678'));
    }

    public function test_every_valid_mobile_prefix_is_accepted(): void
    {
        foreach (['10', '11', '12', '15'] as $prefix) {
            $this->assertSame('20'.$prefix.'12345678', PhoneNumber::normalize('0'.$prefix.'12345678'));
        }
    }

    public function test_non_mobile_or_malformed_numbers_return_null(): void
    {
        $this->assertNull(PhoneNumber::normalize('0223456789')); // Cairo landline prefix (02), not mobile
        $this->assertNull(PhoneNumber::normalize('12345'));      // too short
        $this->assertNull(PhoneNumber::normalize('44123456789')); // foreign-looking, not an Egyptian mobile shape
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize('abc'));
    }
}
