<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun pending (belum ditautkan ke data warga) hanya boleh mengakses /me, sesi, dan notifikasi.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->account_status !== User::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Akun Anda belum diverifikasi pengurus.',
                'code' => 'account_not_active',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
