<?php

namespace Tests\Feature\Phase3;

use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\CredentialVerificationRequest;
use App\Services\Verification\CredentialVerifier;
use Illuminate\Support\Facades\Schema;

class VerificationLookupTest extends Phase3TestCase
{
    private function ask(array $payload)
    {
        return $this->postJson('/api/v1/verification/lookup', $payload);
    }

    // ---- the happy path and what it reveals --------------------------------------------

    public function test_a_single_match_is_verified_with_programme_and_year_only(): void
    {
        $this->graduate();

        $this->ask($this->lookup())
            ->assertOk()
            ->assertJsonPath('data.result', 'verified')
            ->assertJsonPath('data.programme', 'BSc Biology')
            ->assertJsonPath('data.graduation_year', 2024)
            ->assertJsonStructure(['data' => ['result', 'reference', 'message', 'programme', 'graduation_year']]);
    }

    public function test_nothing_else_about_the_graduate_is_ever_returned(): void
    {
        $this->graduate([
            'student_number' => 'SU/2020/123', 'email' => 'private@example.com', 'phone' => '+256700555111', 'whatsapp_number' => '+256700555222',
            'date_of_birth' => '1999-04-02', 'class_of_award' => 'First Class', 'city' => 'Gulu', 'other_names' => 'Grace',
            'employment_status' => 'employed',
        ]);

        $response = $this->ask($this->lookup());
        $body = $response->getContent();

        $this->assertSame(['result', 'reference', 'message', 'programme', 'graduation_year'], array_keys($response->json('data')));
        foreach (['SU/2020/123', 'private@example.com', '555111', '555222', '1999', 'First Class', 'Gulu', 'Grace', 'School of Science', 'Department of Biology', 'Okello', 'Amina'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "the verification answer must not reveal {$secret}");
        }
    }

    public function test_imported_and_verified_records_both_count(): void
    {
        $this->graduate(['verification_status' => VerificationStatus::Unclaimed, 'first_name' => 'Peter', 'last_name' => 'Ojok']);
        $this->graduate(['verification_status' => VerificationStatus::Verified, 'first_name' => 'Grace', 'last_name' => 'Achieng']);

        $this->ask($this->lookup(['name' => 'Peter Ojok']))->assertJsonPath('data.result', 'verified');
        $this->ask($this->lookup(['name' => 'Grace Achieng']))->assertJsonPath('data.result', 'verified');
    }

    // ---- matching the name -------------------------------------------------------------

    public function test_names_match_in_any_order_case_and_with_extra_middle_names(): void
    {
        $this->graduate(['first_name' => 'Amina', 'other_names' => 'Grace', 'last_name' => 'Okello']);

        foreach (['Amina Okello', 'okello amina', 'AMINA   OKELLO', 'Amina Grace Okello', 'Okello, Amina', '  amina okello  '] as $typed) {
            $this->ask($this->lookup(['name' => $typed]))->assertJsonPath('data.result', 'verified', "'{$typed}' should match");
        }
    }

    public function test_accents_hyphens_and_apostrophes_do_not_get_in_the_way(): void
    {
        $this->graduate(['first_name' => 'Zoé', 'last_name' => 'Achieng-Okello']);
        $this->graduate(['first_name' => 'Brian', 'last_name' => "O'Brien"]);

        $cases = ['Zoe Achieng Okello', 'Zoé Achieng-Okello', 'zoe okello achieng', 'Brian OBrien', "Brian O'Brien", 'Brian O’Brien'];
        foreach ($cases as $typed) {
            $this->ask($this->lookup(['name' => $typed]))->assertJsonPath('data.result', 'verified', "'{$typed}' should match");
        }
    }

    public function test_only_whole_words_match(): void
    {
        $this->graduate(['first_name' => 'Aminata', 'last_name' => 'Okello']);

        // "Amina" is the start of "Aminata", not the same name.
        $this->ask($this->lookup(['name' => 'Amina Okello']))->assertJsonPath('data.result', 'not_found');
        $this->ask($this->lookup(['name' => 'Aminata Okello']))->assertJsonPath('data.result', 'verified');
    }

