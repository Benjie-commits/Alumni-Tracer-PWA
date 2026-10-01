<?php

namespace Tests\Feature\Phase3;

use App\Enums\EscalationStatus;
use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Livewire\Admin\VerificationEnquiries;
use App\Models\CredentialLink;
use App\Models\CredentialVerificationRequest;
use App\Models\User;
use App\Models\VerificationEscalation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Livewire\Livewire;

class VerificationConsoleTest extends Phase3TestCase
{
    private function enquiry(array $overrides = []): VerificationEscalation
    {
        return VerificationEscalation::create($overrides + [
            'request_reference' => CredentialVerificationRequest::newReference(),
            'organisation' => 'Acme Recruitment Ltd',
            'requester_name' => 'Jane Hiring',
            'requester_email' => 'jane@acme.example',
            'requester_phone' => '+256700123123',
            'subject_name' => 'peter ojok',
            'subject_graduation_year' => 2022,
            'subject_programme' => 'BSc Biology',
            'lookup_result' => VerificationResult::NotFound,
            'message' => 'He says he finished in 2022.',
            'status' => EscalationStatus::Open,
        ]);
    }

    private function lookupLog(array $overrides = []): CredentialVerificationRequest
    {
        return CredentialVerificationRequest::create($overrides + [
            'reference' => CredentialVerificationRequest::newReference(),
            'channel' => VerificationChannel::Portal,
            'organisation' => 'Acme Recruitment Ltd',
            'query_name' => 'amina okello',
            'result' => VerificationResult::Verified,
        ]);
    }

    private function registrar(): User
    {
        return User::factory()->registrar()->create();
    }

    // ---- access ------------------------------------------------------------------------

    public function test_registrar_and_ict_staff_can_open_the_enquiries_page(): void
    {
        foreach ([$this->registrar(), User::factory()->ictAdmin()->create()] as $staff) {
            $this->actingAs($staff)->get(route('admin.verification-enquiries'))->assertOk()->assertSee('Verification enquiries');
        }
    }

    public function test_qa_viewers_alumni_and_guests_cannot(): void
    {
        $this->actingAs(User::factory()->qaViewer()->create())->get(route('admin.verification-enquiries'))->assertForbidden();
        $this->actingAs(User::factory()->alumnus()->create())->get(route('admin.verification-enquiries'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.verification-enquiries'))->assertRedirect(route('admin.login'));
    }

    public function test_the_page_cannot_be_driven_by_a_read_only_viewer(): void
    {
        $e = $this->enquiry();

        Livewire::actingAs(User::factory()->qaViewer()->create())->test(VerificationEnquiries::class)->assertForbidden();
        $this->assertSame(EscalationStatus::Open, $e->fresh()->status);
    }

    // ---- working an enquiry ------------------------------------------------------------

    public function test_waiting_enquiries_show_who_asked_and_what_about(): void
    {
        $this->enquiry();

        Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class)
            ->assertSee('Asked about: peter ojok')
            ->assertSee('Acme Recruitment Ltd')
            ->assertSee('Jane Hiring')
            ->assertSee('jane@acme.example')
            ->assertSee('BSc Biology')
            ->assertSee('He says he finished in 2022.');
    }

    public function test_the_oldest_enquiry_comes_first(): void
    {
        $this->enquiry(['subject_name' => 'newer person']);
        $old = $this->enquiry(['subject_name' => 'older person']);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $page = Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class);

        $html = $page->html();
        $this->assertLessThan(strpos($html, 'newer person'), strpos($html, 'older person'));
    }

    public function test_marking_an_enquiry_dealt_with_records_who_and_when_and_a_note(): void
    {
        $e = $this->enquiry();
        $staff = $this->registrar();

        Livewire::actingAs($staff)->test(VerificationEnquiries::class)
            ->set("notes.{$e->id}", 'Confirmed by email: graduated 2022, BSc Biology')
            ->call('resolve', $e->id)
            ->assertHasNoErrors();

        $e->refresh();
        $this->assertSame(EscalationStatus::Resolved, $e->status);
        $this->assertSame($staff->id, $e->resolved_by);
        $this->assertNotNull($e->resolved_at);
        $this->assertSame('Confirmed by email: graduated 2022, BSc Biology', $e->resolution_note);
    }

    public function test_a_note_is_optional_and_limited_in_length(): void
    {
        $e = $this->enquiry();
        $page = Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class);

        $page->set("notes.{$e->id}", str_repeat('x', 1001))->call('resolve', $e->id)->assertHasErrors("notes.{$e->id}");
        $this->assertSame(EscalationStatus::Open, $e->fresh()->status);

