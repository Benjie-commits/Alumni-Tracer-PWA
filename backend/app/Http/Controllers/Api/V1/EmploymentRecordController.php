<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EmploymentRecordRequest;
use App\Http\Resources\EmploymentRecordResource;
use App\Models\AlumniProfile;
use App\Models\EmploymentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmploymentRecordController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return EmploymentRecordResource::collection(
            $this->profile($request)->employmentRecords()->orderByDesc('is_current')->orderByDesc('start_date')->get()
        );
    }

    public function store(EmploymentRecordRequest $request): JsonResponse
    {
        $profile = $this->profile($request);

        $record = $profile->employmentRecords()->create($request->validated());
        $this->touchProfile($profile);

        return (new EmploymentRecordResource($record))->response()->setStatusCode(201);
    }

    public function update(EmploymentRecordRequest $request, EmploymentRecord $employmentRecord): EmploymentRecordResource
    {
        $profile = $this->profile($request);
        $this->ensureOwned($profile, $employmentRecord);

        $employmentRecord->update($request->validated());
        $this->touchProfile($profile);

        return new EmploymentRecordResource($employmentRecord);
    }

    public function destroy(Request $request, EmploymentRecord $employmentRecord): JsonResponse
    {
        $profile = $this->profile($request);
        $this->ensureOwned($profile, $employmentRecord);

        $employmentRecord->delete();
        $this->touchProfile($profile);

        return response()->json(null, 204);
    }

    private function profile(Request $request): AlumniProfile
    {
        return $request->user()->alumniProfile()->firstOrFail();
    }

    /** A record that belongs to someone else looks the same as one that does not exist. */
    private function ensureOwned(AlumniProfile $profile, EmploymentRecord $record): void
    {
        abort_unless($record->alumni_profile_id === $profile->id, 404);
    }

    private function touchProfile(AlumniProfile $profile): void
    {
        $profile->forceFill(['profile_updated_at' => now()])->save();
    }
}
