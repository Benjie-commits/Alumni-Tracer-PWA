<?php

namespace Tests\Feature\Phase2;

use App\Enums\MessageTemplate;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\SurveyInvitationStatus;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\AlumniDirectory;
use App\Livewire\Admin\Notifications;
use App\Livewire\Admin\SurveyResponses;
use App\Livewire\Admin\Surveys;
use App\Models\AlumniProfile;
use App\Models\NotificationLog;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Models\User;
use App\Services\Messaging\PermanentGatewayException;
use App\Services\Surveys\SurveyDefinitionSync;
use App\Services\Surveys\SurveySubmissionService;
use Illuminate\Support\Str;
use Livewire\Livewire;

class ConsoleTest extends Phase2TestCase
{
    private function registrar(): User
    {
        return User::factory()->registrar()->create();
    }

    private function ict(): User
    {
        return User::factory()->ictAdmin()->create();
    }

    private function qa(): User
    {
        return User::factory()->qaViewer()->create();
    }

    private function cycle(int $months = 6): TracerSurveyCycle
    {
        return TracerSurveyCycle::where('milestone_months', $months)->firstOrFail();
    }

    private function answered(?AlumniProfile $profile = null, array $answers = []): SurveyResponse
    {
        $profile ??= $this->graduate();
        $invitation = SurveyInvitation::create([
            'tracer_survey_cycle_id' => $this->cycle()->id, 'alumni_profile_id' => $profile->id, 'token' => Str::random(40),
            'status' => SurveyInvitationStatus::Sent, 'due_at' => now()->subDay(), 'expires_at' => now()->addDays(60), 'sent_at' => now()->subDay(),
        ]);

        return app(SurveySubmissionService::class)->submit($invitation, (string) Str::uuid(), $answers + [
            'current_activity' => 'self_employed', 'employer_name' => 'Okello Hardware', 'job_title' => 'Owner',
            'work_related_to_studies' => 'somewhat', 'time_to_first_job' => 'before_graduation',
            'further_study' => 'planned', 'further_study_where' => 'Makerere, MSc', 'started_business' => true, 'business_workers' => 3,
            'ta_interest' => true,
        ])->response;
    }

    // ---- access ------------------------------------------------------------------------

    public function test_every_staff_role_can_see_the_survey_overview_but_only_managers_see_individual_answers(): void
    {
        foreach ([$this->registrar(), $this->ict(), $this->qa()] as $staff) {
            $this->actingAs($staff)->get(route('admin.surveys'))->assertOk()->assertSee('6-month graduate survey');
        }

        $cycle = $this->cycle();
        foreach ([$this->registrar(), $this->ict()] as $manager) {
            $this->actingAs($manager)->get(route('admin.surveys.responses', $cycle))->assertOk();
            $this->actingAs($manager)->get(route('admin.surveys.export', $cycle))->assertOk();
            $this->actingAs($manager)->get(route('admin.notifications'))->assertOk();
        }

        $qa = $this->qa();
        $this->actingAs($qa)->get(route('admin.surveys.responses', $cycle))->assertForbidden();
        $this->actingAs($qa)->get(route('admin.surveys.export', $cycle))->assertForbidden();
        $this->actingAs($qa)->get(route('admin.notifications'))->assertForbidden();
    }

