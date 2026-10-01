<?php

namespace Tests\Feature\Phase2;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Programme;
use App\Models\User;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SurveyCycleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeSmsGateway;
use Tests\Support\FakeWhatsAppGateway;
use Tests\TestCase;

/**
 * Phase 2 tests run with fake SMS/WhatsApp gateways (nothing leaves the machine) and the clock
 * frozen at 10:00 in Kampala on a Monday, safely outside quiet hours.
 */
abstract class Phase2TestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeSmsGateway $sms;

    protected FakeWhatsAppGateway $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SurveyCycleSeeder::class]);

        $this->sms = new FakeSmsGateway;
        $this->whatsapp = new FakeWhatsAppGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(WhatsAppGateway::class, $this->whatsapp);

        $this->at('2026-10-05 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Freeze the clock at a local Kampala time. */
    protected function at(string $kampalaTime): Carbon
    {
        $now = Carbon::parse($kampalaTime, 'Africa/Kampala');
        Carbon::setTestNow($now);

        return $now;
    }

    /** Send by SMS only, which makes message text easy to assert on. */
    protected function smsOnly(): void
    {
        config(['sunates.messaging.channel_priority' => ['sms']]);
    }

    /**
     * A verified graduate with a phone. Override anything, e.g. ['graduation_date' => '2026-04-05'].
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function graduate(array $attributes = []): AlumniProfile
    {
        return AlumniProfile::factory()->create($attributes + [
            'first_name' => 'Amina',
            'last_name' => 'Okello',
            'graduation_date' => '2026-04-05',
            'graduation_year' => 2026,
            'phone' => '0700 111 222',
            'programme_id' => Programme::factory(),
            'verification_status' => VerificationStatus::Verified,
        ]);
    }

    /** A graduate who also has an alumni account. */
    protected function registeredGraduate(array $attributes = []): AlumniProfile
    {
        $user = User::factory()->alumnus()->create();

        return $this->graduate($attributes + ['user_id' => $user->id]);
    }
}
