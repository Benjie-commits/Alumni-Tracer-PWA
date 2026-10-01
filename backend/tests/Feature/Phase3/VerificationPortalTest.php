<?php

namespace Tests\Feature\Phase3;

use App\Enums\EscalationStatus;
use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Models\CredentialVerificationRequest;
use App\Models\VerificationEscalation;
use App\Services\Verification\CredentialLinkService;

class VerificationPortalTest extends Phase3TestCase
{
    private function check(array $overrides = [])
    {
        return $this->post('/verify', $this->lookup($overrides));
    }

    private function enquiryFor(string $reference, array $overrides = [])
    {
        return $this->post('/verify/enquiry', $overrides + [
            'reference' => $reference,
            'requester_name' => 'Jane Hiring',
            'requester_email' => 'jane@acme.example',
            'requester_phone' => '+256700123123',
            'message' => 'She says she finished in 2022.',
        ]);
    }

    // ---- the page ----------------------------------------------------------------------

    public function test_the_portal_loads_for_anyone_without_signing_in(): void
    {
        $this->programme('BSc Biology');

        $this->get('/verify')
            ->assertOk()
            ->assertSee('Verify a graduate')
            ->assertSee('BSc Biology')
            ->assertSee('Your organisation')
            ->assertSee('never share contact details');
    }

    public function test_the_page_is_not_indexed_by_search_engines(): void
    {
        $this->get('/verify')->assertSee('noindex', false);
    }

    public function test_a_verified_graduate_is_confirmed_in_words(): void
    {
        $this->graduate();

        $this->check()
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee('Soroti University confirms this person graduated with BSc Biology in 2024.')
            ->assertSee('VER-', false);
    }

    public function test_the_portal_reveals_no_more_than_the_api(): void
    {
        $this->graduate(['student_number' => 'SU/2020/123', 'email' => 'private@example.com', 'phone' => '0700 555 111', 'class_of_award' => 'First Class']);

        $page = $this->check();

        foreach (['SU/2020/123', 'private@example.com', '555 111', 'First Class'] as $secret) {
            $page->assertDontSee($secret);
        }

        // The programme dropdown lists the schools (public reference data); the answer itself must not.
        preg_match('#<div class="result.*?</div>#s', $page->getContent(), $result);
        $this->assertNotEmpty($result, 'the result block should be on the page');
        foreach (['School of Science', 'Department of Biology', 'Amina', 'Okello'] as $secret) {
            $this->assertStringNotContainsString($secret, $result[0], "the answer must not mention {$secret}");
        }
    }

    public function test_a_lookup_from_the_portal_is_logged_as_a_portal_lookup(): void
    {
        $this->graduate();

        $this->check();

        $log = CredentialVerificationRequest::sole();
        $this->assertSame(VerificationChannel::Portal, $log->channel);
        $this->assertSame('Acme Recruitment Ltd', $log->organisation);
    }

    public function test_the_form_explains_problems_in_plain_language(): void
    {
        $this->check(['name' => 'Amina'])->assertSessionHasErrors('name');
        $this->check(['organisation' => ''])->assertSessionHasErrors('organisation');
    }

    public function test_not_found_offers_the_registrars_help_but_verified_does_not(): void
    {
        $this->graduate();

        $this->check(['name' => 'Peter Ojok'])
            ->assertSee('Not found')
            ->assertSee("Ask the Registrar's office to check", false)
            ->assertSee('name="reference"', false);

        $this->check()->assertDontSee("Ask the Registrar's office to check", false);
    }

    public function test_an_ambiguous_name_first_asks_for_more_detail_rather_than_the_registrar(): void
    {
        $this->graduate();
        $this->graduate();

        $this->check()
            ->assertSee('Add the programme and the year of graduation')
            ->assertDontSee("Ask the Registrar's office to check", false);
    }

    public function test_an_unresolvable_ambiguity_goes_to_the_registrar(): void
    {
        $this->graduate();
        $this->graduate();

        $this->check(['programme_id' => $this->programme()->id, 'graduation_year' => 2024])
            ->assertSee("Ask the Registrar's office to check", false);
    }

    // ---- asking the Registrar to check ---------------------------------------------------

