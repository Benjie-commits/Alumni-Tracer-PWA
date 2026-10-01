<?php

namespace Tests\Feature\Phase2;

use App\Enums\NotificationStatus;
use App\Enums\SurveyInvitationStatus;
use App\Enums\VerificationStatus;
use App\Jobs\SendSurveyInvitation;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Models\SurveyInvitation;
use App\Models\TracerSurveyCycle;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Surveys\SurveyScheduler;
use Illuminate\Support\Facades\Queue;

class SurveySchedulerTest extends Phase2TestCase
{
    private function scheduler(): SurveyScheduler
    {
        return app(SurveyScheduler::class);
    }

    // ---- who is due --------------------------------------------------------------------

    public function test_an_alumnus_is_invited_on_the_day_their_six_month_milestone_arrives(): void
    {
        // Now is 2026-10-05. Graduated 2026-04-05, so six months is today.
        $profile = $this->graduate(['graduation_date' => '2026-04-05']);

        $created = $this->scheduler()->schedule();

        $this->assertSame([6 => 1, 12 => 0, 36 => 0], $created);

        $invitation = SurveyInvitation::sole();
        $this->assertSame($profile->id, $invitation->alumni_profile_id);
        $this->assertSame(6, $invitation->cycle->milestone_months);
        $this->assertSame('2026-10-05', $invitation->due_at->setTimezone('Africa/Kampala')->toDateString());
        $this->assertSame('2027-01-03', $invitation->expires_at->setTimezone('Africa/Kampala')->toDateString(), '90-day window');
        $this->assertSame(40, strlen($invitation->token));
    }

    public function test_nobody_is_invited_the_day_before_their_milestone(): void
    {
        $this->graduate(['graduation_date' => '2026-04-06']); // six months is tomorrow

        $this->assertSame([6 => 0, 12 => 0, 36 => 0], $this->scheduler()->schedule());
        $this->assertSame(0, SurveyInvitation::count());
    }

    public function test_the_one_year_and_three_year_milestones_work_too(): void
    {
        $oneYear = $this->graduate(['graduation_date' => '2025-10-05']);
        $threeYear = $this->graduate(['graduation_date' => '2023-10-05']);

        $this->assertSame([6 => 0, 12 => 1, 36 => 1], $this->scheduler()->schedule());

        $this->assertSame(12, SurveyInvitation::where('alumni_profile_id', $oneYear->id)->sole()->cycle->milestone_months);
        $this->assertSame(36, SurveyInvitation::where('alumni_profile_id', $threeYear->id)->sole()->cycle->milestone_months);
    }

    public function test_a_milestone_missed_by_less_than_the_window_still_gets_invited(): void
    {
        // 30 days ago was the six-month milestone: a late start (or a server outage) must not lose them.
        $this->graduate(['graduation_date' => '2026-03-05']);

        $this->assertSame(1, $this->scheduler()->schedule()[6]);
    }

    public function test_switching_the_system_on_does_not_message_people_whose_window_has_closed(): void
    {
        $this->graduate(['graduation_date' => '2025-12-07']); // six-month milestone was 120 days ago

        $this->assertSame([6 => 0, 12 => 0, 36 => 0], $this->scheduler()->schedule());
    }

    public function test_the_window_edge_is_exact(): void
    {
        // Default window 90 days: a milestone 89 days ago is the last day still inside it.
        $inside = $this->graduate(['graduation_date' => '2026-01-08']);   // +6 months = 2026-07-08, 89 days before 2026-10-05
        $outside = $this->graduate(['graduation_date' => '2026-01-07']);  // 90 days before

        $this->scheduler()->schedule();

        $this->assertTrue(SurveyInvitation::where('alumni_profile_id', $inside->id)->exists());
        $this->assertFalse(SurveyInvitation::where('alumni_profile_id', $outside->id)->exists());
    }

    public function test_a_cycle_can_override_the_window(): void
    {
        TracerSurveyCycle::where('milestone_months', 6)->update(['window_days' => 200]);
        $this->graduate(['graduation_date' => '2025-12-07']); // 120 days ago: outside 90, inside 200

        $this->assertSame(1, $this->scheduler()->schedule()[6]);
    }

