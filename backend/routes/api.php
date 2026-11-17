<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CredentialLinkController;
use App\Http\Controllers\Api\V1\EmploymentRecordController;
use App\Http\Controllers\Api\V1\MentoringController;
use App\Http\Controllers\Api\V1\MySurveysController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OpportunityController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SurveyController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::get('reference/programmes', [ReferenceDataController::class, 'programmes']);
    Route::get('reference/options', [ReferenceDataController::class, 'options']);

    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    // Step two of registering against a Registrar record: the one-time code sent to the contact on record.
    Route::post('auth/register/verify', [AuthController::class, 'verifyCode'])->middleware('throttle:register-code');
    Route::post('auth/register/resend', [AuthController::class, 'resendCode'])->middleware('throttle:register');

    // Forgotten password: a link by email to the PWA's "choose a new password" screen.
    Route::post('auth/forgot-password', [PasswordResetController::class, 'request'])->middleware('throttle:password-reset');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/register-student', [StudentController::class, 'register'])->middleware('throttle:register');

    // Employer / partner credential verification (FR-6). Unauthenticated by design, so heavily rate-limited;
    // answers with graduation status, programme and year only.
    Route::post('verification/lookup', [VerificationController::class, 'lookup'])->middleware('throttle:verification');

    // The survey behind a link sent by SMS/WhatsApp: the unguessable token is the credential (FR-3).
    Route::middleware('throttle:survey')->group(function () {
        Route::get('surveys/{token}', [SurveyController::class, 'show']);
        Route::post('surveys/{token}/responses', [SurveyController::class, 'store']);
    });

    // Alumni self-service (FR-2). Staff use the admin dashboard, not this API.
    // throttle:api (60 a minute per account) is applied here rather than to the whole API group, so the
    // public routes keep their own stricter limits and the WhatsApp webhook its higher one.
    // Shared by alumni and continuing students: the account itself, events and internships/jobs.
    Route::middleware(['auth:sanctum', 'role:'.RoleSlug::Alumni->value.','.RoleSlug::Student->value, 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('me/jobs', [OpportunityController::class, 'jobs']);
        Route::get('me/events', [OpportunityController::class, 'events']);
    });

    // Students only: ask for an alumni mentor and see who it is once staff match them.
    Route::middleware(['auth:sanctum', 'role:'.RoleSlug::Student->value, 'throttle:api'])->group(function () {
        Route::get('me/mentor-request', [StudentController::class, 'mentorship']);
        Route::put('me/mentor-request', [StudentController::class, 'requestMentor']);
    });

    Route::middleware(['auth:sanctum', 'role:'.RoleSlug::Alumni->value, 'throttle:api'])->group(function () {

        Route::get('me/profile', [ProfileController::class, 'show']);
        Route::put('me/profile', [ProfileController::class, 'update']);

        Route::apiResource('me/employment-records', EmploymentRecordController::class)
            ->parameters(['employment-records' => 'employmentRecord'])
            ->except('show');

        // Verification links the alumnus gives employers.
        Route::apiResource('me/credential-links', CredentialLinkController::class)
            ->parameters(['credential-links' => 'credentialLink'])
            ->only(['index', 'store', 'destroy']);

        // Engagement (FR-5): what is aimed at this alumnus, and mentoring current students.
        Route::get('me/mentoring', [MentoringController::class, 'show']);
        Route::put('me/mentoring', [MentoringController::class, 'update']);

        Route::get('me/surveys', [MySurveysController::class, 'index']);
        Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update']);
    });
});

// Provider callbacks. Unauthenticated by nature, so each verifies the sender itself.
Route::prefix('webhooks')->middleware('throttle:webhook')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive']);
});
