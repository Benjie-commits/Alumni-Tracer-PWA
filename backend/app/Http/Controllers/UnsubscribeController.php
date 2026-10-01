<?php

namespace App\Http\Controllers;

use App\Models\AlumniProfile;
use Illuminate\View\View;

/**
 * The "Stop:" link at the end of every SMS. Showing the page (GET) changes nothing, because link
 * scanners in messaging apps open URLs automatically; only pressing the button (POST) opts out.
 */
class UnsubscribeController extends Controller
{
    public function show(string $token): View
    {
        return view('unsubscribe', ['token' => $token, 'done' => false, 'profile' => $this->profile($token)]);
    }

    public function store(string $token): View
    {
        $profile = $this->profile($token);
        $profile->setOptOut(null, true);
        $profile->save();

        return view('unsubscribe', ['token' => $token, 'done' => true, 'profile' => $profile]);
    }

    private function profile(string $token): AlumniProfile
    {
        $profile = AlumniProfile::query()->where('unsubscribe_token', $token)->first();

        abort_if($profile === null, 404);

        return $profile;
    }
}
