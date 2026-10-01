<?php

namespace Tests\Feature\Phase2;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Models\TracerSurveyCycle;
use App\Models\TracerSurveyVersion;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\TransientGatewayException;
use App\Services\Surveys\SurveyDefinitionSync;
use InvalidArgumentException;
use Tests\Support\FakeSmsGateway;

class SurveySyncAndStatusTest extends Phase2TestCase
{
    private function sync(?array $milestones = null): array
    {
        return app(SurveyDefinitionSync::class)->sync($milestones);
    }

    // ---- questionnaire versions --------------------------------------------------------

    public function test_the_three_milestone_surveys_are_loaded_with_a_first_version(): void
    {
        $cycles = TracerSurveyCycle::orderBy('milestone_months')->get();

        $this->assertSame([6, 12, 36], $cycles->pluck('milestone_months')->all());
        $this->assertSame(['6 months', '1 year', '3 years'], $cycles->map->periodLabel()->all());
        $this->assertSame(['6-month graduate survey', '1-year graduate survey', '3-year graduate survey'], $cycles->pluck('title')->all());

        foreach ($cycles as $cycle) {
            $this->assertTrue($cycle->is_active);
            $this->assertSame(1, $cycle->currentVersion->version);
            $this->assertNotEmpty($cycle->currentVersion->questions());
        }
    }

    public function test_syncing_unchanged_questions_changes_nothing(): void
    {
        $before = TracerSurveyVersion::count();

        $results = $this->sync();

        $this->assertSame([6 => 'unchanged', 12 => 'unchanged', 36 => 'unchanged'], $results);
        $this->assertSame($before, TracerSurveyVersion::count());
    }

    public function test_changing_a_question_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $cycle = TracerSurveyCycle::where('milestone_months', 6)->first();
        $original = $cycle->current_version_id;

        $milestones = config('tracer_surveys.milestones');
        $milestones[6]['definition']['questions'][0]['label'] = 'What are you up to these days?';
        $results = $this->sync($milestones);

        $this->assertSame('updated', $results[6]);
        $this->assertSame('unchanged', $results[12]);

        $cycle->refresh();
        $this->assertNotSame($original, $cycle->current_version_id);
        $this->assertSame(2, $cycle->currentVersion->version);
        $this->assertSame('What are you up to these days?', $cycle->currentVersion->questions()[0]['label']);