    public function test_when_only_the_graduation_year_is_known_the_end_of_that_year_is_assumed(): void
    {
        $this->at('2026-07-10 10:00');
        // Year 2025 -> assumed 31 Dec 2025 -> six months is 30 June 2026 (10 days ago).
        $profile = $this->graduate(['graduation_date' => null, 'graduation_year' => 2025]);

        $this->assertSame(1, $this->scheduler()->schedule()[6]);

        $invitation = SurveyInvitation::where('alumni_profile_id', $profile->id)->sole();
        $this->assertSame('2026-06-30', $invitation->due_at->setTimezone('Africa/Kampala')->toDateString());
    }

    public function test_month_end_graduation_dates_clamp_like_a_calendar(): void
    {
        $this->at('2027-02-28 10:00');
        // 31 Aug 2026 + 6 months has no 31 Feb: it lands on the last day, 28 Feb 2027.
        $this->graduate(['graduation_date' => '2026-08-31']);

        $this->assertSame(1, $this->scheduler()->schedule()[6]);
    }

    public function test_a_graduate_with_no_date_at_all_is_never_invited(): void
    {
        $this->graduate(['graduation_date' => null, 'graduation_year' => null]);

        $this->assertSame([6 => 0, 12 => 0, 36 => 0], $this->scheduler()->schedule());
    }

    // ---- who is eligible ---------------------------------------------------------------

    public function test_only_verified_alumni_are_invited_by_default(): void
    {
        foreach ([VerificationStatus::Pending, VerificationStatus::Rejected, VerificationStatus::Unclaimed] as $status) {
            $this->graduate(['verification_status' => $status]);
        }
        $verified = $this->graduate();

        $this->assertSame(1, $this->scheduler()->schedule()[6]);
        $this->assertSame($verified->id, SurveyInvitation::sole()->alumni_profile_id);
    }

    public function test_unregistered_alumni_are_included_only_when_switched_on(): void
    {
        $this->graduate(['verification_status' => VerificationStatus::Unclaimed]);

        $this->assertSame(0, $this->scheduler()->schedule()[6]);

        config(['sunates.surveys.include_unclaimed' => true]);
        $this->assertSame(1, $this->scheduler()->schedule()[6]);
    }

    public function test_someone_who_cannot_be_reached_at_all_is_skipped(): void
    {
        // No phone, no WhatsApp number, no account: there is no way to show them a survey.
        $this->graduate(['phone' => null, 'whatsapp_number' => null, 'user_id' => null]);

        $this->assertSame(0, $this->scheduler()->schedule()[6]);
    }

    public function test_an_alumnus_with_an_account_but_no_phone_still_gets_the_survey_in_the_app(): void
    {
        $profile = $this->registeredGraduate(['phone' => null]);

        $this->assertSame(1, $this->scheduler()->schedule()[6]);

        $invitation = SurveyInvitation::where('alumni_profile_id', $profile->id)->sole();
        $this->assertSame(SurveyInvitationStatus::Scheduled, $invitation->status, 'no message could go out');
        $this->assertTrue($invitation->isOpen());
        $this->assertSame(NotificationStatus::Blocked, NotificationLog::sole()->status);
        $this->assertSame('no_valid_number', NotificationLog::sole()->error);
    }

    public function test_inactive_surveys_are_not_scheduled(): void
    {
        TracerSurveyCycle::where('milestone_months', 6)->update(['is_active' => false]);
        $this->graduate();

        $this->assertArrayNotHasKey(6, $this->scheduler()->schedule());
        $this->assertSame(0, SurveyInvitation::count());
    }

    public function test_soft_deleted_records_are_ignored(): void
    {
        $this->graduate()->delete();

        $this->assertSame(0, $this->scheduler()->schedule()[6]);
    }

    // ---- idempotency and dry runs ------------------------------------------------------

    public function test_running_it_again_creates_and_sends_nothing_new(): void
    {
        $this->graduate();

        $this->scheduler()->schedule();
        $second = $this->scheduler()->schedule();

        $this->assertSame(0, $second[6]);
        $this->assertSame(1, SurveyInvitation::count());
        $this->assertCount(1, $this->whatsapp->sent, 'exactly one message ever');
    }

