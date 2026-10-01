<?php

namespace Tests\Feature\Api;

class LinkedInAddressTest extends ApiTestCase
{
    private function save(array $payload, $user)
    {
        return $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/profile', $payload);
    }

    public function test_an_alumnus_can_give_their_linkedin_address_and_it_is_stored_in_one_form(): void
    {
        [$user, $profile] = $this->alumnus();

        $this->save(['linkedin_url' => 'ug.linkedin.com/in/amina-okello/?trk=x'], $user)
            ->assertOk()
            ->assertJsonPath('data.linkedin_url', 'https://www.linkedin.com/in/amina-okello');

        $this->assertSame('https://www.linkedin.com/in/amina-okello', $profile->fresh()->linkedin_url);
    }

    public function test_it_is_part_of_the_profile_they_read_back(): void
    {
        [$user, $profile] = $this->alumnus();
        $profile->update(['linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/profile')->assertJsonPath('data.linkedin_url', 'https://www.linkedin.com/in/amina-okello');
    }

    public function test_it_can_be_removed_again(): void
    {
        [$user, $profile] = $this->alumnus();
        $profile->update(['linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);

        $this->save(['linkedin_url' => null], $user)->assertOk()->assertJsonPath('data.linkedin_url', null);
        $this->assertNull($profile->fresh()->linkedin_url);

        $profile->update(['linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);
        $this->save(['linkedin_url' => '   '], $user)->assertOk();
        $this->assertNull($profile->fresh()->linkedin_url, 'a blank box clears it');
    }

    public function test_anything_that_is_not_a_profile_address_is_rejected_and_nothing_is_saved(): void
    {
        [$user, $profile] = $this->alumnus();

        foreach (['javascript:alert(1)', 'https://evil.example/in/amina', 'https://www.linkedin.com/company/x', 'my linkedin', 'https://www.linkedin.com/in/'] as $bad) {
            $this->save(['linkedin_url' => $bad], $user)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('linkedin_url')
                ->assertJsonPath('errors.linkedin_url.0', 'Enter the address of your LinkedIn profile, e.g. linkedin.com/in/your-name.');
        }

        $this->assertNull($profile->fresh()->linkedin_url);
    }

    public function test_leaving_it_out_does_not_erase_one_already_given(): void
    {
        [$user, $profile] = $this->alumnus();
        $profile->update(['linkedin_url' => 'https://www.linkedin.com/in/amina-okello']);

        $this->save(['city' => 'Gulu'], $user)->assertOk();

        $this->assertSame('https://www.linkedin.com/in/amina-okello', $profile->fresh()->linkedin_url);
    }

    public function test_one_alumnus_cannot_set_anothers(): void
    {
        [$userA] = $this->alumnus();
        [, $profileB] = $this->alumnus(['first_name' => 'Peter', 'last_name' => 'Ojok']);

        $this->save(['linkedin_url' => 'linkedin.com/in/someone-else', 'id' => $profileB->id, 'alumni_profile_id' => $profileB->id], $userA)->assertOk();

        $this->assertNull($profileB->fresh()->linkedin_url);
    }
}
