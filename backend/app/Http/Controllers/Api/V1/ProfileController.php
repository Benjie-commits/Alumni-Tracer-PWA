<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\AlumniProfileResource;
use App\Models\AlumniProfile;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): AlumniProfileResource
    {
        return new AlumniProfileResource($this->profile($request)->load('programme.department.school', 'employmentRecords'));
    }

    public function update(UpdateProfileRequest $request): AlumniProfileResource
    {
        $profile = $this->profile($request);

        $profile->fill($request->safe()->only(AlumniProfile::SELF_SERVICE_FIELDS));
        $profile->profile_updated_at = now();
        $profile->save();

        // Keep the login email in step with the contact email the alumnus chose.
        if ($profile->wasChanged('email') && $profile->email) {
            $request->user()->update(['email' => $profile->email]);
        }

        return new AlumniProfileResource($profile->load('programme.department.school', 'employmentRecords'));
    }

    private function profile(Request $request): AlumniProfile
    {
        return $request->user()->alumniProfile()->firstOrFail();
    }
}
