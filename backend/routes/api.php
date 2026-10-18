<?php

use App\Http\Controllers\Admin\AppSettingsController;
use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\PendingUserController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Community\ActivityController;
use App\Http\Controllers\Community\AnnouncementController;
use App\Http\Controllers\Documents\DocumentRequestController;
use App\Http\Controllers\Documents\LetterController;
use App\Http\Controllers\Documents\LetterSettingsController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Finance\CashbookController;
use App\Http\Controllers\Finance\DuesController;
use App\Http\Controllers\Finance\PaymentController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Finance\ReportExportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Population\HouseController;
use App\Http\Controllers\Population\HouseholdController;
use App\Http\Controllers\Population\ResidentController;
use App\Http\Controllers\Population\ResidentMovementController;
use App\Http\Controllers\Population\StreetController;
use App\Services\Reports\OperationalReports;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/google', GoogleLoginController::class)->middleware('throttle:auth');

    // Surat siap cetak: tanpa sesi, hanya lewat tautan bertanda tangan berumur pendek (lihat letterLink).
    Route::get('letters/{documentRequest}', LetterController::class)
        ->whereUuid('documentRequest')
        ->middleware(['signed', 'throttle:sensitive'])
        ->name('documents.letter');

    Route::get('letter-previews/{unit}', [LetterSettingsController::class, 'preview'])
        ->whereUuid('unit')
        ->middleware(['signed', 'throttle:sensitive'])
        ->name('documents.letter-preview');

    Route::get('file-downloads/{file}', [FileController::class, 'download'])
        ->whereUuid('file')
        ->middleware(['signed', 'throttle:sensitive'])
        ->name('files.download');

    // Ekspor laporan kas (CSV / siap cetak) lewat tautan bertanda tangan berumur pendek.
    Route::get('finance-exports/{unit}', [ReportExportController::class, 'download'])
        ->whereUuid('unit')
        ->middleware(['signed', 'throttle:sensitive'])
        ->name('finance.export');

    // Terautentikasi — termasuk akun pending.
    Route::middleware(['auth:api', 'throttle:api'])->group(function () {
        // Tetap terbuka saat mode pemeliharaan: aplikasi perlu tahu peran pengguna, dan siapa pun bisa keluar.
        Route::get('me', [SessionController::class, 'me']);
        Route::post('auth/logout', [SessionController::class, 'logout']);

        // Mode pemeliharaan: selain super_admin mendapat 503 `maintenance` (lihat EnsureNotInMaintenance).
        Route::middleware('not.maintenance')->group(function () {
            Route::put('me/avatar', [ProfileController::class, 'updateAvatar']);
            Route::get('auth/sessions', [SessionController::class, 'index']);
            Route::post('auth/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
            Route::delete('auth/sessions/{sessionId}', [SessionController::class, 'destroy'])->whereUuid('sessionId');

            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereUuid('notificationId');

            // Hanya akun aktif (sudah ditautkan/diverifikasi).
            Route::middleware('account.active')->group(function () {
                Route::get('units/{unit}/memberships', [MembershipController::class, 'index'])->whereUuid('unit');

                // Berkas privat (bukti bayar, nota kas): unggah lalu lampirkan lewat *_file_id.
                Route::post('files', [FileController::class, 'store'])->middleware('throttle:sensitive');
                Route::post('files/{file}/link', [FileController::class, 'link'])->whereUuid('file')->middleware('throttle:sensitive');

                // W8: iuran dan pembayaran.
                Route::whereUuid(['unit', 'duesType', 'duesPeriod', 'duesAssessment', 'payment'])->group(function () {
                    Route::get('my/dues', [PaymentController::class, 'myDues']);
                    Route::get('my/payments', [PaymentController::class, 'myPayments']);
                    Route::post('payments', [PaymentController::class, 'submit'])->middleware('throttle:sensitive');

                    Route::get('units/{unit}/dues-types', [DuesController::class, 'types']);
                    Route::post('units/{unit}/dues-types', [DuesController::class, 'storeType']);
                    Route::patch('dues-types/{duesType}', [DuesController::class, 'updateType']);
                    Route::get('dues-types/{duesType}/periods', [DuesController::class, 'periods']);
                    Route::post('dues-types/{duesType}/periods', [DuesController::class, 'storePeriod']);
                    Route::get('dues-periods/{duesPeriod}', [DuesController::class, 'showPeriod']);
                    Route::post('dues-periods/{duesPeriod}/close', [DuesController::class, 'closePeriod']);
                    Route::post('dues-assessments/{duesAssessment}/waive', [DuesController::class, 'waive']);

                    Route::get('units/{unit}/payments', [PaymentController::class, 'queue']);
                    Route::post('units/{unit}/payments', [PaymentController::class, 'recordCash'])->middleware('throttle:sensitive');
                    Route::post('payments/{payment}/verify', [PaymentController::class, 'verify']);
                    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject']);
                    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);
                });

                // W9: buku kas dan laporan. Laporan agregat terbuka bagi anggota unit; rincian hanya pengurus keuangan.
                Route::whereUuid(['unit', 'financeCategory', 'financeTransaction'])->group(function () {
                    Route::get('units/{unit}/finance-categories', [CashbookController::class, 'categories']);
                    Route::post('units/{unit}/finance-categories', [CashbookController::class, 'storeCategory']);
                    Route::patch('finance-categories/{financeCategory}', [CashbookController::class, 'updateCategory']);
                    Route::get('units/{unit}/finance-transactions', [CashbookController::class, 'transactions']);
                    Route::post('units/{unit}/finance-transactions', [CashbookController::class, 'store'])->middleware('throttle:sensitive');
                    Route::post('finance-transactions/{financeTransaction}/reverse', [CashbookController::class, 'reverse']);
                    Route::get('units/{unit}/finance-reports/monthly', [ReportController::class, 'monthly']);
                    Route::get('units/{unit}/finance-reports/yearly', [ReportController::class, 'yearly']);
                    Route::post('units/{unit}/finance-reports/export-link', [ReportExportController::class, 'link'])->middleware('throttle:sensitive');
                    // FR-11: laporan operasional per unit/bulan (ekspor lewat export-link dengan `report`).
                    Route::get('units/{unit}/reports/{type}', [ReportController::class, 'operational'])->whereIn('type', OperationalReports::TYPES);
                });

                // W7: surat pengantar. Pemohon = pemilik akun; pengurus memproses di unitnya.
                Route::whereUuid(['unit', 'documentRequest'])->group(function () {
                    Route::get('document-types', [DocumentRequestController::class, 'types']);
                    Route::get('document-requests/eligible-residents', [DocumentRequestController::class, 'eligibleResidents']);
                    Route::get('document-requests', [DocumentRequestController::class, 'mine']);
                    Route::post('document-requests', [DocumentRequestController::class, 'store']);
                    Route::get('units/{unit}/document-requests', [DocumentRequestController::class, 'queue']);
                    Route::get('document-requests/{documentRequest}', [DocumentRequestController::class, 'show']);
                    Route::put('document-requests/{documentRequest}', [DocumentRequestController::class, 'resubmit']);
                    Route::post('document-requests/{documentRequest}/cancel', [DocumentRequestController::class, 'cancel']);
                    Route::post('document-requests/{documentRequest}/transition', [DocumentRequestController::class, 'transition']);
                    Route::post('document-requests/{documentRequest}/letter-link', [DocumentRequestController::class, 'letterLink'])
                        ->middleware('throttle:sensitive');

                    // Pengaturan surat per unit: penanda tangan, wilayah kop, logo, tanda tangan + cap.
                    Route::get('letter-settings/units', [LetterSettingsController::class, 'units']);
                    Route::get('units/{unit}/letter-settings', [LetterSettingsController::class, 'show']);
                    Route::put('units/{unit}/letter-settings', [LetterSettingsController::class, 'update']);
                    Route::post('units/{unit}/letter-settings/preview-link', [LetterSettingsController::class, 'previewLink'])
                        ->middleware('throttle:sensitive');
                });

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

                    // Pengaturan aplikasi (mode pemeliharaan): hanya super_admin, diperiksa di controller.
                    Route::get('settings', [AppSettingsController::class, 'show']);
                    Route::put('settings/maintenance', [AppSettingsController::class, 'updateMaintenance']);
                });
            });
        });
    });
});