    public function test_a_dry_run_counts_but_creates_and_sends_nothing(): void
    {
        $this->graduate();
        $this->graduate(['graduation_date' => '2025-10-05']);

        $counts = $this->scheduler()->schedule(dryRun: true);

        $this->assertSame([6 => 1, 12 => 1, 36 => 0], $counts);
        $this->assertSame(0, SurveyInvitation::count());
        $this->assertSame([], $this->whatsapp->sent);
        $this->assertSame([], $this->sms->sent);
    }

    public function test_upcoming_milestones_can_be_previewed(): void
    {
        $cycle = TracerSurveyCycle::where('milestone_months', 6)->first();
        $this->graduate(['graduation_date' => '2026-04-20']); // 15 days from now
        $this->graduate(['graduation_date' => '2026-06-20']); // 2.5 months away
        $this->graduate(['graduation_date' => '2026-04-05']); // due today, not "upcoming"

        $this->assertSame(1, $this->scheduler()->upcomingCount($cycle, 30));
    }

    // ---- the message that goes out -----------------------------------------------------

    public function test_the_invitation_is_sent_and_recorded(): void
    {
        $profile = $this->graduate(['phone' => '0700 111 222', 'first_name' => 'Amina']);

        $this->scheduler()->schedule();

        $invitation = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitationStatus::Sent, $invitation->status);
        $this->assertNotNull($invitation->sent_at);

        // WhatsApp is tried first; parameters are first name, period, link.
        $this->assertCount(1, $this->whatsapp->sent);
        $sent = $this->whatsapp->sent[0];
        $this->assertSame('+256700111222', $sent['to']);
        $this->assertSame('sunates_survey_invite', $sent['template']);
        $this->assertSame(['Amina', '6 months', $invitation->url()], $sent['params']);
        $this->assertStringEndsWith('/s/'.$invitation->token, $invitation->url());

