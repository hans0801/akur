<?php

namespace App\Console\Commands;

use App\Models\AdministrativeUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap pengurus pertama dari server (mis. super admin). Pengguna harus sudah pernah login Google.
 */
class GrantRole extends Command
{
    protected $signature = 'akur:grant-role
        {email : Email Google pengguna (harus sudah pernah login)}
        {role : Kode peran, mis. super_admin}
        {unit : Kode RW (mis. 06) atau RW/RT (mis. 06/05)}
        {--activate : Aktifkan akun bila masih pending}
        {--reason=bootstrap via CLI : Alasan untuk audit log}';

    protected $description = 'Menetapkan peran kepada pengguna pada unit tertentu (tercatat di audit log)';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Pengguna tidak ditemukan. Minta ia login dengan Google terlebih dahulu.');

            return self::FAILURE;
        }

        $roleId = Role::idFor($this->argument('role'));
        if ($roleId === 0) {
            $this->error('Peran tidak dikenal.');

            return self::FAILURE;
        }

        $unit = $this->resolveUnit($this->argument('unit'));
        if (! $unit) {
            $this->error('Unit tidak ditemukan.');

            return self::FAILURE;
        }

        if ($user->account_status !== User::STATUS_ACTIVE && ! $this->option('activate')) {
            $this->error("Akun berstatus {$user->account_status}. Tambahkan --activate untuk mengaktifkannya.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $roleId, $unit, $audit) {
            if ($user->account_status !== User::STATUS_ACTIVE) {
                $user->update(['account_status' => User::STATUS_ACTIVE]);
                $audit->log('user.activated', 'user', $user->id, $unit->id, ['source' => 'cli']);
            }

            $membership = UserMembership::query()->updateOrCreate(
                ['user_id' => $user->id, 'unit_id' => $unit->id, 'role_id' => $roleId],
                ['status' => UserMembership::STATUS_ACTIVE, 'granted_by' => null, 'granted_at' => now(), 'expires_at' => null],
            );

            $audit->log('membership.granted', 'user_membership', $membership->id, $unit->id, [
                'user_id' => $user->id,
                'role' => $this->argument('role'),
                'reason' => $this->option('reason'),
                'source' => 'cli',
            ]);
        });

        $this->info("Peran {$this->argument('role')} diberikan kepada {$user->email} di {$unit->name}.");

        return self::SUCCESS;
    }

    private function resolveUnit(string $path): ?AdministrativeUnit
    {
        [$rwCode, $rtCode] = array_pad(explode('/', $path, 2), 2, null);

        $rw = AdministrativeUnit::query()->where('unit_type', 'rw')->where('code', $rwCode)->first();
        if (! $rw || $rtCode === null) {
            return $rw;
        }

        return AdministrativeUnit::query()->where('unit_type', 'rt')->where('parent_unit_id', $rw->id)->where('code', $rtCode)->first();
    }
}
