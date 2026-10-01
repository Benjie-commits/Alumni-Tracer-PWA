<?php

namespace Tests\Feature\Phase4;

use App\Enums\FollowUpOutcome;
use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\VerificationStatus;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\FollowUp;
use App\Models\AlumniProfile;
use App\Models\FollowUpCheck;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Followup\FollowUpCandidates;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

class FollowUpTest extends Phase4TestCase
{
    private function candidates(): FollowUpCandidates
    {
        return new FollowUpCandidates;
    }

    /** @return list<int> */
    private function ids(string $reason, array $filters = []): array
    {
        return $this->candidates()->query($reason, $filters)->pluck('alumni_profiles.id')->all();
    }

    /** A graduate nobody has heard from in two years, with a number we could have messaged. */
    private function stale(array $attributes = []): AlumniProfile
    {
        $profile = $this->graduate($attributes + ['phone' => '+256700000001']);
        $profile->forceFill(['created_at' => now()->subYears(2)])->save();

        return $profile;
    }

    private function nudge(AlumniProfile $profile, int $times = 1, NotificationStatus $status = NotificationStatus::Sent, ?Carbon $at = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            // forceFill: created_at is not mass-assignable, and an old log would silently become "now".
            (new NotificationLog)->forceFill([
                'alumni_profile_id' => $profile->id,
                'channel' => NotificationChannel::Whatsapp,
                'template' => MessageTemplate::ProfileNudge,
                'to_number' => '+256700000001',
                'status' => $status,
                'created_at' => $at ?? now()->subDays(30 * ($i + 1)),
            ])->save();
        }
    }

    private function registrar(): User
    {
        return User::factory()->registrar()->create();
    }

    // ---- "did not respond" ------------------------------------------------------------------------------

    public function test_someone_who_ignored_repeated_nudges_is_listed(): void
    {
        $silent = $this->stale();
        $this->nudge($silent, 2);

        $this->assertSame([$silent->id], $this->ids(FollowUpCandidates::UNRESPONSIVE));
    }

    public function test_one_nudge_is_not_yet_silence(): void
    {
        $this->nudge($this->stale(), 1);

        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE));
    }

    public function test_someone_never_asked_is_not_non_responsive(): void
    {
        $this->stale();

        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE), 'not asked yet is not the same as not answering');
    }

    public function test_only_nudges_that_got_out_in_the_last_year_count(): void
    {
        $failed = $this->stale();
        $this->nudge($failed, 2, NotificationStatus::Failed);
        $blocked = $this->stale();
        $this->nudge($blocked, 2, NotificationStatus::Blocked);
        $old = $this->stale();
        $this->nudge($old, 2, at: now()->subDays(400));
        $delivered = $this->stale();
        $this->nudge($delivered, 2, NotificationStatus::Delivered);

        $this->assertSame([$delivered->id], $this->ids(FollowUpCandidates::UNRESPONSIVE));
    }

    public function test_survey_messages_are_not_nudges(): void
    {
        $profile = $this->stale();
        foreach ([1, 2] as $i) {
            (new NotificationLog)->forceFill([
                'alumni_profile_id' => $profile->id, 'channel' => NotificationChannel::Sms, 'template' => MessageTemplate::SurveyInvite,
                'to_number' => '+256700000001', 'status' => NotificationStatus::Sent, 'created_at' => now()->subDays(10 * $i),
            ])->save();
        }

        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE));
    }

    public function test_the_nudge_count_is_available_for_display(): void
    {
        $profile = $this->stale();
        $this->nudge($profile, 3);

        $found = $this->candidates()->withNudgeCount($this->candidates()->query(FollowUpCandidates::UNRESPONSIVE))->first();

        $this->assertSame(3, (int) $found->nudges_sent);
    }

    // ---- who is never suggested ------------------------------------------------------------------------------

    public function test_anyone_who_confirmed_their_record_within_the_year_is_left_alone(): void
    {
        $edited = $this->stale(['profile_updated_at' => now()->subMonths(3)]);
        $this->nudge($edited, 2);
        $surveyed = $this->stale(['last_survey_completed_at' => now()->subMonths(2)]);
        $this->nudge($surveyed, 2);

        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE));
    }

    public function test_only_graduates_the_registrar_s_records_confirm_are_listed(): void
    {
        $selfRegistered = $this->stale(['student_number' => null]);
        $this->nudge($selfRegistered, 2);
        $pending = $this->stale(['verification_status' => VerificationStatus::Pending]);
        $this->nudge($pending, 2);
        $rejected = $this->stale(['verification_status' => VerificationStatus::Rejected]);
        $this->nudge($rejected, 2);
        $future = $this->stale(['graduation_year' => 2026, 'graduation_date' => '2027-07-01']);
        $this->nudge($future, 2);

        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE));
    }

    public function test_someone_who_stopped_every_message_is_never_suggested_but_one_channel_is_fine(): void
    {
        $stopped = $this->stale(['sms_opt_out_at' => now()->subMonth(), 'whatsapp_opt_out_at' => now()->subMonth()]);
        $this->nudge($stopped, 2);
        $oneChannel = $this->stale(['sms_opt_out_at' => now()->subMonth()]);
        $this->nudge($oneChannel, 2);

        $this->assertSame([$oneChannel->id], $this->ids(FollowUpCandidates::UNRESPONSIVE));

        $noPhone = $this->stale(['phone' => null, 'whatsapp_opt_out_at' => now(), 'sms_opt_out_at' => now()]);
        $this->assertNotContains($noPhone->id, $this->ids(FollowUpCandidates::UNREACHABLE), 'asking to be left alone also applies when we hold no number');
    }

    public function test_someone_staff_already_looked_for_is_left_alone_until_the_gap_has_passed(): void
    {
        $profile = $this->stale();
        $this->nudge($profile, 2);
        FollowUpCheck::create(['alumni_profile_id' => $profile->id, 'checked_by' => $this->registrar()->id, 'outcome' => FollowUpOutcome::NotFound]);

        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE));

        Carbon::setTestNow(now()->addDays(179));
        $this->assertSame([], $this->ids(FollowUpCandidates::UNRESPONSIVE), 'still inside the 180 days');

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame([$profile->id], $this->ids(FollowUpCandidates::UNRESPONSIVE), 'suggested again once the gap has passed');
    }

    // ---- "no phone number" ------------------------------------------------------------------------------------

    public function test_graduates_we_hold_no_number_for_are_listed_as_unreachable(): void
    {
        $none = $this->stale(['phone' => null, 'whatsapp_number' => null]);
        $blanks = $this->stale(['phone' => '', 'whatsapp_number' => '']);
        $hasPhone = $this->stale(['phone' => '+256700123456']);
        $hasWhatsapp = $this->stale(['phone' => null, 'whatsapp_number' => '+256700123456']);

        $ids = $this->ids(FollowUpCandidates::UNREACHABLE);

        $this->assertEqualsCanonicalizing([$none->id, $blanks->id], $ids);
        $this->assertNotContains($hasPhone->id, $ids);
        $this->assertNotContains($hasWhatsapp->id, $ids);
    }

    public function test_a_young_import_is_not_chased_before_a_year_has_passed(): void
    {
        $recent = $this->graduate(['phone' => null]);   // created now: nobody has had a year to answer
        $recent->forceFill(['created_at' => now()->subMonths(4)])->save();

        $this->assertSame([], $this->ids(FollowUpCandidates::UNREACHABLE));
    }

    // ---- narrowing the list --------------------------------------------------------------------------------------

    public function test_the_list_can_be_narrowed_and_is_ordered_newest_graduates_first(): void
    {
        $biology2022 = $this->stale(['phone' => null, 'graduation_year' => 2022, 'graduation_date' => '2022-07-01', 'last_name' => 'Zebra']);
        $biology2024 = $this->stale(['phone' => null, 'graduation_year' => 2024, 'graduation_date' => '2024-07-01', 'last_name' => 'Apple']);
        $chemistry = $this->stale(['phone' => null, 'graduation_year' => 2023, 'graduation_date' => '2023-07-01', 'programme_id' => $this->programme('BSc Chemistry')->id]);

        $this->assertSame([$biology2024->id, $chemistry->id, $biology2022->id], $this->ids(FollowUpCandidates::UNREACHABLE));
        $this->assertSame([$chemistry->id], $this->ids(FollowUpCandidates::UNREACHABLE, ['programme_id' => $chemistry->programme_id]));
        $this->assertSame([$biology2022->id], $this->ids(FollowUpCandidates::UNREACHABLE, ['graduation_year' => 2022]));
        $this->assertSame([$biology2024->id], $this->ids(FollowUpCandidates::UNREACHABLE, ['search' => 'Apple']));
    }

    public function test_searching_cannot_be_used_to_probe_contact_details(): void
    {
        $profile = $this->stale(['phone' => null, 'whatsapp_number' => null, 'email' => 'secret.address@example.test']);

        $this->assertSame([], $this->ids(FollowUpCandidates::UNREACHABLE, ['search' => 'secret.address']));
        $this->assertSame([$profile->id], $this->ids(FollowUpCandidates::UNREACHABLE, ['search' => $profile->student_number]));
    }

    // ---- the page ------------------------------------------------------------------------------------------------------

    public function test_registrar_and_ict_can_open_it_and_nobody_else_can(): void
    {
        foreach ([$this->registrar(), User::factory()->ictAdmin()->create()] as $staff) {
            $this->actingAs($staff)->get(route('admin.follow-up'))->assertOk()->assertSee('Follow-up list');
        }

        $this->actingAs(User::factory()->qaViewer()->create())->get(route('admin.follow-up'))->assertForbidden();
        $this->actingAs(User::factory()->alumnus()->create())->get(route('admin.follow-up'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.follow-up'))->assertRedirect(route('admin.login'));
    }

    public function test_the_page_lists_people_with_a_search_link_that_cannot_be_hijacked(): void
    {
        $profile = $this->stale(['first_name' => 'Amina', 'last_name' => "O'Kello & Co", 'phone' => null]);

        $html = Livewire::actingAs($this->registrar())->test(FollowUp::class)->set('tab', 'unreachable')->html();

        $this->assertStringContainsString('BSc Biology', $html);
        $this->assertStringContainsString('https://www.linkedin.com/search/results/people/?keywords='.rawurlencode("Amina O'Kello & Co Soroti University"), $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString($profile->student_number.'"', strip_tags($html, ''), 'no needless identifiers');
    }

    public function test_a_linkedin_address_the_graduate_gave_is_used_instead_of_a_search(): void
    {
        $this->stale(['phone' => null, 'linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);

        Livewire::actingAs($this->registrar())->test(FollowUp::class)->set('tab', 'unreachable')
            ->assertSee('Their LinkedIn profile')
            ->assertSee('given by the graduate')
            ->assertSeeHtml('href="https://www.linkedin.com/in/amina-okello"')
            ->assertDontSee('Search LinkedIn');
    }

    public function test_tab_counts_and_empty_states_are_honest(): void
    {
        $page = Livewire::actingAs($this->registrar())->test(FollowUp::class)
            ->assertSee('Did not respond (0)')
            ->assertSee('No phone number (0)')
            ->assertSee('Nobody is waiting here');

        $silent = $this->stale();
        $this->nudge($silent, 2);
        $this->stale(['phone' => null]);

        Livewire::actingAs($this->registrar())->test(FollowUp::class)
            ->assertSee('Did not respond (1)')
            ->assertSee('No phone number (1)')
            ->assertSee('2 messages sent, no reply');
    }

    public function test_recording_a_look_stores_who_what_and_a_note_and_removes_the_person_from_the_list(): void
    {
        $profile = $this->stale(['phone' => null]);
        $staff = $this->registrar();

        Livewire::actingAs($staff)->test(FollowUp::class)->set('tab', 'unreachable')
            ->set("outcome.{$profile->id}", 'updated')
            ->set("note.{$profile->id}", 'Now at Stanbic Bank, Kampala')
            ->call('record', $profile->id)
            ->assertHasNoErrors()
            ->assertSee('is recorded as checked')
            ->assertSee('180 days')
            ->assertDontSee('Search LinkedIn');

        $check = FollowUpCheck::sole();
        $this->assertSame($profile->id, $check->alumni_profile_id);
        $this->assertSame($staff->id, $check->checked_by);
        $this->assertSame(FollowUpOutcome::Updated, $check->outcome);
        $this->assertSame('Now at Stanbic Bank, Kampala', $check->note);
    }

    public function test_a_look_is_not_the_alumnus_confirming_their_record(): void
    {
        $profile = $this->stale(['phone' => null]);

        Livewire::actingAs($this->registrar())->test(FollowUp::class)->set('tab', 'unreachable')
            ->set("outcome.{$profile->id}", 'confirmed')->call('record', $profile->id);

        $profile->refresh();
        $this->assertNull($profile->profile_updated_at, 'only the alumnus confirms their own record');
        $this->assertNull($profile->last_survey_completed_at);
    }

    public function test_an_outcome_is_required_and_must_be_a_real_one_and_notes_are_capped(): void
    {
        $profile = $this->stale(['phone' => null]);
        $page = Livewire::actingAs($this->registrar())->test(FollowUp::class)->set('tab', 'unreachable');

        $page->call('record', $profile->id)->assertHasErrors("outcome.{$profile->id}")->assertSee('Say what you found.');
        $page->set("outcome.{$profile->id}", 'made_up')->call('record', $profile->id)->assertHasErrors("outcome.{$profile->id}");
        $page->set("outcome.{$profile->id}", 'not_found')->set("note.{$profile->id}", str_repeat('x', 501))->call('record', $profile->id)->assertHasErrors("note.{$profile->id}");

        $this->assertSame(0, FollowUpCheck::count());

        $page->set("note.{$profile->id}", '')->call('record', $profile->id)->assertHasNoErrors();
        $this->assertNull(FollowUpCheck::sole()->note, 'a blank note is stored as nothing');
    }

    public function test_read_only_staff_cannot_record_a_look_even_with_a_forged_call(): void
    {
        $profile = $this->stale(['phone' => null]);

        Livewire::actingAs(User::factory()->qaViewer()->create())->test(FollowUp::class)->assertForbidden();

        $this->assertSame(0, FollowUpCheck::count());
    }

    public function test_the_page_never_suggests_someone_who_asked_to_be_left_alone(): void
    {
        $this->stale(['first_name' => 'Hidden', 'phone' => null, 'sms_opt_out_at' => now(), 'whatsapp_opt_out_at' => now()]);

        Livewire::actingAs($this->registrar())->test(FollowUp::class)->set('tab', 'unreachable')->assertDontSee('Hidden');
    }

    // ---- on the alumnus record ----------------------------------------------------------------------------------------

    public function test_the_record_page_shows_managers_the_address_and_who_looked(): void
    {
        $profile = $this->graduate(['linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);
        $staff = User::factory()->registrar()->create(['name' => 'Rita Registrar']);
        FollowUpCheck::create(['alumni_profile_id' => $profile->id, 'checked_by' => $staff->id, 'outcome' => FollowUpOutcome::Updated, 'note' => 'Now at Stanbic']);

        Livewire::actingAs($staff)->test(AlumniDetail::class, ['profile' => $profile])
            ->assertSee('Manual follow-up')
            ->assertSeeHtml('href="https://www.linkedin.com/in/amina-okello"')
            ->assertSee('Rita Registrar')
            ->assertSee('Found them and updated the record')
            ->assertSee('Now at Stanbic');
    }

    public function test_read_only_staff_do_not_see_the_address_or_the_checks(): void
    {
        $profile = $this->graduate(['linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);
        FollowUpCheck::create(['alumni_profile_id' => $profile->id, 'outcome' => FollowUpOutcome::NotFound, 'note' => 'private note']);

        $html = Livewire::actingAs(User::factory()->qaViewer()->create())->test(AlumniDetail::class, ['profile' => $profile])->html();

        $this->assertStringNotContainsString('Manual follow-up', $html);
        $this->assertStringNotContainsString('linkedin.com/in/amina-okello', $html);
        $this->assertStringNotContainsString('private note', $html);
    }

    public function test_the_navigation_offers_the_list_to_managers_only(): void
    {
        $this->actingAs($this->registrar())->get(route('admin.dashboard'))->assertSee('Follow-up list');
        $this->actingAs(User::factory()->qaViewer()->create())->get(route('admin.dashboard'))->assertDontSee('Follow-up list');
    }

    public function test_deleting_an_alumnus_record_removes_the_checks_about_them(): void
    {
        $profile = $this->graduate();
        FollowUpCheck::create(['alumni_profile_id' => $profile->id, 'outcome' => FollowUpOutcome::NotFound]);

        $profile->forceDelete();

        $this->assertSame(0, FollowUpCheck::count());
    }
}