    public function test_an_enquiry_reaches_the_registrar_with_everything_they_need(): void
    {
        $programme = $this->programme('BSc Biology');
        $this->graduate();
        $reference = CredentialVerificationRequest::newReference();
        $lookup = $this->check(['name' => 'Peter Ojok', 'programme_id' => $programme->id, 'graduation_year' => 2022, 'email' => 'jane@acme.example']);
        $reference = CredentialVerificationRequest::sole()->reference;

        $this->enquiryFor($reference)->assertOk()->assertSee("the Registrar's office has your request", false);

        $escalation = VerificationEscalation::sole();
        $this->assertSame($reference, $escalation->request_reference);
        $this->assertSame('Acme Recruitment Ltd', $escalation->organisation);
        $this->assertSame('Jane Hiring', $escalation->requester_name);
        $this->assertSame('jane@acme.example', $escalation->requester_email);
        $this->assertSame('+256700123123', $escalation->requester_phone);
        $this->assertSame('peter ojok', $escalation->subject_name);
        $this->assertSame(2022, $escalation->subject_graduation_year);
        $this->assertSame('BSc Biology', $escalation->subject_programme);
        $this->assertSame(VerificationResult::NotFound, $escalation->lookup_result);
        $this->assertSame(EscalationStatus::Open, $escalation->status);
        $this->assertSame('She says she finished in 2022.', $escalation->message);
    }

    public function test_sending_the_same_enquiry_twice_does_not_duplicate_it(): void
    {
        $this->check(['name' => 'Peter Ojok']);
        $reference = CredentialVerificationRequest::sole()->reference;

        $this->enquiryFor($reference)->assertOk();
        $this->enquiryFor($reference, ['requester_name' => 'Someone Else'])->assertOk();

        $this->assertSame(1, VerificationEscalation::count());
        $this->assertSame('Jane Hiring', VerificationEscalation::sole()->requester_name, 'the first one stands');
    }

    public function test_an_enquiry_needs_a_real_reference_from_an_unresolved_lookup(): void
    {
        $this->graduate();
        $this->check(); // verified: nothing to escalate
        $verifiedReference = CredentialVerificationRequest::sole()->reference;

        $this->enquiryFor($verifiedReference)->assertNotFound();
        $this->enquiryFor('VER-ZZZZZZZZ')->assertNotFound();
        $this->assertSame(0, VerificationEscalation::count());
    }

    public function test_a_shared_link_view_cannot_be_escalated(): void
    {
        $user = $this->alumnusAccount();
        $link = app(CredentialLinkService::class)->issue($user->alumniProfile);
        $this->get('/verify/'.$link->token)->assertOk();
        $reference = CredentialVerificationRequest::where('channel', VerificationChannel::Link)->sole()->reference;

        $this->enquiryFor($reference)->assertNotFound();
    }

    public function test_an_enquiry_requires_contact_details_so_the_registrar_can_reply(): void
    {
        $this->check(['name' => 'Peter Ojok']);
        $reference = CredentialVerificationRequest::sole()->reference;

        $this->enquiryFor($reference, ['requester_email' => 'not-an-email'])->assertSessionHasErrors('requester_email');
        $this->enquiryFor($reference, ['requester_name' => ''])->assertSessionHasErrors('requester_name');
        $this->enquiryFor($reference, ['message' => str_repeat('x', 1001)])->assertSessionHasErrors('message');
        $this->assertSame(0, VerificationEscalation::count());
    }

    public function test_bots_that_fill_the_hidden_field_are_quietly_ignored(): void
    {
        $this->check(['name' => 'Peter Ojok']);
        $reference = CredentialVerificationRequest::sole()->reference;

        $this->enquiryFor($reference, ['website' => 'http://spam.example'])->assertOk()->assertSee("the Registrar's office has your request", false);

        $this->assertSame(0, VerificationEscalation::count(), 'the bot is told it worked, but nothing is created');
    }

    public function test_enquiries_are_tightly_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->check(['name' => "Person{$i} Nobody"]);
            $this->enquiryFor(CredentialVerificationRequest::latest('id')->first()->reference)->assertOk();
        }

        $this->check(['name' => 'Sixth Nobody']);
        $this->enquiryFor(CredentialVerificationRequest::latest('id')->first()->reference)->assertTooManyRequests();

        $this->assertSame(5, VerificationEscalation::count());
    }

    public function test_the_web_lookup_is_rate_limited_like_the_api(): void
    {
        foreach (range(1, 20) as $i) {
            $this->check()->assertOk();
        }

        $this->check()->assertTooManyRequests();
    }
}
