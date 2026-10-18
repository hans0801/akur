<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode pemeliharaan (app_settings): semua pengguna selain super_admin mendapat 503 `maintenance`.
 * Login, /me, dan logout tidak memakai middleware ini agar aplikasi tetap tahu peran pengguna
 * dan super_admin bisa masuk untuk mematikannya.
 */
class EnsureNotInMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = AppSetting::maintenance();

        if ($maintenance['enabled'] && ! $request->user()?->isSuperAdmin()) {
            return response()->json([
                'message' => $maintenance['message'] ?: 'AKUR sedang dalam pemeliharaan. Silakan coba lagi nanti.',
                'code' => 'maintenance',
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '300', 'Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
