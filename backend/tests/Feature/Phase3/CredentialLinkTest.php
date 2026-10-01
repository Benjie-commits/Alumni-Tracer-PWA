<?php

namespace Tests\Feature\Phase3;

use App\Enums\VerificationChannel;
use App\Enums\VerificationResult;
use App\Enums\VerificationStatus;
use App\Models\CredentialLink;
use App\Models\CredentialVerificationRequest;
use App\Models\User;
use App\Services\Verification\CredentialLinkService;

class CredentialLinkTest extends Phase3TestCase
{
    private function create(User $user)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/credential-links');
    }

    public function test_a_verified_alumnus_can_create_a_link_to_give_an_employer(): void
    {
        $user = $this->alumnusAccount();

        $response = $this->create($user)->assertCreated();

        $token = CredentialLink::sole()->token;
        $this->assertSame(40, strlen($token));
        $this->assertStringEndsWith('/verify/'.$token, $response->json('data.url'));
        $this->assertSame(now()->addDays(90)->toIso8601String(), $response->json('data.expires_at'));
        $this->assertSame(0, $response->json('data.views'));
    }

    public function test_an_employer_opening_the_link_sees_who_graduated_with_what_and_when(): void
    {
        $profile = $this->graduate(['first_name' => 'Amina', 'last_name' => 'Okello', 'student_number' => 'SU/2020/777', 'email' => 'private@example.com']);
        $user = $this->alumnusAccount($profile);
        $link = app(CredentialLinkService::class)->issue($profile);

        $this->get('/verify/'.$link->token)
            ->assertOk()
            ->assertSee('Amina Okello')
            ->assertSee('BSc Biology')
            ->assertSee('2024')
            ->assertSee('Verified')
            ->assertDontSee('SU/2020/777')
            ->assertDontSee('private@example.com');
    }

    public function test_opening_a_link_counts_the_view_and_is_logged(): void
    {
        $profile = $this->graduate();
        $this->alumnusAccount($profile);
        $link = app(CredentialLinkService::class)->issue($profile);

        $this->get('/verify/'.$link->token);
        $this->get('/verify/'.$link->token);

        $link->refresh();
        $this->assertSame(2, $link->views);
        $this->assertNotNull($link->last_viewed_at);

        $log = CredentialVerificationRequest::where('channel', VerificationChannel::Link)->get();
        $this->assertCount(2, $log);
        $this->assertSame(VerificationResult::Verified, $log[0]->result);
        $this->assertSame($profile->id, $log[0]->matched_profile_id);
        $this->assertNull($log[0]->query_name, 'a link view has no typed query');
    }

    public function test_the_alumnus_can_see_their_links_and_how_often_they_were_opened(): void
    {
        $profile = $this->graduate();
        $user = $this->alumnusAccount($profile);
        $link = app(CredentialLinkService::class)->issue($profile);
        $this->get('/verify/'.$link->token);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/credential-links')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.views', 1)->assertJsonPath('meta.can_create', true)
            ->assertJsonPath('meta.valid_days', 90)->assertJsonPath('meta.max_active', 5);
    }

    // ---- who can have one --------------------------------------------------------------

    public function test_someone_the_registrar_cannot_confirm_gets_no_link(): void
    {
        foreach ([
            ['verification_status' => VerificationStatus::Pending],
            ['verification_status' => VerificationStatus::Verified, 'student_number' => null],
            ['verification_status' => VerificationStatus::Verified, 'graduation_year' => 2030, 'graduation_date' => null],
        ] as $attributes) {
            $profile = $this->graduate($attributes);
            $user = User::factory()->alumnus()->create();
            $profile->update(['user_id' => $user->id]);

            $this->create($user)->assertUnprocessable()->assertJsonValidationErrors('profile');
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/credential-links')->assertJsonPath('meta.can_create', false);
        }

        $this->assertSame(0, CredentialLink::count());
    }

    public function test_there_is_a_limit_on_active_links(): void
    {
        $user = $this->alumnusAccount();

        foreach (range(1, 5) as $i) {
            $this->create($user)->assertCreated();
        }

        $this->create($user)->assertUnprocessable()->assertJsonValidationErrors('profile');
    }

    public function test_withdrawn_and_expired_links_do_not_count_against_the_limit(): void
    {
        $user = $this->alumnusAccount();
        foreach (range(1, 5) as $i) {
            $this->create($user);
        }
        CredentialLink::first()->update(['revoked_at' => now()]);
        CredentialLink::skip(1)->first()->update(['expires_at' => now()->subDay()]);

        $this->create($user)->assertCreated();
    }

    // ---- when a link stops working -----------------------------------------------------

    public function test_unknown_expired_and_revoked_links_all_look_the_same_to_the_employer(): void
    {
        $profile = $this->graduate();
        $this->alumnusAccount($profile);
        $service = app(CredentialLinkService::class);
        $expired = $service->issue($profile);
        $expired->update(['expires_at' => now()->subSecond()]);
        $revoked = $service->issue($profile);
        $service->revoke($revoked);

        $this->get('/verify/'.str_repeat('z', 40))->assertNotFound();
        $this->get('/verify/'.$expired->token)->assertNotFound();
        $this->get('/verify/'.$revoked->token)->assertNotFound();
        $this->assertSame(0, CredentialVerificationRequest::count(), 'dead links are not logged as verifications');
    }

    public function test_a_link_stops_working_if_the_owner_can_no_longer_be_confirmed(): void
    {
        $profile = $this->graduate();
        $this->alumnusAccount($profile);
        $link = app(CredentialLinkService::class)->issue($profile);
        $this->get('/verify/'.$link->token)->assertOk();

        $profile->update(['verification_status' => VerificationStatus::Rejected]);

        $this->get('/verify/'.$link->token)->assertNotFound();
    }

    public function test_the_alumnus_can_withdraw_a_link(): void
    {
        $profile = $this->graduate();
        $user = $this->alumnusAccount($profile);
        $link = app(CredentialLinkService::class)->issue($profile);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/me/credential-links/{$link->id}")->assertNoContent();

        $this->get('/verify/'.$link->token)->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/credential-links')->assertJsonCount(0, 'data');
    }

    public function test_one_alumnus_cannot_withdraw_or_see_anothers_links(): void
    {
        $mine = $this->alumnusAccount($this->graduate());
        $other = $this->graduate(['first_name' => 'Peter', 'last_name' => 'Ojok']);
        $theirs = app(CredentialLinkService::class)->issue($other->fresh()->setRelation('user', null)->forceFill(['verification_status' => VerificationStatus::Verified]));

        $this->actingAs($mine, 'sanctum')->deleteJson("/api/v1/me/credential-links/{$theirs->id}")->assertNotFound();
        $this->actingAs($mine, 'sanctum')->getJson('/api/v1/me/credential-links')->assertJsonCount(0, 'data');
        $this->assertTrue($theirs->fresh()->isActive());
    }

    public function test_only_signed_in_alumni_manage_links(): void
    {
        $this->getJson('/api/v1/me/credential-links')->assertUnauthorized();
        $this->postJson('/api/v1/me/credential-links')->assertUnauthorized();
        $this->actingAs(User::factory()->registrar()->create(), 'sanctum')->postJson('/api/v1/me/credential-links')->assertForbidden();
    }

    public function test_link_opens_are_rate_limited(): void
    {
        foreach (range(1, 20) as $i) {
            $this->get('/verify/'.str_repeat('z', 40))->assertNotFound();
        }

        $this->get('/verify/'.str_repeat('z', 40))->assertTooManyRequests();
    }
}
