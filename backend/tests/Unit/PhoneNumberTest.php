<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function ugandanNumbers(): array
    {
        return [
            'national with spaces' => ['0700 123 456', '+256700123456'],
            'national plain' => ['0771234567', '+256771234567'],
            'already international' => ['+256700123456', '+256700123456'],
            'international with punctuation' => ['+256 (700) 123-456', '+256700123456'],
            'country code without plus' => ['256700123456', '+256700123456'],
            'double-zero prefix' => ['00256700123456', '+256700123456'],
            'bare subscriber number' => ['700123456', '+256700123456'],
            'leading and trailing space' => ['  0700123456 ', '+256700123456'],
            'another country is left alone' => ['+254712345678', '+254712345678'],
            'dashes' => ['0700-123-456', '+256700123456'],
        ];
    }

    #[DataProvider('ugandanNumbers')]
    public function test_it_normalises_to_e164(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toE164($raw));
    }

    /** @return array<string, array{0: ?string}> */
    public static function unusable(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'letters' => ['call me'],
            'too short' => ['0700'],
            'too long' => ['+2567001234567890123'],
            'plus only' => ['+'],
        ];
    }

    #[DataProvider('unusable')]
    public function test_it_rejects_what_cannot_be_a_number(?string $raw): void
    {
        $this->assertNull(PhoneNumber::toE164($raw));
    }

    public function test_the_default_country_is_configurable(): void
    {
        $this->assertSame('+254712345678', PhoneNumber::toE164('0712345678', '254'));
    }

    public function test_last_nine_digits_identify_a_number_however_it_was_typed(): void
    {
        $this->assertSame('700123456', PhoneNumber::lastNine('0700 123 456'));
        $this->assertSame('700123456', PhoneNumber::lastNine('+256700123456'));
        $this->assertNull(PhoneNumber::lastNine('12345'));
        $this->assertNull(PhoneNumber::lastNine(null));
    }
}
