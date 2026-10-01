<?php

namespace Tests\Feature\Phase2;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\VerificationStatus;
use App\Jobs\SendProfileNudge;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Messaging\NotificationService;
use App\Services\Nudges\ProfileNudger;
use Illuminate\Support\Facades\Queue;

class NudgeTest extends Phase2TestCase
{
    /** A verified alumnus whose record nobody has touched for two years (so: stale). */
    private function stale(array $attributes = []): AlumniProfile
    {
        return $this->graduate($attributes + [
            'created_at' => now()->subYears(2),
            'profile_updated_at' => now()->subYears(2),
        ]);
    }

    private function nudger(): ProfileNudger
    {
        return app(ProfileNudger::class);
    }

    private function logNudge(AlumniProfile $profile, string $daysAgo, NotificationStatus $status = NotificationStatus::Sent, MessageTemplate $template = MessageTemplate::ProfileNudge): void
    {
        // forceFill: created_at is not mass-assignable, and a "31 days ago" log silently created as "now"
        // would make several of these tests pass for the wrong reason.
        (new NotificationLog)->forceFill([
            'alumni_profile_id' => $profile->id, 'channel' => NotificationChannel::Sms, 'template' => $template,
            'status' => $status, 'created_at' => now()->subDays((int) $daysAgo), 'updated_at' => now()->subDays((int) $daysAgo),
        ])->save();
    }

    // ---- who is stale ------------------------------------------------------------------

    public function test_a_stale_record_gets_a_nudge_carrying_the_link(): void
    {
        $profile = $this->stale(['first_name' => 'Amina']);
        $this->registeredGraduateFrom($profile);

        $result = $this->nudger()->run();

        $this->assertSame(['eligible' => 1, 'queued' => 1], $result);
        $sent = $this->whatsapp->sent[0];
        $this->assertSame('sunates_profile_nudge', $sent['template']);
        $this->assertSame(['Amina', config('sunates.pwa_url')], $sent['params']);
        $this->assertSame(MessageTemplate::ProfileNudge, NotificationLog::sole()->template);
    }

    public function test_a_recently_updated_record_is_left_alone(): void
    {
        $this->stale(['profile_updated_at' => now()->subMonths(11)]);

        $this->assertSame(0, $this->nudger()->run()['eligible']);
    }

    public function test_the_threshold_is_one_year(): void
    {
        $justUnder = $this->stale(['profile_updated_at' => now()->subDays(364)]);
        $justOver = $this->stale(['profile_updated_at' => now()->subDays(366)]);

        $ids = $this->nudger()->candidates(now())->pluck('id')->all();

        $this->assertSame([$justOver->id], $ids);
        $this->assertNotContains($justUnder->id, $ids);
    }

    public function test_answering_a_survey_counts_as_fresh(): void
    {
        $this->stale(['last_survey_completed_at' => now()->subMonths(2)]);

        $this->assertSame(0, $this->nudger()->run()['eligible']);
    }

    public function test_a_brand_new_record_that_was_never_edited_is_not_stale_yet(): void
    {
        $this->graduate(['profile_updated_at' => null, 'created_at' => now()->subMonths(3)]);

        $this->assertSame(0, $this->nudger()->run()['eligible']);
    }

    public function test_a_never_edited_record_becomes_stale_a_year_after_it_was_created(): void
    {
        $this->graduate(['profile_updated_at' => null, 'created_at' => now()->subMonths(14)]);

        $this->assertSame(1, $this->nudger()->run()['eligible']);
    }

    // ---- politeness --------------------------------------------------------------------

    public function test_nobody_is_nudged_twice_in_a_quarter(): void
    {
        $profile = $this->stale();
        $this->logNudge($profile, '30');

        $this->assertSame(0, $this->nudger()->run()['eligible']);

        $this->logNudge($profile, '30'); // still within 90 days
        $this->assertSame(0, $this->nudger()->run()['eligible']);
    }

    public function test_after_the_gap_a_person_can_be_nudged_again(): void
    {
        $profile = $this->stale();
        $this->logNudge($profile, '91');

        $this->assertSame(1, $this->nudger()->run()['eligible']);
    }

    public function test_no_more_than_three_nudges_in_a_year(): void
    {
        $profile = $this->stale();
        foreach (['100', '200', '300'] as $daysAgo) {
            $this->logNudge($profile, $daysAgo);
        }

        $this->assertSame(0, $this->nudger()->run()['eligible']);
    }

    public function test_the_yearly_count_forgets_nudges_older_than_a_year(): void
    {
        $profile = $this->stale();
        foreach (['100', '200', '400'] as $daysAgo) { // only two in the last year
            $this->logNudge($profile, $daysAgo);
        }

        $this->assertSame(1, $this->nudger()->run()['eligible']);
    }

    public function test_failed_or_blocked_attempts_do_not_use_up_the_allowance(): void
    {
        $profile = $this->stale();
        foreach (['10', '20', '30'] as $daysAgo) {
            $this->logNudge($profile, $daysAgo, NotificationStatus::Failed);
        }
        $this->logNudge($profile, '5', NotificationStatus::Blocked);

        $this->assertSame(1, $this->nudger()->run()['eligible']);
    }

    public function test_someone_we_messaged_this_fortnight_about_anything_is_left_alone(): void
    {
        $profile = $this->stale();
        $this->logNudge($profile, '5', NotificationStatus::Delivered, MessageTemplate::SurveyInvite);

        $this->assertSame(0, $this->nudger()->run()['eligible']);

        NotificationLog::query()->update(['created_at' => now()->subDays(15)]);
        $this->assertSame(1, $this->nudger()->run()['eligible']);
    }

