<?php

use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\PendingUserController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Community\ActivityController;
use App\Http\Controllers\Community\AnnouncementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Population\HouseController;
use App\Http\Controllers\Population\HouseholdController;
use App\Http\Controllers\Population\ResidentController;
use App\Http\Controllers\Population\ResidentMovementController;
use App\Http\Controllers\Population\StreetController;
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
            Route::get('units/{unit}/memberships', [MembershipController::class, 'index'])->whereUuid('unit');

            // W6: pengumuman dan kegiatan. Baca untuk semua anggota unit, tulis untuk pengurus.
            Route::whereUuid(['unit', 'announcement', 'activity', 'participant'])->group(function () {
                Route::get('announcements', [AnnouncementController::class, 'feed']);
                Route::get('units/{unit}/announcements', [AnnouncementController::class, 'index']);
                Route::post('units/{unit}/announcements', [AnnouncementController::class, 'store']);
                Route::get('announcements/{announcement}', [AnnouncementController::class, 'show']);
                Route::patch('announcements/{announcement}', [AnnouncementController::class, 'update']);

                Route::get('activities', [ActivityController::class, 'index']);
                Route::post('units/{unit}/activities', [ActivityController::class, 'store']);
                Route::get('activities/{activity}', [ActivityController::class, 'show']);
                Route::patch('activities/{activity}', [ActivityController::class, 'update']);
                Route::put('activities/{activity}/participation', [ActivityController::class, 'participate']);
                Route::post('activities/{activity}/participants/{participant}/check-in', [ActivityController::class, 'checkIn']);
            });

            // FR-04: data kependudukan, diotorisasi per unit di controller.
            Route::whereUuid(['unit', 'street', 'house', 'household', 'resident'])->group(function () {
                Route::get('units/{unit}/streets', [StreetController::class, 'index']);
                Route::post('units/{unit}/streets', [StreetController::class, 'store']);
                Route::patch('streets/{street}', [StreetController::class, 'update']);

                Route::get('units/{unit}/houses', [HouseController::class, 'index']);
                Route::post('units/{unit}/houses', [HouseController::class, 'store']);
                Route::patch('houses/{house}', [HouseController::class, 'update']);
                Route::delete('houses/{house}', [HouseController::class, 'destroy']);

                Route::get('units/{unit}/households', [HouseholdController::class, 'index']);
                Route::post('units/{unit}/households', [HouseholdController::class, 'store']);
                Route::get('households/{household}', [HouseholdController::class, 'show']);
                Route::patch('households/{household}', [HouseholdController::class, 'update']);

                Route::get('units/{unit}/residents', [ResidentController::class, 'index']);
                Route::post('households/{household}/residents', [ResidentController::class, 'store']);
                Route::get('residents/{resident}', [ResidentController::class, 'show']);
                Route::patch('residents/{resident}', [ResidentController::class, 'update']);
                Route::delete('residents/{resident}', [ResidentController::class, 'destroy']);

                Route::get('residents/{resident}/movements', [ResidentMovementController::class, 'index']);
                Route::post('residents/{resident}/movements', [ResidentMovementController::class, 'store']);

                // NIK/KK utuh dan pencarian NIK: diaudit dan dibatasi ketat.
                Route::middleware('throttle:sensitive')->group(function () {
                    Route::post('residents/{resident}/nik/reveal', [ResidentController::class, 'revealNik']);
                    Route::post('households/{household}/kk/reveal', [HouseholdController::class, 'revealKk']);
                    Route::post('units/{unit}/residents/lookup-nik', [ResidentController::class, 'lookupNik']);
                });
            });

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