    public function test_a_different_name_is_not_found(): void
    {
        $this->graduate();

        $this->ask($this->lookup(['name' => 'Peter Ojok']))
            ->assertOk()
            ->assertJsonPath('data.result', 'not_found')
            ->assertJsonMissingPath('data.programme');
    }

    public function test_a_first_name_alone_or_initials_are_too_vague(): void
    {
        $this->graduate();

        foreach (['Amina', 'A O', 'A. O.', '   ', 'Amina Amina'] as $typed) {
            $this->ask($this->lookup(['name' => $typed]))->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        $this->assertSame(0, CredentialVerificationRequest::count(), 'a rejected question is not a lookup');
    }

    public function test_wildcards_cannot_be_used_to_match_everyone(): void
    {
        $this->graduate();
        $this->graduate(['first_name' => 'Peter', 'last_name' => 'Ojok']);

        foreach (['% %', '_ _', 'Am%na Ok%llo', 'Amina O_ello', '%%%%'] as $typed) {
            $response = $this->ask($this->lookup(['name' => $typed]));

            $this->assertNotSame('verified', $response->json('data.result'), "'{$typed}' must not match anyone");
        }
    }

    public function test_hostile_input_is_harmless(): void
    {
        $this->graduate();

        $this->ask($this->lookup(['name' => "Robert'); DROP TABLE alumni_profiles;-- Tables"]))->assertOk()->assertJsonPath('data.result', 'not_found');
        $this->ask($this->lookup(['name' => '<script>alert(1)</script> Okello']))->assertOk();

        $this->assertSame(1, AlumniProfile::count());
    }

    public function test_very_long_names_are_refused(): void
    {
        $this->ask($this->lookup(['name' => str_repeat('Amina ', 40)]))->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    // ---- who can be verified -----------------------------------------------------------

    public function test_only_records_the_registrar_holds_can_be_verified(): void
    {
        foreach ([
            ['verification_status' => VerificationStatus::Pending],
            ['verification_status' => VerificationStatus::Rejected],
            // Approved by staff as a new alumnus but never in the Registrar's graduate list: no student number.
            ['verification_status' => VerificationStatus::Verified, 'student_number' => null, 'declared_student_number' => 'SU/2020/050'],
        ] as $attributes) {
            $this->graduate($attributes);
        }

        $this->ask($this->lookup())->assertJsonPath('data.result', 'not_found');
    }

    public function test_a_graduation_that_has_not_happened_yet_is_not_confirmed(): void
    {
        $this->graduate(['first_name' => 'Peter', 'last_name' => 'Ojok', 'graduation_year' => 2026, 'graduation_date' => '2026-10-06']); // tomorrow
        $this->graduate(['first_name' => 'Grace', 'last_name' => 'Achieng', 'graduation_year' => 2027, 'graduation_date' => null]);
        $this->graduate(['first_name' => 'Joan', 'last_name' => 'Akello', 'graduation_year' => 2026, 'graduation_date' => '2026-10-05']); // today

        $this->ask($this->lookup(['name' => 'Peter Ojok']))->assertJsonPath('data.result', 'not_found');
        $this->ask($this->lookup(['name' => 'Grace Achieng']))->assertJsonPath('data.result', 'not_found');
        $this->ask($this->lookup(['name' => 'Joan Akello']))->assertJsonPath('data.result', 'verified');
    }

    public function test_a_record_with_no_graduation_year_is_not_confirmed(): void
    {
        $this->graduate(['graduation_year' => null, 'graduation_date' => null]);

        $this->ask($this->lookup())->assertJsonPath('data.result', 'not_found');
    }

    public function test_deleted_records_are_not_confirmed(): void
    {
        $this->graduate()->delete();

        $this->ask($this->lookup())->assertJsonPath('data.result', 'not_found');
    }

    // ---- ambiguity ---------------------------------------------------------------------

    public function test_two_people_with_the_same_name_are_never_listed_just_reported_as_ambiguous(): void
    {
        $a = $this->graduate(['student_number' => 'SU/2020/001', 'email' => 'first@example.com']);
        $b = $this->graduate(['student_number' => 'SU/2020/002', 'email' => 'second@example.com', 'programme_id' => $this->programme('BA History')->id, 'graduation_year' => 2022, 'graduation_date' => '2022-07-01']);

        $response = $this->ask($this->lookup())->assertOk();

        $response->assertJsonPath('data.result', 'ambiguous')->assertJsonPath('data.can_narrow', true);
        $response->assertJsonMissingPath('data.programme');
        $body = $response->getContent();
        foreach (['SU/2020/001', 'SU/2020/002', 'first@example.com', 'BA History', 'BSc Biology', '2022', '2024'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "an ambiguous answer must not describe either graduate ({$leak})");
        }
        $this->assertNull(CredentialVerificationRequest::first()->matched_profile_id);
    }

    public function test_adding_the_programme_or_year_settles_an_ambiguous_name(): void
    {
        $biology = $this->programme('BSc Biology');
        $history = $this->programme('BA History');
        $this->graduate(['programme_id' => $biology->id, 'graduation_year' => 2024]);
        $this->graduate(['programme_id' => $history->id, 'graduation_year' => 2022, 'graduation_date' => '2022-07-01']);

        $this->ask($this->lookup(['programme_id' => $history->id]))
            ->assertJsonPath('data.result', 'verified')->assertJsonPath('data.programme', 'BA History')->assertJsonPath('data.graduation_year', 2022);

        $this->ask($this->lookup(['graduation_year' => 2024]))
            ->assertJsonPath('data.result', 'verified')->assertJsonPath('data.programme', 'BSc Biology');
    }

    public function test_when_even_programme_and_year_cannot_separate_them_only_the_registrar_can(): void
    {
        $this->graduate();
        $this->graduate();

        $response = $this->ask($this->lookup(['programme_id' => $this->programme()->id, 'graduation_year' => 2024]));

        $response->assertJsonPath('data.result', 'ambiguous')->assertJsonMissingPath('data.can_narrow');
        $this->assertStringContainsString("Registrar's office", $response->json('data.message'));
    }

    public function test_a_programme_or_year_that_matches_nobody_is_simply_not_found(): void
    {
        $this->graduate();

        $this->ask($this->lookup(['graduation_year' => 2019]))->assertJsonPath('data.result', 'not_found');
        $this->ask($this->lookup(['programme_id' => $this->programme('BA History')->id]))->assertJsonPath('data.result', 'not_found');
    }

    // ---- the log -----------------------------------------------------------------------

    public function test_every_lookup_is_logged_including_the_ones_that_find_nothing(): void
    {
        $graduate = $this->graduate();

        $verified = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->ask($this->lookup(['email' => 'hr@acme.example', 'programme_id' => $graduate->programme_id, 'graduation_year' => 2024]));
        $missing = $this->ask($this->lookup(['name' => 'Peter Ojok']));

        $this->assertSame(2, CredentialVerificationRequest::count());

        $first = CredentialVerificationRequest::where('reference', $verified->json('data.reference'))->first();
        $this->assertSame(VerificationResult::Verified, $first->result);
        $this->assertSame(VerificationChannel::Api, $first->channel);
        $this->assertSame('Acme Recruitment Ltd', $first->organisation);
        $this->assertSame('hr@acme.example', $first->requester_email);
        $this->assertSame('amina okello', $first->query_name);
        $this->assertSame($graduate->programme_id, $first->query_programme_id);
        $this->assertSame(2024, $first->query_graduation_year);
        $this->assertSame($graduate->id, $first->matched_profile_id);
        $this->assertSame('203.0.113.9', $first->ip_address);
        $this->assertNotNull($first->created_at);

        $second = CredentialVerificationRequest::where('reference', $missing->json('data.reference'))->first();
        $this->assertSame(VerificationResult::NotFound, $second->result);
        $this->assertNull($second->matched_profile_id);
    }

    public function test_the_log_holds_no_profile_data_so_it_can_live_in_its_own_database(): void
    {
        $columns = Schema::getColumnListing('credential_verification_requests');

        foreach (['first_name', 'last_name', 'email', 'phone', 'student_number', 'date_of_birth', 'class_of_award', 'employment_status'] as $profileColumn) {
            $this->assertNotContains($profileColumn, $columns);
        }
        // No foreign keys into the main database: a second database could not enforce them.
        $this->assertSame([], Schema::getForeignKeys('credential_verification_requests'));
    }

    public function test_references_are_short_unique_and_unambiguous_to_read_aloud(): void
    {
        $this->graduate();

        $references = collect(range(1, 8))->map(fn () => $this->ask($this->lookup())->json('data.reference'));

        $this->assertCount(8, $references->unique());
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^VER-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/', $reference);
        }
    }

    public function test_the_log_can_be_pointed_at_a_separate_database(): void
    {
        $this->assertNull((new CredentialVerificationRequest)->getConnectionName(), 'by default it uses the main connection');

        config(['sunates.verification.connection' => 'verification_logs']);
        $this->assertSame('verification_logs', (new CredentialVerificationRequest)->getConnectionName());
    }

    // ---- the request itself ------------------------------------------------------------

    public function test_it_needs_no_sign_in(): void
    {
        $this->graduate();

        $this->assertGuest();
        $this->ask($this->lookup())->assertOk();
    }

    public function test_the_organisation_and_name_are_required(): void
    {
        $this->ask(['name' => 'Amina Okello'])->assertUnprocessable()->assertJsonValidationErrors('organisation');
        $this->ask(['organisation' => 'Acme'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->ask($this->lookup(['organisation' => 'A']))->assertUnprocessable()->assertJsonValidationErrors('organisation');
    }

    public function test_optional_fields_are_validated(): void
    {
        $this->ask($this->lookup(['programme_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors('programme_id');
        $this->ask($this->lookup(['graduation_year' => 1800]))->assertUnprocessable()->assertJsonValidationErrors('graduation_year');
        $this->ask($this->lookup(['graduation_year' => 'soon']))->assertUnprocessable()->assertJsonValidationErrors('graduation_year');
        $this->ask($this->lookup(['email' => 'not-an-email']))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    // ---- abuse -------------------------------------------------------------------------

    public function test_lookups_are_rate_limited_per_address(): void
    {
        $this->graduate();

        foreach (range(1, 20) as $i) {
            $this->ask($this->lookup())->assertOk();
        }

        $this->ask($this->lookup())->assertTooManyRequests();
        $this->assertSame(20, CredentialVerificationRequest::count(), 'a refused request is not answered or logged');
    }

    public function test_the_limit_is_per_address_so_one_noisy_client_does_not_block_others(): void
    {
        $this->graduate();
        foreach (range(1, 21) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->ask($this->lookup());
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->ask($this->lookup())->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->ask($this->lookup())->assertOk();
    }

    public function test_name_words_are_normalised_consistently(): void
    {
        $this->assertSame(['amina', 'okello'], CredentialVerifier::nameWords('  Amina   OKELLO  '));
        $this->assertSame(['achieng', 'okello'], CredentialVerifier::nameWords('Achieng-Okello'));
        $this->assertSame(['obrien', 'brian'], CredentialVerifier::nameWords("O'Brien, Brian"));
        $this->assertSame(['amina', 'okello'], CredentialVerifier::nameWords('Amina A. Okello'), 'initials are ignored');
        $this->assertSame([], CredentialVerifier::nameWords('% _ %'));
    }
}