    public function test_alumni_who_stopped_all_messages_are_never_nudged_but_one_channel_is_enough(): void
    {
        $stopped = $this->stale();
        $stopped->setOptOut(null, true);
        $stopped->save();

        $smsOnly = $this->stale(['phone' => '0700 333 444']);
        $smsOnly->setOptOut(NotificationChannel::Whatsapp, true);
        $smsOnly->save();

        $ids = $this->nudger()->candidates(now())->pluck('id')->all();

        $this->assertSame([$smsOnly->id], $ids);
    }

    // ---- who can be reached ------------------------------------------------------------

    public function test_only_verified_alumni_with_a_phone_are_considered(): void
    {
        $this->stale(['phone' => null, 'whatsapp_number' => null]);
        foreach ([VerificationStatus::Pending, VerificationStatus::Rejected, VerificationStatus::Unclaimed] as $status) {
            $this->stale(['verification_status' => $status]);
        }
        $good = $this->stale();

        $this->assertSame([$good->id], $this->nudger()->candidates(now())->pluck('id')->all());
    }

    public function test_unregistered_alumni_are_invited_to_join_only_when_switched_on(): void
    {
        $unregistered = $this->stale(['verification_status' => VerificationStatus::Unclaimed, 'first_name' => 'Peter']);

        $this->assertSame(0, $this->nudger()->run()['eligible']);

        config(['sunates.nudges.include_unclaimed' => true]);
        $this->assertSame(1, $this->nudger()->run()['eligible']);

        $this->assertSame('sunates_register_invite', $this->whatsapp->sent[0]['template']);
        $this->assertSame(['Peter', config('sunates.pwa_url').'/register'], $this->whatsapp->sent[0]['params']);
        $this->assertSame(MessageTemplate::RegisterInvite, NotificationLog::sole()->template);
        $this->assertSame($unregistered->id, NotificationLog::sole()->alumni_profile_id);
    }

    public function test_soft_deleted_records_are_not_nudged(): void
    {
        $this->stale()->delete();

        $this->assertSame(0, $this->nudger()->run()['eligible']);
    }

    // ---- budget and switches -----------------------------------------------------------

    public function test_the_daily_limit_caps_a_run_and_the_longest_neglected_go_first(): void
    {
        config(['sunates.nudges.daily_limit' => 2]);
        $newest = $this->stale(['profile_updated_at' => now()->subYears(1)->subDays(5)]);
        $oldest = $this->stale(['profile_updated_at' => now()->subYears(4)]);
        $middle = $this->stale(['profile_updated_at' => now()->subYears(2)]);
        $this->smsOnly();

        Queue::fake();
        $result = $this->nudger()->run();

        $this->assertSame(['eligible' => 3, 'queued' => 2], $result);
        Queue::assertPushed(SendProfileNudge::class, 2);
        Queue::assertPushed(SendProfileNudge::class, fn ($job) => $job->profileId === $oldest->id);
        Queue::assertPushed(SendProfileNudge::class, fn ($job) => $job->profileId === $middle->id);
        Queue::assertNotPushed(SendProfileNudge::class, fn ($job) => $job->profileId === $newest->id);
    }

    public function test_a_dry_run_reports_but_sends_nothing(): void
    {
        $this->stale();

        $result = $this->nudger()->run(dryRun: true);

        $this->assertSame(['eligible' => 1, 'queued' => 1], $result);
        $this->assertSame(0, NotificationLog::count());
        $this->assertSame([], $this->whatsapp->sent);
    }

    public function test_nudges_can_be_switched_off(): void
    {
        config(['sunates.nudges.enabled' => false]);
        $this->stale();

        $this->assertSame(['eligible' => 0, 'queued' => 0], $this->nudger()->run());
        $this->assertSame([], $this->whatsapp->sent);
    }

    public function test_nothing_is_sent_at_night_and_the_job_is_not_lost(): void
    {
        $this->stale();
        $this->at('2026-10-05 23:00');

        Queue::fake();
        $this->nudger()->run();

        Queue::assertPushed(SendProfileNudge::class, 1);
    }

    public function test_the_job_itself_will_not_send_during_quiet_hours(): void
    {
        $profile = $this->stale();
        $this->at('2026-10-05 23:00');

        (new SendProfileNudge($profile->id))->handle(app(NotificationService::class));

        $this->assertSame([], $this->whatsapp->sent);
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_a_duplicate_job_the_same_day_does_not_send_twice(): void
    {
        $profile = $this->stale();
        $service = app(NotificationService::class);

        (new SendProfileNudge($profile->id))->handle($service);
        (new SendProfileNudge($profile->id))->handle($service);

        $this->assertCount(1, $this->whatsapp->sent);
    }

    public function test_the_command_reports_and_respects_dry_run(): void
    {
        $this->stale();

        $this->artisan('sunates:nudges --dry-run')->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(0, NotificationLog::count());

        $this->artisan('sunates:nudges')->assertSuccessful();
        $this->assertSame(1, NotificationLog::count());
    }

    /** Give a profile an alumni account (the nudge asks registered alumni to confirm their record). */
    private function registeredGraduateFrom(AlumniProfile $profile): void
    {
        $profile->update(['user_id' => User::factory()->alumnus()->create()->id]);
    }
}
