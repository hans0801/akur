<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * W11 hardening: header keamanan untuk semua respons API. Caddy juga menambahkan header serupa di produksi,
 * tetapi middleware ini menjaga perilaku yang sama saat API diakses langsung (dev, healthcheck internal).
 * Respons API berisi data pribadi, jadi bawaannya tidak boleh di-cache oleh browser maupun proxy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        $cacheControl = (string) $response->headers->get('Cache-Control');
        if (! str_contains($cacheControl, 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
