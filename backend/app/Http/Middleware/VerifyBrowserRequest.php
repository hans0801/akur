<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proteksi CSRF untuk klien browser (sesi via cookie HttpOnly).
 *
 * Request yang mengubah data tanpa Bearer token, tetapi membawa cookie sesi atau header Origin
 * (artinya berasal dari browser), wajib: Origin terdaftar + header kustom X-AKUR-CSRF.
 * Header kustom memaksa preflight CORS, sehingga situs lain tidak dapat memalsukannya.
 * Klien Android memakai Bearer token dan tidak terkena aturan ini.
 */
class VerifyBrowserRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->bearerToken()) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        $hasSessionCookie = $request->cookies->has(config('akur.session.cookie'));

        if ($origin === null && ! $hasSessionCookie) {
            return $next($request);
        }

        $allowedOrigin = $origin !== null && in_array($origin, config('akur.web_origins'), true);
        $hasHeader = $request->headers->get(config('akur.csrf_header')) === '1';

        if (! $allowedOrigin || ! $hasHeader) {
            return response()->json(['message' => 'Permintaan ditolak (CSRF).'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
