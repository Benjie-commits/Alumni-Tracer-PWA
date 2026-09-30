<?php

use App\Enums\RoleSlug;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmploymentRecordController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReferenceDataController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::get('reference/programmes', [ReferenceDataController::class, 'programmes']);
    Route::get('reference/options', [ReferenceDataController::class, 'options']);

    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Alumni self-service (FR-2). Staff use the admin dashboard, not this API.
    Route::middleware(['auth:sanctum', 'role:'.RoleSlug::Alumni->value])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('me/profile', [ProfileController::class, 'show']);
        Route::put('me/profile', [ProfileController::class, 'update']);

        Route::apiResource('me/employment-records', EmploymentRecordController::class)
            ->parameters(['employment-records' => 'employmentRecord'])
            ->except('show');
    });
});
