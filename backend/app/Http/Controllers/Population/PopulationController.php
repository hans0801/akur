<?php

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Models\AdministrativeUnit;
use App\Services\Access\UnitAccess;
use App\Services\Audit\AuditLogger;
use App\Services\Pii\PopulationPii;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Dasar FR-04 (data kependudukan): setiap entitas diselesaikan ke unitnya lalu diotorisasi per peran + cakupan unit.
 * Tidak ada cache klien yang dipercaya; akses lintas RT ditolak di sini.
 */
abstract class PopulationController extends Controller
{
    public function __construct(
        protected readonly UnitAccess $access,
        protected readonly AuditLogger $audit,
        protected readonly PopulationPii $pii,
    ) {}

    protected function authorizeRead(Request $request, ?AdministrativeUnit $unit): void
    {
        abort_if($unit === null, 404);
        $this->access->authorize($request->user(), $unit, config('akur.population_read_roles'));
    }

    protected function authorizeWrite(Request $request, ?AdministrativeUnit $unit): void
    {
        abort_if($unit === null, 404);
        $this->access->authorize($request->user(), $unit, config('akur.population_write_roles'));
    }

    /**
     * Perubahan [lama, baru] per kolom yang akan disimpan, untuk metadata audit. Panggil sebelum save().
     * Kolom PII tidak pernah lewat sini (AuditLogger juga menyaring kunci nik/kk_number).
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected static function dirtyChanges(Model $model): array
    {
        $changes = [];
        foreach (array_keys($model->getDirty()) as $key) {
            $changes[$key] = [$model->getOriginal($key), $model->getAttribute($key)];
        }

        return $changes;
    }

    protected static function like(string $q): string
    {
        return '%'.addcslashes($q, '%_\').'%';
    }
}
