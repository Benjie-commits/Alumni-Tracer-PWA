<?php

namespace App\Support;

/**
 * The LinkedIn address an alumnus may give us (spec section 7.4).
 *
 * It ends up as a link staff click, so only a genuine public-profile address is accepted, and it is
 * rewritten to one fixed form: nothing else a person could paste (a script, another site, a query
 * string full of tracking) is ever stored or shown.
 */
final class LinkedInUrl
{
    /** The only shape we keep. */
    public const PATTERN = '#^https://www\.linkedin\.com/in/[A-Za-z0-9_%-]{3,100}$#';

    /** The canonical address, or null if this is not a LinkedIn profile address. */
    public static function normalise(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        // People paste "linkedin.com/in/someone" without a scheme.
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        // Credentials or a port in the address are never legitimate here.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'linkedin.com' && ! str_ends_with($host, '.linkedin.com')) {
            return null;
        }

        if (! preg_match('#^/in/([A-Za-z0-9_%-]{3,100})/?$#', (string) ($parts['path'] ?? ''), $m)) {
            return null;
        }

        return 'https://www.linkedin.com/in/'.$m[1];
    }
}
