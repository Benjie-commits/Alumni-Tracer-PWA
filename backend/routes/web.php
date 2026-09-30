<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Admin\AlumniExportController;
use App\Http\Controllers\Admin\AuthController;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\AlumniDirectory;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\ImportAlumni;
use App\Livewire\Admin\StaffUsers;
use App\Livewire\Admin\VerificationQueue;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

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

        // Changing records is Registrar/ICT only; QA and Dean viewers are read-only (spec sections 2.3, 9).
        Route::middleware($managers)->group(function () {
            Route::get('verification', VerificationQueue::class)->name('verification');
            Route::get('import', ImportAlumni::class)->name('import');
        });

        Route::middleware('role:'.RoleSlug::IctAdmin->value)->get('staff', StaffUsers::class)->name('staff');
    });
});