        $old = TracerSurveyVersion::find($original);
        $this->assertNotSame('What are you up to these days?', $old->questions()[0]['label'], 'the old wording is preserved for old answers');
    }

    public function test_reverting_a_change_creates_yet_another_version_rather_than_rewriting_history(): void
    {
        $changed = config('tracer_surveys.milestones');
        $changed[6]['definition']['intro'] = 'Changed';
        $this->sync($changed);
        $this->sync(); // back to the original text

        $this->assertSame([1, 2, 3], TracerSurveyCycle::where('milestone_months', 6)->first()->versions()->orderBy('version')->pluck('version')->all());
    }

    public function test_renaming_a_survey_updates_the_title_only(): void
    {
        $milestones = config('tracer_surveys.milestones');
        $milestones[12]['title'] = 'First anniversary survey';

        $this->assertSame('updated', $this->sync($milestones)[12]);

        $cycle = TracerSurveyCycle::where('milestone_months', 12)->first();
        $this->assertSame('First anniversary survey', $cycle->title);
        $this->assertSame(1, $cycle->versions()->count(), 'no new version for a title change');
    }

    public function test_an_invalid_definition_changes_nothing_at_all(): void
    {
        $versionsBefore = TracerSurveyVersion::count();

        $milestones = config('tracer_surveys.milestones');
        $milestones[6]['definition']['intro'] = 'Would be a valid change';
        $milestones[36]['definition']['questions'][0]['type'] = 'telepathy'; // broken

        try {
            $this->sync($milestones);
            $this->fail('Expected the broken definition to be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("unknown type 'telepathy'", $e->getMessage());
        }

        $this->assertSame($versionsBefore, TracerSurveyVersion::count(), 'the valid change must not be half-applied');
    }

    public function test_the_sync_command_reports_each_survey(): void
    {
        $this->artisan('sunates:sync-surveys')
            ->expectsOutputToContain(' 6-month survey: unchanged')
            ->expectsOutputToContain('36-month survey: unchanged')
            ->assertSuccessful();
    }

    public function test_the_sync_command_fails_clearly_on_a_broken_config(): void
    {
        $milestones = config('tracer_surveys.milestones');
        $milestones[12]['definition']['questions'] = [];
        config(['tracer_surveys.milestones' => $milestones]);

        $this->artisan('sunates:sync-surveys')
            ->expectsOutputToContain('Survey definitions are invalid')
            ->assertFailed();
    }

    // ---- SMS delivery status polling ---------------------------------------------------

    private function smsLog(array $overrides = []): NotificationLog
    {
        // forceFill: created_at is not mass-assignable.
        $log = (new NotificationLog)->forceFill($overrides + [
            'alumni_profile_id' => $this->graduate()->id,
            'channel' => NotificationChannel::Sms,
            'template' => MessageTemplate::SurveyInvite,
            'status' => NotificationStatus::Sent,
            'provider' => 'mtn',
            'provider_message_id' => 'mtn-'.uniqid(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $log->save();

        return $log;
    }

    public function test_delivered_and_failed_messages_are_picked_up_from_the_provider(): void
    {
        $delivered = $this->smsLog();
        $failed = $this->smsLog();
        $pending = $this->smsLog();
        $this->sms->statuses = [
            $delivered->provider_message_id => NotificationStatus::Delivered,
            $failed->provider_message_id => NotificationStatus::Failed,
            $pending->provider_message_id => NotificationStatus::Sent,
        ];

        $this->artisan('sunates:sync-delivery-status')->assertSuccessful();

        $this->assertSame(NotificationStatus::Delivered, $delivered->fresh()->status);
        $this->assertNotNull($delivered->fresh()->delivered_at);
        $this->assertSame(NotificationStatus::Failed, $failed->fresh()->status);
        $this->assertNotNull($failed->fresh()->failed_at);
        $this->assertSame(NotificationStatus::Sent, $pending->fresh()->status);
    }

    public function test_only_recent_provider_sms_that_are_still_only_sent_are_polled(): void
    {
        $tooNew = $this->smsLog(['created_at' => now()->subSeconds(30)]);
        $tooOld = $this->smsLog(['created_at' => now()->subDays(4)]);
        $devLog = $this->smsLog(['provider' => 'log']);
        $whatsapp = $this->smsLog(['channel' => NotificationChannel::Whatsapp]);
        $alreadyDone = $this->smsLog(['status' => NotificationStatus::Delivered]);
        $noId = $this->smsLog(['provider_message_id' => null]);

        foreach ([$tooNew, $tooOld, $devLog, $whatsapp, $alreadyDone] as $log) {
            $this->sms->statuses[$log->provider_message_id] = NotificationStatus::Failed;
        }

        $this->artisan('sunates:sync-delivery-status')->expectsOutputToContain('Checked 0 message(s)')->assertSuccessful();

        $this->assertSame(NotificationStatus::Sent, $tooNew->fresh()->status);
        $this->assertSame(NotificationStatus::Sent, $tooOld->fresh()->status);
        $this->assertSame(NotificationStatus::Sent, $devLog->fresh()->status);
        $this->assertSame(NotificationStatus::Sent, $noId->fresh()->status);
    }

    public function test_a_struggling_provider_stops_the_run_without_damaging_anything(): void
    {
        $log = $this->smsLog();
        $this->sms = new class extends FakeSmsGateway
        {
            public function deliveryStatus(string $providerMessageId): ?NotificationStatus
            {
                throw new TransientGatewayException('MTN is down');
            }
        };
        $this->app->instance(SmsGateway::class, $this->sms);

        $this->artisan('sunates:sync-delivery-status')->assertSuccessful();

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
    }
}
