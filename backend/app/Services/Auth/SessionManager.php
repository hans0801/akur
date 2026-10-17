<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Support\IpPrefix;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sesi aplikasi milik AKUR sendiri. Token opak acak; database hanya menyimpan SHA-256-nya.
 */
class SessionManager
{
    /**
     * @return array{session: UserSession, token: string}
     */
    public function issue(User $user, string $platform, Request $request, ?string $deviceLabel = null): array
    {
        $token = Str::random(64);

        $session = new UserSession([
            'user_id' => $user->id,
            'device_label' => $deviceLabel,
            'platform' => $platform,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(config('akur.session.idle_ttl_days')),
            'ip_prefix' => IpPrefix::from($request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
        $session->setAttribute('refresh_token_hash', self::hashExpression($token));
        $session->save();

        return ['session' => $session->refresh(), 'token' => $token];
    }

    public function findUsable(string $token): ?UserSession
    {
        $session = UserSession::query()
            ->whereRaw("refresh_token_hash = decode(?, 'hex')", [hash('sha256', $token)])
            ->first();

        if (! $session || ! $session->isUsable()) {
            return null;
        }

        $this->touch($session);

        return $session;
    }

    public function revoke(UserSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAllFor(User $user, ?string $exceptSessionId = null): int
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->update(['revoked_at' => now()]);
    }

    /**
     * Sliding expiry: perpanjang paling sering sekali per jam, tidak melewati umur absolut.
     */
    private function touch(UserSession $session): void
    {
        if ($session->last_used_at && $session->last_used_at->gt(now()->subHour())) {
            return;
        }

        $absoluteLimit = $session->created_at->copy()->addDays(config('akur.session.absolute_ttl_days'));
        $newExpiry = now()->addDays(config('akur.session.idle_ttl_days'));

        $session->forceFill([
            'last_used_at' => now(),
            'expires_at' => $newExpiry->min($absoluteLimit),
        ])->save();
    }

    /** bytea dari hex — hex hanya [0-9a-f] sehingga aman disisipkan. */
    private static function hashExpression(string $token): Expression
    {
        return DB::raw("decode('".hash('sha256', $token)."', 'hex')");
    }
}
