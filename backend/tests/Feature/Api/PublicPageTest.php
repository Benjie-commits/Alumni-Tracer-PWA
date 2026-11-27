<?php

namespace Tests\Feature\Api;

use App\Enums\VerificationStatus;

class PublicPageTest extends ApiTestCase
{
    private function publish(array $extra = []): array
    {
        [$user, $profile] = $this->alumnus(['graduation_year' => 2024, 'city' => 'Gulu', 'country' => 'Uganda', 'phone' => '+256700000001']);
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/public-page', $extra + ['published' => true, 'sections' => []])->assertOk();

        return [$user, $profile->refresh()];
    }

    public function test_nothing_is_public_until_the_graduate_switches_it_on(): void
    {
        [, $profile] = $this->alumnus();

        $this->get('/alumni')->assertOk()->assertDontSee('Amina');
        $this->assertNull($profile->public_slug);
    }

    public function test_a_published_page_shows_only_the_verified_baseline_and_chosen_parts(): void
    {
        [, $profile] = $this->publish(['sections' => ['location'], 'headline' => 'Building things.']);

        $this->get(route('alumnus.show', $profile->public_slug))
            ->assertOk()
            ->assertSee('Amina Okello')
            ->assertSee('Building things.')
            ->assertSee('Gulu, Uganda')
            ->assertDontSee('+256700000001')          // contact details are never on the page
            ->assertDontSee($profile->email ?? 'no-email-to-leak');
    }

    public function test_unchosen_parts_stay_hidden(): void
    {
        [, $profile] = $this->publish(['sections' => []]);

        $this->get(route('alumnus.show', $profile->public_slug))->assertOk()->assertDontSee('Gulu');
    }

    public function test_withdrawing_takes_the_page_down_and_unlisted_graduates_are_not_searchable(): void
    {
        [$user, $profile] = $this->publish();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/public-page', ['published' => false])->assertOk();

        $this->get(route('alumnus.show', $profile->public_slug))->assertNotFound();
        $this->get('/alumni?q=Okello')->assertOk()->assertDontSee('Amina Okello');
    }

    public function test_an_unverified_graduate_cannot_publish_and_a_page_is_hidden_if_verification_is_lost(): void
    {
        [$user, $profile] = $this->alumnus();
        $profile->forceFill(['verification_status' => VerificationStatus::Pending])->save();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/public-page', ['published' => true])->assertStatus(422);

        [, $other] = $this->publish();
        $other->forceFill(['verification_status' => VerificationStatus::Rejected])->save();
        $this->get(route('alumnus.show', $other->public_slug))->assertNotFound();
    }

    public function test_the_address_is_server_made_and_unknown_sections_are_refused(): void
    {
        [$user, $profile] = $this->alumnus();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/public-page', ['published' => true, 'sections' => ['phone']])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/public-page', ['published' => true, 'public_slug' => 'chosen-by-me'])->assertOk();

        $this->assertNotSame('chosen-by-me', $profile->refresh()->public_slug);
        $this->assertMatchesRegularExpression('/^amina-okello-[a-z0-9]{5}$/', $profile->public_slug);
    }

    public function test_search_finds_published_graduates_by_name(): void
    {
        $this->publish();

        $this->get('/alumni?q=Okel')->assertOk()->assertSee('Amina Okello');
        $this->get('/alumni?q=Nobody')->assertOk()->assertSee('No published graduates match');
    }
}
