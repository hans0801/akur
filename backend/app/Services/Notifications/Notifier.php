<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifikasi in-app (FR-10). Dikirim setelah commit dan kegagalannya tidak menggagalkan transaksi utama.
 * Payload hanya berisi ID — jangan masukkan PII.
 */
class Notifier
{
    /**
     * @param  array<string, scalar|null>  $payload
     */
    public function send(string $userId, string $type, string $title, string $body, array $payload = []): void
    {
        DB::afterCommit(function () use ($userId, $type, $title, $body, $payload) {
            try {
                AppNotification::query()->create(compact('type', 'title', 'body', 'payload') + ['user_id' => $userId]);
            } catch (Throwable $e) {
                Log::warning('In-app notification failed', ['type' => $type, 'error' => $e->getMessage()]);
            }
        });
    }
}
