<?php

namespace Tests\Unit;

use App\Support\LinkedInUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LinkedInUrlTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function acceptable(): array
    {
        return [
            'already canonical' => ['https://www.linkedin.com/in/amina-okello', 'https://www.linkedin.com/in/amina-okello'],
            'no scheme' => ['linkedin.com/in/amina-okello', 'https://www.linkedin.com/in/amina-okello'],
            'with www, no scheme' => ['www.linkedin.com/in/amina-okello', 'https://www.linkedin.com/in/amina-okello'],
            'http' => ['http://www.linkedin.com/in/amina-okello', 'https://www.linkedin.com/in/amina-okello'],
            'country site' => ['https://ug.linkedin.com/in/amina-okello', 'https://www.linkedin.com/in/amina-okello'],
            'trailing slash' => ['https://www.linkedin.com/in/amina-okello/', 'https://www.linkedin.com/in/amina-okello'],
            'tracking junk is dropped' => ['https://www.linkedin.com/in/amina-okello/?trk=public_profile&utm_source=x#top', 'https://www.linkedin.com/in/amina-okello'],
            'upper-case host' => ['HTTPS://WWW.LINKEDIN.COM/in/Amina-Okello', 'https://www.linkedin.com/in/Amina-Okello'],
            'surrounding spaces' => ["  linkedin.com/in/amina-okello \n", 'https://www.linkedin.com/in/amina-okello'],
            'percent-encoded slug' => ['https://www.linkedin.com/in/am%C3%ADna-okello', 'https://www.linkedin.com/in/am%C3%ADna-okello'],
        ];
    }

    #[DataProvider('acceptable')]
    public function test_profile_addresses_are_rewritten_to_one_canonical_form(string $typed, string $expected): void
    {
        $this->assertSame($expected, LinkedInUrl::normalise($typed));
        $this->assertSame(1, preg_match(LinkedInUrl::PATTERN, $expected));
    }

    /** @return array<string, array{string}> */
    public static function unacceptable(): array
    {
        return [
            'script' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'another site' => ['https://evil.example/in/amina-okello'],
            'lookalike host' => ['https://linkedin.com.evil.example/in/amina-okello'],
            'lookalike suffix' => ['https://notlinkedin.com/in/amina-okello'],
            'a company page' => ['https://www.linkedin.com/company/soroti-university'],
            'no profile name' => ['https://www.linkedin.com/in/'],
            'too short' => ['https://www.linkedin.com/in/ab'],
            'extra path' => ['https://www.linkedin.com/in/amina-okello/details/experience'],
            'credentials in the address' => ['https://user:pass@www.linkedin.com/in/amina-okello'],
            'a port' => ['https://www.linkedin.com:8443/in/amina-okello'],
            'ftp' => ['ftp://www.linkedin.com/in/amina-okello'],
            'markup' => ['https://www.linkedin.com/in/"><script>'],
            'plain words' => ['my linkedin'],
            'empty' => [''],
            'blank' => ['   '],
        ];
    }

    #[DataProvider('unacceptable')]
    public function test_anything_else_is_refused(string $typed): void
    {
        $this->assertNull(LinkedInUrl::normalise($typed));
    }

    public function test_null_is_nothing(): void
    {
        $this->assertNull(LinkedInUrl::normalise(null));
    }
}
