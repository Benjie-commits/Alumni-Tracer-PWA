<?php

namespace Tests\Feature\Engagement;

use App\Models\AlumniProfile;
use App\Models\EventPosting;
use App\Models\EventRsvp;
use App\Models\Programme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-5: alumni say whether they are coming to an event. */
class EventRsvpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function alumnus(?Programme $programme = null): User
    {
        $user = User::factory()->alumnus()->create();
        AlumniProfile::factory()->claimedBy($user)->create(['programme_id' => $programme ?? Programme::factory(), 'graduation_year' => 2022]);

        return $user;
    }

    public function test_an_alumnus_can_say_they_are_going_and_change_their_mind(): void
    {
        $user = $this->alumnus();
        $event = EventPosting::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/me/events/{$event->id}/rsvp", ['response' => 'going'])
            ->assertOk()->assertJsonPath('data.my_rsvp', 'going')->assertJsonPath('data.going_count', 1);

        $this->putJson("/api/v1/me/events/{$event->id}/rsvp", ['response' => 'no'])
            ->assertOk()->assertJsonPath('data.going_count', 0);

        $this->assertSame(1, EventRsvp::count(), 'one answer per person per event');
    }

    public function test_the_event_list_shows_my_answer_and_how_many_are_going(): void
    {
        $user = $this->alumnus();
        $other = $this->alumnus();
        $event = EventPosting::factory()->create();
        EventRsvp::create(['event_posting_id' => $event->id, 'user_id' => $other->id, 'response' => 'going']);
        EventRsvp::create(['event_posting_id' => $event->id, 'user_id' => $user->id, 'response' => 'maybe']);

        $row = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/events')->assertOk()->json('data.0');

        $this->assertSame('maybe', $row['my_rsvp']);
        $this->assertSame(1, $row['going_count']);
    }

    public function test_an_answer_can_be_withdrawn(): void
    {
        $user = $this->alumnus();
        $event = EventPosting::factory()->create();
        EventRsvp::create(['event_posting_id' => $event->id, 'user_id' => $user->id, 'response' => 'going']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/me/events/{$event->id}/rsvp")->assertOk();

        $this->assertSame(0, EventRsvp::count());
    }

    public function test_only_a_valid_answer_is_accepted(): void
    {
        $user = $this->alumnus();
        $event = EventPosting::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/me/events/{$event->id}/rsvp", ['response' => 'definitely'])->assertUnprocessable();
    }

    public function test_you_cannot_answer_for_an_event_that_is_not_for_you_not_published_or_over(): void
    {
        $user = $this->alumnus();
        $someoneElsesProgramme = EventPosting::factory()->create(['programme_id' => Programme::factory()]);
        $draft = EventPosting::factory()->create(['published_at' => null]);
        $over = EventPosting::factory()->create(['starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHours(2)]);

        $this->actingAs($user, 'sanctum');

        foreach ([$someoneElsesProgramme, $draft, $over] as $event) {
            $this->putJson("/api/v1/me/events/{$event->id}/rsvp", ['response' => 'going'])->assertNotFound();
        }
        $this->assertSame(0, EventRsvp::count());
    }

    public function test_signed_out_people_cannot_answer(): void
    {
        $event = EventPosting::factory()->create();

        $this->putJson("/api/v1/me/events/{$event->id}/rsvp", ['response' => 'going'])->assertUnauthorized();
    }
}
