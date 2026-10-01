<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Admin\AlumniExportController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\OutcomeExportController;
use App\Http\Controllers\Admin\SurveyExportController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\VerificationPortalController;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\AlumniDirectory;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\ImportAlumni;
use App\Livewire\Admin\Notifications;
use App\Livewire\Admin\Outcomes;
use App\Livewire\Admin\StaffUsers;
use App\Livewire\Admin\SurveyResponses;
use App\Livewire\Admin\Surveys;
use App\Livewire\Admin\VerificationEnquiries;
use App\Livewire\Admin\VerificationQueue;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

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

$staff = 'role:'.implode(',', array_map(fn (RoleSlug $r) => $r->value, RoleSlug::staff()));
$managers = 'role:'.RoleSlug::Registrar->value.','.RoleSlug::IctAdmin->value;

Route::prefix('admin')->name('admin.')->group(function () use ($staff, $managers) {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'create'])->name('login');
        Route::post('login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    });

    Route::middleware(['auth', $staff])->group(function () use ($managers) {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

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
        });

        Route::middleware('role:'.RoleSlug::IctAdmin->value)->get('staff', StaffUsers::class)->name('staff');
    });
});
