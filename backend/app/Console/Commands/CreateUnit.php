<?php

namespace App\Console\Commands;

use App\Models\AdministrativeUnit;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class CreateUnit extends Command
{
    protected $signature = 'akur:create-unit
        {type : rw atau rt}
        {code : Kode unit, mis. 06}
        {name : Nama tampilan, mis. "RW 06"}
        {--parent= : Kode RW induk (wajib untuk RT)}';

    protected $description = 'Membuat unit RW/RT';

    public function handle(AuditLogger $audit): int
    {
        $type = strtolower($this->argument('type'));
        if (! in_array($type, [AdministrativeUnit::TYPE_RW, AdministrativeUnit::TYPE_RT], true)) {
            $this->error('Tipe harus rw atau rt.');

            return self::FAILURE;
        }

        $parentId = null;
        if ($type === AdministrativeUnit::TYPE_RT) {
            $parent = AdministrativeUnit::query()->where('unit_type', 'rw')->where('code', $this->option('parent'))->first();
            if (! $parent) {
                $this->error('RW induk tidak ditemukan. Gunakan --parent=<kode RW>.');

                return self::FAILURE;
            }
            $parentId = $parent->id;
        }

        $unit = AdministrativeUnit::query()->firstOrCreate(
            ['unit_type' => $type, 'code' => $this->argument('code'), 'parent_unit_id' => $parentId],
            ['name' => $this->argument('name')],
        );

        if ($unit->wasRecentlyCreated) {
            $audit->log('unit.created', 'administrative_unit', $unit->id, $unit->id, ['source' => 'cli']);
        }

        $this->info("Unit {$unit->name}: {$unit->id}");

        return self::SUCCESS;
    }
}
