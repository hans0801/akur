<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Http\Request;

/**
 * Dasar W8/W9 (iuran & kas). Tulis: akur.finance_write_roles; baca rinci: akur.finance_read_roles.
 * Warga hanya mengakses keluarganya sendiri lewat endpoint "my".
 */
abstract class FinanceController extends Controller
{
    public function __construct(
        protected readonly UnitAccess $access,
        protected readonly AuditLogger $audit,
        protected readonly Notifier $notifier,
    ) {}

    protected function authorizeRead(Request $request, ?AdministrativeUnit $unit): void
    {
        abort_if($unit === null, 404);
        $this->access->authorize($request->user(), $unit, config('akur.finance_read_roles'));
    }

    protected function authorizeWrite(Request $request, ?AdministrativeUnit $unit): void
    {
        abort_if($unit === null, 404);
        $this->access->authorize($request->user(), $unit, config('akur.finance_write_roles'));
    }

    /** Keluarga milik pengguna (lewat data warga yang ditautkan); 403 bila belum tertaut. */
    protected function ownHousehold(User $user): Household
    {
        $household = $user->resident?->household;
        abort_if($household === null, 403, 'Akun Anda belum terhubung ke data keluarga.');

        return $household;
    }

    /**
     * Notifikasi ke akun warga yang tertaut pada keluarga-keluarga tertentu. Isi tanpa PII.
     *
     * @param  list<string>  $householdIds
     * @param  array<string, scalar|null>  $payload
     */
    protected function notifyHouseholds(array $householdIds, string $type, string $title, string $body, array $payload): void
    {
        $userIds = Resident::query()
            ->whereIn('household_id', $householdIds)
            ->whereNotNull('user_id')
            ->where('is_alive', true)
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            $this->notifier->send($userId, $type, $title, $body, $payload);
        }
    }
}
