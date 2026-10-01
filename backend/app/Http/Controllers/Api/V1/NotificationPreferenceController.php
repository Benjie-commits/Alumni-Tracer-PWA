<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Models\AlumniProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Whether the alumnus wants SMS and WhatsApp messages (true = yes). */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->profile($request));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms' => ['sometimes', 'boolean'],
            'whatsapp' => ['sometimes', 'boolean'],
        ]);

        $profile = $this->profile($request);

        foreach (['sms' => NotificationChannel::Sms, 'whatsapp' => NotificationChannel::Whatsapp] as $key => $channel) {
            if (array_key_exists($key, $data)) {
                $profile->setOptOut($channel, ! $data[$key]);
            }
        }

        // Deliberately not touching profile_updated_at: choosing not to be messaged is not a confirmation of the record.
        $profile->save();

        return $this->respond($profile);
    }

    private function profile(Request $request): AlumniProfile
    {
        return $request->user()->alumniProfile()->firstOrFail();
    }

    private function respond(AlumniProfile $profile): JsonResponse
    {
        return response()->json(['data' => [
            'sms' => ! $profile->hasOptedOut(NotificationChannel::Sms),
            'whatsapp' => ! $profile->hasOptedOut(NotificationChannel::Whatsapp),
        ]]);
    }
}