        $page->set("notes.{$e->id}", '')->call('resolve', $e->id)->assertHasNoErrors();
        $this->assertNull($e->fresh()->resolution_note);
    }

    public function test_resolved_enquiries_move_to_their_own_tab_and_cannot_be_resolved_twice(): void
    {
        $e = $this->enquiry();
        $page = Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class)->call('resolve', $e->id);

        $page->assertDontSee('Asked about: peter ojok')
            ->set('tab', 'resolved')
            ->assertSee('Asked about: peter ojok')
            ->assertSee('Resolved');

        $page->call('resolve', $e->id)->assertNotFound();
    }

    public function test_the_waiting_count_appears_in_the_page_and_the_navigation(): void
    {
        $this->enquiry();
        $this->enquiry();

        $this->actingAs($this->registrar())->get(route('admin.dashboard'))->assertSee('Employer enquiries');
        Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class)->assertSee('Waiting (2)');
    }

    // ---- the lookup log ----------------------------------------------------------------

    public function test_the_log_lists_every_lookup_and_summarises_the_month(): void
    {
        $this->lookupLog(['result' => VerificationResult::Verified]);
        $this->lookupLog(['result' => VerificationResult::NotFound, 'query_name' => 'peter ojok']);
        $this->lookupLog(['result' => VerificationResult::Ambiguous, 'query_name' => 'common name']);
        $old = $this->lookupLog(['query_name' => 'ancient lookup']);
        $old->forceFill(['created_at' => now()->subDays(45)])->save();

        $page = Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class)->set('tab', 'log');

        $page->assertSee('peter ojok')->assertSee('common name')->assertSee('ancient lookup')->assertSee('Acme Recruitment Ltd');
        $counts = $page->viewData('counts');
        $this->assertSame(1, $counts['verified'], 'the 45-day-old lookup is outside the 30-day summary');
        $this->assertSame(1, $counts['not_found']);
        $this->assertSame(1, $counts['ambiguous']);
    }

    public function test_a_shared_link_view_is_shown_as_such_in_the_log(): void
    {
        $this->lookupLog(['channel' => VerificationChannel::Link, 'organisation' => null, 'query_name' => null]);

        Livewire::actingAs($this->registrar())->test(VerificationEnquiries::class)->set('tab', 'log')
            ->assertSee('via shared link')->assertSee('Alumnus link');
    }

    // ---- retention ---------------------------------------------------------------------

    public function test_old_lookups_and_resolved_enquiries_are_deleted_but_open_ones_never_are(): void
    {
        config(['sunates.verification.log_retention_days' => 730]);

        $recent = $this->lookupLog(['query_name' => 'recent']);
        $ancient = $this->lookupLog(['query_name' => 'ancient']);
        $ancient->forceFill(['created_at' => now()->subDays(731)])->save();

        $openOld = $this->enquiry(['subject_name' => 'still waiting']);
        $openOld->forceFill(['created_at' => now()->subDays(900)])->save();
        $resolvedOld = $this->enquiry(['subject_name' => 'long done', 'status' => EscalationStatus::Resolved, 'resolved_at' => now()->subDays(800)]);
        $resolvedRecent = $this->enquiry(['subject_name' => 'done last week', 'status' => EscalationStatus::Resolved, 'resolved_at' => now()->subDays(7)]);

        $this->artisan('sunates:prune-verification-data')->assertSuccessful();

        $this->assertTrue(CredentialVerificationRequest::whereKey($recent->id)->exists());
        $this->assertFalse(CredentialVerificationRequest::whereKey($ancient->id)->exists());
        $this->assertTrue(VerificationEscalation::whereKey($openOld->id)->exists(), 'unresolved work is never pruned, however old');
        $this->assertFalse(VerificationEscalation::whereKey($resolvedOld->id)->exists());
        $this->assertTrue(VerificationEscalation::whereKey($resolvedRecent->id)->exists());
    }

    public function test_dead_credential_links_are_cleared_after_a_month_but_live_ones_stay(): void
    {
        $profile = $this->graduate();
        $make = fn (array $a) => CredentialLink::create($a + ['alumni_profile_id' => $profile->id, 'token' => Str::random(40), 'expires_at' => now()->addDays(30)]);

        $live = $make([]);
        $justExpired = $make(['expires_at' => now()->subDays(5)]);
        $longExpired = $make(['expires_at' => now()->subDays(40)]);
        $longRevoked = $make(['revoked_at' => now()->subDays(40)]);
        $justRevoked = $make(['revoked_at' => now()->subDays(2)]);

        $this->artisan('sunates:prune-verification-data')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$live->id, $justExpired->id, $justRevoked->id],
            CredentialLink::pluck('id')->all(),
        );
        $this->assertFalse(CredentialLink::whereKey([$longExpired->id, $longRevoked->id])->exists());
    }

    public function test_the_prune_dry_run_deletes_nothing(): void
    {
        $ancient = $this->lookupLog();
        $ancient->forceFill(['created_at' => now()->subDays(900)])->save();

        $this->artisan('sunates:prune-verification-data --dry-run')->expectsOutputToContain('Would delete 1 lookup log entries')->assertSuccessful();

        $this->assertTrue(CredentialVerificationRequest::whereKey($ancient->id)->exists());
    }

    public function test_pruning_is_on_the_monthly_schedule(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command)->filter(fn ($c) => str_contains((string) $c, 'sunates:prune-verification-data'));

        $this->assertCount(1, $scheduled);
    }
}
