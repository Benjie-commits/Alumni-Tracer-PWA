<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Admin\AlumniExportController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\OutcomeExportController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\SurveyExportController;
use App\Http\Controllers\PublicAlumnusController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\VerificationPortalController;
use App\Livewire\Admin\ActivityLogs;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\AlumniDirectory;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\ErpSync;
use App\Livewire\Admin\EventPostings;
use App\Livewire\Admin\FollowUp;
use App\Livewire\Admin\ImportAlumni;
use App\Livewire\Admin\JobPostings;
use App\Livewire\Admin\Mentorship;
use App\Livewire\Admin\Notifications;
use App\Livewire\Admin\Outcomes;
use App\Livewire\Admin\StaffUsers;
use App\Livewire\Admin\SurveyResponses;
use App\Livewire\Admin\Surveys;
use App\Livewire\Admin\VerificationEnquiries;
use App\Livewire\Admin\VerificationQueue;
use Illuminate\Support\Facades\Route;

// The public homepage: a two-part scroll story, then the single way in for everyone.
Route::view('/', 'home')->name('home');

// The alumni/student app, built into public/app. Its own files are served directly; every other
// /app path is a page inside the app, so it gets the app shell.
Route::get('app/{path?}', function () {
    $shell = public_path('app/index.html');
    abort_unless(is_file($shell), 503, 'The app has not been built yet. Run "npm.cmd run build" in the pwa folder.');

    return response()->file($shell, ['Cache-Control' => 'no-cache']);
})->where('path', '.*');

// The "Stop:" link at the end of every SMS. No sign-in: the token in the link identifies the alumnus.
Route::middleware('throttle:survey')->group(function () {
    Route::get('u/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
    Route::post('u/{token}', [UnsubscribeController::class, 'store'])->name('unsubscribe.store');
});

// Employers and partners: check that someone graduated (FR-6). No sign-in, so every route is rate-limited,
// and the only thing ever revealed is graduation status, programme and year (spec section 9).
Route::get('verify', [VerificationPortalController::class, 'show'])->name('verify.show');
Route::post('verify', [VerificationPortalController::class, 'lookup'])->middleware('throttle:verification')->name('verify.lookup');
Route::post('verify/enquiry', [VerificationPortalController::class, 'enquiry'])->middleware('throttle:verification-enquiry')->name('verify.enquiry');
Route::get('verify/{token}', [VerificationPortalController::class, 'link'])->middleware('throttle:verification')->name('verify.link');

// Graduates' own public pages: only ones the graduate published, showing only what they chose.
Route::middleware('throttle:survey')->group(function () {
    Route::get('alumni', [PublicAlumnusController::class, 'index'])->name('alumni.public');
    Route::get('alumni/{slug}', [PublicAlumnusController::class, 'show'])->name('alumnus.show');
});

$staff = 'role:'.implode(',', array_map(fn (RoleSlug $r) => $r->value, RoleSlug::staff()));
$managers = 'role:'.RoleSlug::Registrar->value.','.RoleSlug::IctAdmin->value;

Route::prefix('admin')->name('admin.')->group(function () use ($staff, $managers) {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'create'])->name('login');
        Route::get('handoff/{nonce}', [AuthController::class, 'handoff'])->middleware('throttle:login')->name('handoff');
    });

    Route::middleware(['auth', $staff, 'password.changed'])->group(function () use ($managers) {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');

        Route::get('/', Dashboard::class)->name('dashboard');
        Route::get('alumni', AlumniDirectory::class)->name('alumni.index');
        Route::get('alumni/export', AlumniExportController::class)->name('alumni.export');
        Route::get('alumni/{profile}', AlumniDetail::class)->name('alumni.show');

        // Survey overview and response rates are open to every staff role (QA and Deans need them).
        Route::get('surveys', Surveys::class)->name('surveys');

        // Outcome dashboards (FR-4): aggregates only, with small groups withheld, so every staff role may use them.
        Route::get('outcomes', Outcomes::class)->name('outcomes');
        Route::get('outcomes/export', OutcomeExportController::class)->name('outcomes.export');

        // Changing records is Registrar/ICT only; QA and Dean viewers are read-only (spec sections 2.3, 9).
        Route::middleware($managers)->group(function () {
            Route::get('verification', VerificationQueue::class)->name('verification');
            Route::get('import', ImportAlumni::class)->name('import');

            // Individual answers and the message log are personal data.
            Route::get('surveys/{cycle}/responses', SurveyResponses::class)->name('surveys.responses');
            Route::get('surveys/{cycle}/export', SurveyExportController::class)->name('surveys.export');
            Route::get('notifications', Notifications::class)->name('notifications');
            Route::get('verification-enquiries', VerificationEnquiries::class)->name('verification-enquiries');

            // SorotiUniERP hook (FR-9): the Registrar can read it; only ICT's actions are allowed (re-checked in the component).
            Route::get('erp', ErpSync::class)->name('erp');

            // Manual look-ups of non-responsive alumni (spec 7.4 fallback). Personal details, so managers only.
            Route::get('follow-up', FollowUp::class)->name('follow-up');

            // Engagement (FR-5): jobs, events, and mentorship matching with current students.
            Route::get('jobs', JobPostings::class)->name('jobs');
            Route::get('events', EventPostings::class)->name('events');
            Route::get('mentorship', Mentorship::class)->name('mentorship');
        });

        Route::middleware('role:'.RoleSlug::IctAdmin->value)->group(function () {
            Route::get('staff', StaffUsers::class)->name('staff');
            // The staff audit trail (who edited, decided, imported or exported what).
            Route::get('activity', ActivityLogs::class)->name('activity');
        });
    });
});
