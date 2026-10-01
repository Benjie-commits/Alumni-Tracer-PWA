<?php

namespace App\Services\Verification;

use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Models\AlumniProfile;
use App\Models\CredentialVerificationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * FR-6: confirms to an employer or partner institution that someone graduated.
 *
 * It answers one question, "did a person with this name graduate (from this programme, in this
 * year)?", and it never lists candidates: a name that fits several people yields "ambiguous",
 * not their details, so the service cannot be used to browse the alumni directory. Every lookup,
 * including the ones that find nothing, is logged.
 */
class CredentialVerifier
{
    /** More name words than this is not a person's name; it bounds the query. */
    private const MAX_NAME_WORDS = 6;

    /**
     * @throws ValidationException when the name is too vague to look up
     */
    public function lookup(
        string $name,
        ?int $programmeId,
        ?int $graduationYear,
        string $organisation,
        ?string $email,
        VerificationChannel $channel,
        ?string $ip,
    ): VerificationOutcome {
        $words = self::nameWords($name);

        if (count($words) < 2) {
            throw ValidationException::withMessages(['name' => 'Enter the graduate\'s full name (first and last name).']);
        }
        $words = array_slice($words, 0, self::MAX_NAME_WORDS);

        $matches = $this->matching($words, $programmeId, $graduationYear)->limit(2)->get();

        [$result, $matched] = match ($matches->count()) {
            0 => [VerificationResult::NotFound, null],
            1 => [VerificationResult::Verified, $matches->first()],
            default => [VerificationResult::Ambiguous, null],
        };

        $reference = CredentialVerificationRequest::newReference();

        CredentialVerificationRequest::query()->create([
            'reference' => $reference,
            'channel' => $channel,
            'organisation' => $organisation,
            'requester_email' => $email,
            'query_name' => implode(' ', $words),
            'query_programme_id' => $programmeId,
            'query_graduation_year' => $graduationYear,
            'result' => $result,
            'matched_profile_id' => $matched?->id,
            'ip_address' => $ip,
        ]);

        return match ($result) {
            VerificationResult::Verified => new VerificationOutcome(
                $result, $reference, $matched->programme?->name, $matched->graduation_year,
            ),
            // Ambiguous on the name alone: more detail may settle it. Already narrowed: only the Registrar can.
            VerificationResult::Ambiguous => new VerificationOutcome($result, $reference, canNarrow: $programmeId === null && $graduationYear === null),
            default => new VerificationOutcome($result, $reference),
        };
    }

    /**
     * Words of a typed name, ready to compare: lower-case, hyphens treated as spaces ("Achieng-Okello"
     * is two words), apostrophes and other punctuation dropped, initials and repeats ignored.
     *
     * @return list<string>
     */
    public static function nameWords(string $name): array
    {
        $name = mb_strtolower($name);
        $name = str_replace(['-', '’'], [' ', ''], $name);
        $name = (string) preg_replace("/[^\p{L}\p{N}\s]/u", '', $name);

        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w) => mb_strlen($w) >= 2)));
    }

    /**
     * Graduates whose recorded name contains every word that was typed, in any order, as whole words.
     * "Amina Okello" finds "Amina Grace Okello" and "Okello, Amina" but not "Aminata Okello".
     *
     * @param  list<string>  $words
     * @return Builder<AlumniProfile>
     */
    private function matching(array $words, ?int $programmeId, ?int $graduationYear)
    {
        // Same normalisation as nameWords(), applied to the stored names, with a space either side
        // so a LIKE on " word " only ever matches a whole word.
        $recorded = "CONCAT(' ', REPLACE(REPLACE(REPLACE(CONCAT_WS(' ', first_name, other_names, last_name), '-', ' '), '''', ''), '’', ''), ' ')";

        $query = AlumniProfile::query()->verifiable()->with('programme');

        foreach ($words as $word) {
            $query->whereRaw("{$recorded} LIKE ?", ['% '.addcslashes($word, '%_\\').' %']);
        }

        return $query
            ->when($programmeId, fn ($q, $id) => $q->where('programme_id', $id))
            ->when($graduationYear, fn ($q, $year) => $q->where('graduation_year', $year));
    }
}