    public function test_the_new_pages_require_signing_in(): void
    {
        $cycle = $this->cycle();

        foreach ([route('admin.surveys'), route('admin.surveys.responses', $cycle), route('admin.surveys.export', $cycle), route('admin.notifications')] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
    }

    public function test_alumni_accounts_cannot_open_them(): void
    {
        $alumnus = User::factory()->alumnus()->create();

        $this->actingAs($alumnus)->get(route('admin.surveys'))->assertForbidden();
        $this->actingAs($alumnus)->get(route('admin.notifications'))->assertForbidden();
    }

    // ---- surveys overview --------------------------------------------------------------

    public function test_the_overview_shows_counts_rates_and_what_is_coming(): void
    {
        $this->answered();
        $this->answered($this->graduate());
        $open = SurveyInvitation::create([
            'tracer_survey_cycle_id' => $this->cycle()->id, 'alumni_profile_id' => $this->graduate()->id, 'token' => Str::random(40),
            'status' => SurveyInvitationStatus::Sent, 'due_at' => now(), 'expires_at' => now()->addDays(60),
        ]);
        $this->graduate(['graduation_date' => '2026-04-20']); // reaches 6 months in 15 days

        $page = Livewire::actingAs($this->registrar())->test(Surveys::class);

        $row = collect($page->viewData('rows'))->firstWhere(fn ($r) => $r['cycle']->milestone_months === 6);
        $this->assertSame(3, $row['total']);
        $this->assertSame(2, $row['completed']);
        $this->assertSame(67, (int) $row['rate']);
        $this->assertSame(1, $row['sent']);
        $this->assertSame(1, $row['upcoming']);
    }

    public function test_the_overview_previews_questions_and_explains_how_to_change_them(): void
    {
        Livewire::actingAs($this->qa())->test(Surveys::class)
            ->assertSee('What are you doing now?')
            ->assertSee('sunates:sync-surveys');
    }

    public function test_managers_can_pause_and_resume_a_survey_but_qa_viewers_cannot(): void
    {
        $cycle = $this->cycle();

        $page = Livewire::actingAs($this->registrar())->test(Surveys::class);
        $page->call('toggleActive', $cycle->id);
        $this->assertFalse($cycle->fresh()->is_active);
        $page->call('toggleActive', $cycle->id);
        $this->assertTrue($cycle->fresh()->is_active);

        Livewire::actingAs($this->qa())->test(Surveys::class)->call('toggleActive', $cycle->id)->assertForbidden();
        $this->assertTrue($cycle->fresh()->is_active);
    }

    public function test_qa_viewers_do_not_even_see_the_buttons(): void
    {
        Livewire::actingAs($this->qa())->test(Surveys::class)->assertDontSee('Pause')->assertDontSee('Responses');
        Livewire::actingAs($this->registrar())->test(Surveys::class)->assertSee('Pause')->assertSee('Responses');
    }

    // ---- responses ---------------------------------------------------------------------

    public function test_responses_are_listed_and_answers_read_in_plain_words(): void
    {
        $response = $this->answered($this->graduate(['first_name' => 'Amina', 'last_name' => 'Okello']));

        Livewire::actingAs($this->registrar())->test(SurveyResponses::class, ['cycle' => $this->cycle()])
            ->assertSee('Okello, Amina')
            ->assertDontSee('Okello Hardware')
            ->call('toggle', $response->id)
            ->assertSee('What are you doing now?')
            ->assertSee('Running my own business or working for myself')
            ->assertSee('Okello Hardware')
            ->assertSee('Yes')
            ->assertDontSee('self_employed');
    }

    public function test_only_this_surveys_responses_are_listed(): void
    {
        $this->answered($this->graduate(['last_name' => 'Sixmonth']));
        $oneYear = SurveyInvitation::create([
            'tracer_survey_cycle_id' => $this->cycle(12)->id, 'alumni_profile_id' => $this->graduate(['last_name' => 'Oneyear'])->id, 'token' => Str::random(40),
            'status' => SurveyInvitationStatus::Sent, 'due_at' => now()->subDay(), 'expires_at' => now()->addDays(60),
        ]);
        app(SurveySubmissionService::class)->submit($oneYear, (string) Str::uuid(), [
            'current_activity' => 'unemployed', 'time_to_first_job' => 'not_yet', 'further_study' => 'none', 'started_business' => false, 'programme_prepared_me' => 3,
        ]);

        Livewire::actingAs($this->registrar())->test(SurveyResponses::class, ['cycle' => $this->cycle(6)])
            ->assertSee('Sixmonth')->assertDontSee('Oneyear');
    }

    public function test_answers_are_shown_with_the_wording_they_were_given_for(): void
    {
        $response = $this->answered();

        // The question is reworded afterwards.
        $milestones = config('tracer_surveys.milestones');
        $milestones[6]['definition']['questions'][0]['label'] = 'Brand new wording of the question';
        app(SurveyDefinitionSync::class)->sync($milestones);

        Livewire::actingAs($this->registrar())->test(SurveyResponses::class, ['cycle' => $this->cycle()])
            ->call('toggle', $response->id)
            ->assertSee('What are you doing now?')
            ->assertDontSee('Brand new wording of the question');
    }

    public function test_qa_viewers_cannot_drive_the_responses_component_directly(): void
    {
        $response = $this->answered();

        Livewire::actingAs($this->qa())->test(SurveyResponses::class, ['cycle' => $this->cycle()])->assertForbidden();
    }

    // ---- export ------------------------------------------------------------------------

    public function test_the_export_has_one_column_per_question_in_readable_words(): void
    {
        $this->answered($this->graduate(['student_number' => 'SU/2026/001', 'first_name' => 'Amina', 'last_name' => 'Okello', 'class_of_award' => 'First Class']));

        $csv = $this->actingAs($this->registrar())->get(route('admin.surveys.export', $this->cycle()))->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $header = $rows[0];
        $data = array_combine($header, $rows[1]);

        $this->assertSame('SU/2026/001', $data['Student number']);
        $this->assertSame('Yes', $data['Teaching-assistant candidate']);
        $this->assertSame('Running my own business or working for myself', $data['What are you doing now?']);
        $this->assertSame('Okello Hardware', $data['Name of your employer or business']);
        $this->assertSame('Yes', $data['Have you started your own business or income-generating activity since graduating?']);
        $this->assertSame('3', $data['How many people work for you, not counting yourself?']);
    }

    public function test_unanswered_questions_export_as_blank_cells(): void
    {
        $this->answered(null, ['current_activity' => 'unemployed']);

        $csv = $this->actingAs($this->registrar())->get(route('admin.surveys.export', $this->cycle()))->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $data = array_combine($rows[0], $rows[1]);

        $this->assertSame('', $data['Name of your employer or business']);
    }

    public function test_the_export_neutralises_spreadsheet_formulas_typed_into_answers(): void
    {
        $this->answered(null, ['employer_name' => '=HYPERLINK("http://evil.example","pay")', 'job_title' => '@SUM(A1)']);

        $csv = $this->actingAs($this->registrar())->get(route('admin.surveys.export', $this->cycle()))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM", $csv);
    }

    public function test_the_export_covers_responses_to_older_questionnaire_versions(): void
    {
        $this->answered(null, ['employer_name' => 'Old Version Ltd']);

        $milestones = config('tracer_surveys.milestones');
        $milestones[6]['definition']['questions'][1]['label'] = 'Where do you work?'; // employer_name reworded
        app(SurveyDefinitionSync::class)->sync($milestones);

        $csv = $this->actingAs($this->registrar())->get(route('admin.surveys.export', $this->cycle()))->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $data = array_combine($rows[0], $rows[1]);

        $this->assertSame('Old Version Ltd', $data['Where do you work?'], 'the same question lands in one column, under its newest wording');
        $this->assertSame('1', $data['Questionnaire version']);
    }

    // ---- notification log --------------------------------------------------------------

    private function log(array $overrides = []): NotificationLog
    {
        $log = (new NotificationLog)->forceFill($overrides + [
            'alumni_profile_id' => $this->graduate()->id, 'channel' => NotificationChannel::Sms,
            'template' => MessageTemplate::SurveyInvite, 'to_number' => '+256700111222',
            'status' => NotificationStatus::Sent, 'provider' => 'mtn',
        ]);
        $log->save();

        return $log;
    }

    public function test_the_log_lists_messages_with_numbers_masked_and_failures_explained(): void
    {
        $this->log(['status' => NotificationStatus::Failed, 'error' => 'MTN refused the credentials']);

        Livewire::actingAs($this->registrar())->test(Notifications::class)
            ->assertSee('MTN refused the credentials')
            ->assertSee('+256 ••• ••• 222')
            ->assertDontSee('+256700111222');
    }

    public function test_the_log_can_be_filtered(): void
    {
        $this->log(['status' => NotificationStatus::Failed, 'error' => 'only-in-failed']);
        $this->log(['status' => NotificationStatus::Delivered, 'channel' => NotificationChannel::Whatsapp]);

        Livewire::actingAs($this->registrar())->test(Notifications::class)
            ->set('status', 'failed')->assertSee('only-in-failed')
            ->set('status', 'delivered')->assertDontSee('only-in-failed')
            ->set('status', '')->set('channel', 'whatsapp')->assertDontSee('only-in-failed');
    }

    public function test_the_counts_cover_the_last_thirty_days_only(): void
    {
        $this->log(['status' => NotificationStatus::Delivered]);
        $this->log(['status' => NotificationStatus::Delivered, 'created_at' => now()->subDays(45)]);
        $this->log(['status' => NotificationStatus::Blocked, 'channel' => null, 'to_number' => null]);

        $counts = Livewire::actingAs($this->registrar())->test(Notifications::class)->viewData('counts');

        $this->assertSame(1, $counts['delivered']);
        $this->assertSame(1, $counts['blocked']);
    }

    public function test_the_page_warns_when_messaging_is_only_being_logged(): void
    {
        config(['sunates.messaging.sms_driver' => 'log', 'sunates.messaging.whatsapp_driver' => 'log']);
        Livewire::actingAs($this->registrar())->test(Notifications::class)->assertSee('log-only mode');

        config(['sunates.messaging.sms_driver' => 'mtn']);
        Livewire::actingAs($this->registrar())->test(Notifications::class)->assertDontSee('log-only mode');
    }

    public function test_ict_can_send_a_test_message_and_it_appears_in_the_log(): void
    {
        Livewire::actingAs($this->ict())->test(Notifications::class)
            ->set('testChannel', 'sms')->set('testNumber', '0700 123 456')
            ->call('sendTest')
            ->assertSee('Accepted by the provider');

        $this->assertSame('+256700123456', $this->sms->sent[0]['to']);
        $this->assertStringContainsString('test message', $this->sms->sent[0]['message']);
        $log = NotificationLog::sole();
        $this->assertNull($log->alumni_profile_id);
        $this->assertSame(NotificationStatus::Sent, $log->status);
    }

    public function test_a_test_message_that_fails_says_why(): void
    {
        $this->sms->failWith = new PermanentGatewayException('MTN SMS is not configured (missing MTN_SMS_CLIENT_ID).');

        Livewire::actingAs($this->ict())->test(Notifications::class)
            ->set('testChannel', 'sms')->set('testNumber', '+256700123456')
            ->call('sendTest')
            ->assertSee('Not sent: MTN SMS is not configured');

        $this->assertSame(NotificationStatus::Failed, NotificationLog::sole()->status);
    }

    public function test_a_test_to_whatsapp_uses_the_test_template(): void
    {
        Livewire::actingAs($this->ict())->test(Notifications::class)
            ->set('testChannel', 'whatsapp')->set('testNumber', '+256700123456')->call('sendTest');

        $this->assertSame('hello_world', $this->whatsapp->sent[0]['template']);
    }

    public function test_a_bad_test_number_is_rejected_without_sending(): void
    {
        Livewire::actingAs($this->ict())->test(Notifications::class)
            ->set('testNumber', 'call me')->call('sendTest')->assertHasErrors('testNumber');

        $this->assertSame([], $this->sms->sent);
    }

    public function test_registrars_can_read_the_log_but_not_send_test_messages(): void
    {
        $page = Livewire::actingAs($this->registrar())->test(Notifications::class)->assertDontSee('Send a test message');

        $page->set('testNumber', '+256700123456')->call('sendTest')->assertForbidden();
        $this->assertSame([], $this->sms->sent);
    }

    // ---- directory and detail ----------------------------------------------------------

    public function test_the_directory_can_show_only_teaching_assistant_candidates(): void
    {
        $this->graduate(['last_name' => 'Flagged', 'ta_flagged_at' => now()]);
        $this->graduate(['last_name' => 'Notflagged']);

        Livewire::actingAs($this->registrar())->test(AlumniDirectory::class)
            ->assertSee('Flagged')->assertSee('Notflagged')
            ->set('taOnly', true)->assertSee('Flagged')->assertDontSee('Notflagged');
    }

    public function test_the_directory_export_marks_teaching_assistant_candidates_and_can_filter_to_them(): void
    {
        $this->graduate(['last_name' => 'Flagged', 'ta_flagged_at' => now()]);
        $this->graduate(['last_name' => 'Notflagged']);

        $all = $this->actingAs($this->registrar())->get(route('admin.alumni.export'))->streamedContent();
        $only = $this->actingAs($this->registrar())->get(route('admin.alumni.export', ['ta' => 1]))->streamedContent();

        $this->assertStringContainsString('Teaching-assistant candidate', $all);
        $this->assertStringContainsString('Notflagged', $all);
        $this->assertStringContainsString('Flagged', $only);
        $this->assertStringNotContainsString('Notflagged', $only);
    }

    public function test_qa_viewers_can_see_the_ta_flag_but_never_contact_details(): void
    {
        $this->graduate(['last_name' => 'Flagged', 'ta_flagged_at' => now(), 'phone' => '0700 999 888', 'email' => 'private@example.com']);

        Livewire::actingAs($this->qa())->test(AlumniDirectory::class)
            ->set('taOnly', true)
            ->assertSee('Flagged')
            ->assertDontSee('private@example.com')
            ->assertDontSee('0700 999 888');
    }

    public function test_a_record_shows_survey_status_and_the_ta_flag(): void
    {
        // Strong enough to stay flagged when they answer yes to the teaching-assistant question.
        $profile = $this->graduate(['class_of_award' => 'First Class', 'ta_flagged_at' => now()]);
        $this->answered($profile);

        Livewire::actingAs($this->qa())->test(AlumniDetail::class, ['profile' => $profile])
            ->assertSee('Teaching-assistant candidate')
            ->assertSee('6-month graduate survey')
            ->assertSee('Completed')
            ->assertDontSee('Okello Hardware', 'answers are not on the record page');
    }

    public function test_a_manager_can_record_a_request_to_stop_messages_and_reverse_it(): void
    {
        $profile = $this->graduate();
        $page = Livewire::actingAs($this->registrar())->test(AlumniDetail::class, ['profile' => $profile]);

        $page->assertSee('Allowed')->call('toggleMessaging')->assertSee('Stopped by the alumnus');
        $profile->refresh();
        $this->assertNotNull($profile->sms_opt_out_at);
        $this->assertNotNull($profile->whatsapp_opt_out_at);

        $page->call('toggleMessaging')->assertSee('Allowed');
        $this->assertNull($profile->fresh()->sms_opt_out_at);
    }

    public function test_qa_viewers_cannot_change_messaging_preferences(): void
    {
        $profile = $this->graduate();

        Livewire::actingAs($this->qa())->test(AlumniDetail::class, ['profile' => $profile])
            ->assertDontSee('Stop messages (at their request)')
            ->call('toggleMessaging')->assertForbidden();

        $this->assertNull($profile->fresh()->sms_opt_out_at);
    }

    public function test_the_dashboard_shows_the_survey_response_rate_and_ta_count(): void
    {
        $this->answered($this->graduate(['class_of_award' => 'First Class']));

        $page = $this->actingAs($this->qa())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Tracer survey response rate')
            ->assertSee('100%')
            ->assertSee('Teaching-assistant candidates');

        $this->assertSame(1, AlumniProfile::taCandidates()->count(), 'answering yes flagged the strong graduate');
        $page->assertSee('View them');
    }
}