        $log = NotificationLog::sole();
        $this->assertSame($profile->id, $log->alumni_profile_id);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertSame($invitation->id, $log->related_id);
    }

    public function test_the_sms_carries_the_link_and_a_way_to_stop(): void
    {
        $this->smsOnly();
        $profile = $this->graduate(['first_name' => 'Amina']);

        $this->scheduler()->schedule();

        $message = $this->sms->sent[0]['message'];
        $this->assertStringContainsString('Amina', $message);
        $this->assertStringContainsString('6 months', $message);
        $this->assertStringContainsString('/s/'.SurveyInvitation::sole()->token, $message);
        $this->assertStringContainsString('/u/'.$profile->fresh()->unsubscribe_token, $message);
    }

    public function test_the_message_text_is_not_stored_in_the_log(): void
    {
        // It contains the secret survey link; anyone who can read the log must not be able to reuse it.
        $this->smsOnly();
        $this->graduate();

        $this->scheduler()->schedule();

        $token = SurveyInvitation::sole()->token;
        $this->assertStringNotContainsString($token, json_encode(NotificationLog::sole()->getAttributes()));
    }

    public function test_a_failed_send_leaves_the_survey_open_in_the_app_and_is_not_retried_by_the_scheduler(): void
    {
        $this->smsOnly();
        $this->sms->failWith = new PermanentGatewayException('number not reachable');
        $this->graduate();

        $this->scheduler()->schedule();

        $invitation = SurveyInvitation::sole();
        $this->assertSame(SurveyInvitationStatus::Scheduled, $invitation->status);
        $this->assertNull($invitation->sent_at);
        $this->assertTrue($invitation->isOpen());
        $this->assertSame(NotificationStatus::Failed, NotificationLog::sole()->status);

        $this->assertSame(0, $this->scheduler()->schedule()[6], 'no second invitation, so no repeat attempt');
    }

    public function test_messages_wait_for_the_morning_instead_of_waking_people_up(): void
    {
        $this->at('2026-10-05 22:30');
        $this->graduate();

        $this->scheduler()->schedule();

        $this->assertSame(1, SurveyInvitation::count(), 'the invitation exists (and is open in the app)');
        $this->assertSame([], $this->whatsapp->sent, 'but nothing is sent at 22:30');
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_each_invitation_message_is_queued_as_a_job_rather_than_sent_inline(): void
    {
        Queue::fake();
        $this->graduate();
        $this->scheduler()->schedule();

        Queue::assertPushed(SendSurveyInvitation::class, fn ($job) => $job->reminderNumber === 0);
    }

    // ---- reminders and expiry ----------------------------------------------------------

    private function sentInvitation(): SurveyInvitation
    {
        $this->graduate();
        $this->scheduler()->schedule();

        return SurveyInvitation::sole();
    }

    public function test_a_reminder_goes_out_seven_days_after_the_first_message(): void
    {
        $this->smsOnly();
        $invitation = $this->sentInvitation();

        $this->at('2026-10-11 10:00'); // day 6
        $this->assertSame(0, $this->scheduler()->sendReminders());

        $this->at('2026-10-12 10:00'); // day 7
        $this->assertSame(1, $this->scheduler()->sendReminders());

        $invitation->refresh();
        $this->assertSame(1, $invitation->reminders_sent);
        $this->assertCount(2, $this->sms->sent);
        $this->assertStringContainsString('still open', $this->sms->sent[1]['message']);
    }

    public function test_reminders_stop_after_the_configured_number(): void
    {
        $invitation = $this->sentInvitation();

        $this->at('2026-10-12 10:00');
        $this->assertSame(1, $this->scheduler()->sendReminders());
        $this->at('2026-10-26 10:00'); // day 21
        $this->assertSame(1, $this->scheduler()->sendReminders());
        $this->at('2026-11-20 10:00');
        $this->assertSame(0, $this->scheduler()->sendReminders(), 'two reminders is the limit');

        $this->assertSame(2, $invitation->fresh()->reminders_sent);
        $this->assertCount(3, $this->whatsapp->sent);
    }

    public function test_running_the_reminder_step_twice_in_a_day_does_not_double_send(): void
    {
        $this->sentInvitation();
        $this->at('2026-10-12 10:00');

        $this->scheduler()->sendReminders();
        $this->scheduler()->sendReminders();

        $this->assertCount(2, $this->whatsapp->sent, 'one invitation + one reminder');
    }

    public function test_a_completed_survey_is_not_reminded(): void
    {
        $invitation = $this->sentInvitation();
        $invitation->update(['status' => SurveyInvitationStatus::Completed, 'completed_at' => now()]);

        $this->at('2026-10-12 10:00');
        $this->assertSame(0, $this->scheduler()->sendReminders());
    }

    public function test_no_reminder_is_sent_after_the_window_closes(): void
    {
        $this->sentInvitation();

        $this->at('2027-01-10 10:00'); // past the 90-day window
        $this->assertSame(0, $this->scheduler()->sendReminders());
    }

    public function test_a_dry_run_of_reminders_sends_nothing(): void
    {
        $invitation = $this->sentInvitation();
        $this->at('2026-10-12 10:00');

        $this->assertSame(1, $this->scheduler()->sendReminders(dryRun: true));
        $this->assertSame(0, $invitation->fresh()->reminders_sent);
        $this->assertCount(1, $this->whatsapp->sent);
    }

    public function test_unanswered_invitations_expire_when_their_window_closes(): void
    {
        $invitation = $this->sentInvitation();

        $this->at('2027-01-02 10:00');
        $this->assertSame(0, $this->scheduler()->expireOverdue());

        $this->at('2027-01-04 10:00');
        $this->assertSame(1, $this->scheduler()->expireOverdue());
        $this->assertSame(SurveyInvitationStatus::Expired, $invitation->fresh()->status);
    }

    public function test_completed_invitations_never_expire(): void
    {
        $invitation = $this->sentInvitation();
        $invitation->update(['status' => SurveyInvitationStatus::Completed]);

        $this->at('2027-06-01 10:00');
        $this->assertSame(0, $this->scheduler()->expireOverdue());
        $this->assertSame(SurveyInvitationStatus::Completed, $invitation->fresh()->status);
    }

    // ---- the artisan command -----------------------------------------------------------

    public function test_the_command_runs_the_whole_cycle(): void
    {
        $this->graduate();

        $this->artisan('sunates:surveys')->assertSuccessful();

        $this->assertSame(1, SurveyInvitation::count());
        $this->assertCount(1, $this->whatsapp->sent);
    }

    public function test_the_command_dry_run_changes_nothing(): void
    {
        $this->graduate();

        $this->artisan('sunates:surveys --dry-run')->assertSuccessful();

        $this->assertSame(0, SurveyInvitation::count());
        $this->assertSame(0, AlumniProfile::first()->surveyInvitations()->count());
    }
}
