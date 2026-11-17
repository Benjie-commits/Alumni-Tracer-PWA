<?php

namespace Tests\Unit;

use App\Services\Verification\CredentialVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The typed name and the stored name must be normalised the same way, or a correct lookup is reported
 * as "not found" (FR-6). The SQL side is checked against the database in VerificationLookupTest; this
 * checks the PHP side and that the generated SQL strips the same punctuation.
 */
class CredentialVerifierNameTest extends TestCase
{
    public function test_typed_names_lose_punctuation_and_split_on_hyphens(): void
    {
        $this->assertSame(['ojok', 'jr'], CredentialVerifier::nameWords('Ojok Jr.'));
        $this->assertSame(['okello', 'amina'], CredentialVerifier::nameWords('Okello, Amina'));
        $this->assertSame(['achieng', 'okello'], CredentialVerifier::nameWords('Achieng-Okello'));
        $this->assertSame(['obrien', 'brian'], CredentialVerifier::nameWords("O'Brien Brian"));
        $this->assertSame(['ruth', 'nakato'], CredentialVerifier::nameWords('Ruth (Nakato)'));
    }

    public function test_the_stored_name_sql_strips_the_same_punctuation(): void
    {
        $sql = CredentialVerifier::normalisedRecordedName();

        foreach (["'-', ' '", "',', ' '", "'.', ''", "'''', ''", "'’', ''", "'(', ''", "')', ''", "'\"', ''"] as $replacement) {
            $this->assertStringContainsString("REPLACE(", $sql);
            $this->assertStringContainsString($replacement, $sql);
        }

        // Padded with spaces so " word " only matches whole words.
        $this->assertStringStartsWith("CONCAT(' ', ", $sql);
        $this->assertStringEndsWith(", ' ')", $sql);
    }
}
