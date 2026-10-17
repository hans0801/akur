<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\IpPrefix;
use Illuminate\Http\Request;

/**
 * Jejak audit FR-13. Metadata disaring: token, NIK/KK, password, dan rahasia tidak pernah tersimpan.
 */
class AuditLogger
{
    private const FORBIDDEN_KEY_PATTERN = '/(nik|kk_number|token|password|secret|credential|ciphertext)/i';

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?string $unitId = null,
        array $metadata = [],
        ?string $actorUserId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actorUserId ?? $this->request->user()?->getAuthIdentifier(),
            'unit_id' => $unitId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'occurred_at' => now(),
            'request_id' => $this->request->attributes->get('request_id'),
            'ip_prefix' => app()->runningInConsole() ? null : IpPrefix::from($this->request->ip()),
            'metadata' => self::sanitize($metadata) ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_KEY_PATTERN, $key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }
}
