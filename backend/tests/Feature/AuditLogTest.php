<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_sensitive_metadata_keys_are_dropped(): void
    {
        $log = app(AuditLogger::class)->log('test.action', 'thing', metadata: [
            'role' => 'warga',
            'nik' => '3201010101010001',
            'id_token' => 'eyJ...',
            'nested' => ['kk_number' => '123', 'ok' => true],
        ]);

        $this->assertSame(['role' => 'warga', 'nested' => ['ok' => true]], $log->fresh()->metadata);
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = app(AuditLogger::class)->log('test.action', 'thing');

        $this->expectException(QueryException::class);
        AuditLog::query()->whereKey($log->id)->delete();
    }
}
