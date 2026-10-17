<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use App\Models\UserSession;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $this->current($request);
        $this->sessions->revoke($session);
        $this->audit->log('auth.logout', 'user_session', $session->id);

        return response()->json(['message' => 'Berhasil keluar.'])->withCookie(SessionCookie::forget());
    }

    public function index(Request $request): JsonResponse
    {
        $currentId = $this->current($request)->id;

        $sessions = UserSession::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('last_used_at')
            ->get()
            ->map(fn (UserSession $s) => [
                'id' => $s->id,
                'platform' => $s->platform,
                'device_label' => $s->device_label,
                'created_at' => $s->created_at->toIso8601String(),
                'last_used_at' => $s->last_used_at?->toIso8601String(),
                'expires_at' => $s->expires_at->toIso8601String(),
                'current' => $s->id === $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        // Hanya sesi milik sendiri; sesi orang lain diperlakukan sebagai tidak ada (anti-IDOR).
        $session = UserSession::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($sessionId)
            ->firstOrFail();

        $this->sessions->revoke($session);
        $this->audit->log('auth.session_revoked', 'user_session', $session->id);

        return response()->json(['message' => 'Sesi dicabut.']);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeAllFor($request->user(), exceptSessionId: $this->current($request)->id);
        $this->audit->log('auth.sessions_revoked_others', 'user', $request->user()->id, metadata: ['count' => $count]);

        return response()->json(['message' => 'Sesi lain dicabut.', 'revoked' => $count]);
    }

    private function current(Request $request): UserSession
    {
        return $request->attributes->get('akur_session');
    }
}
