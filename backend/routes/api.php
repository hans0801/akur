<?php

use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\PendingUserController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UnitResidentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/google', GoogleLoginController::class)->middleware('throttle:auth');

    // Terautentikasi — termasuk akun pending.
    Route::middleware(['auth:api', 'throttle:api'])->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::post('auth/logout', [SessionController::class, 'logout']);
        Route::get('auth/sessions', [SessionController::class, 'index']);
        Route::post('auth/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
        Route::delete('auth/sessions/{sessionId}', [SessionController::class, 'destroy'])->whereUuid('sessionId');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereUuid('notificationId');

        // Hanya akun aktif (sudah ditautkan/diverifikasi).
        Route::middleware('account.active')->group(function () {
            Route::get('units/{unit}/residents', [UnitResidentController::class, 'index'])->whereUuid('unit');
            Route::get('units/{unit}/memberships', [MembershipController::class, 'index'])->whereUuid('unit');

            Route::prefix('admin')->middleware('throttle:sensitive')->group(function () {
                Route::get('pending-users', [PendingUserController::class, 'index']);
                Route::post('pending-users/{user}/link', [PendingUserController::class, 'link'])->whereUuid('user');
                Route::post('pending-users/{user}/reject', [PendingUserController::class, 'reject'])->whereUuid('user');

                Route::post('memberships', [MembershipController::class, 'store']);
                Route::delete('memberships/{membership}', [MembershipController::class, 'destroy'])->whereUuid('membership');
            });
        });
    });
});
