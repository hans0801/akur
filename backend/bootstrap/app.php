<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyBrowserRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hanya Caddy (jaringan Docker privat) yang dipercaya sebagai proxy.
        $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1']);

        $middleware->api(prepend: [AssignRequestId::class, SecurityHeaders::class], append: [VerifyBrowserRequest::class]);

        $middleware->alias(['account.active' => EnsureAccountActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Jangan pernah melaporkan token/ID token maupun NIK/KK ke log atau session flash.
        $exceptions->dontFlash(['id_token', 'token', 'nik', 'kk_number']);

        $exceptions->render(fn (AuthorizationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage() ?: 'Akses ditolak.'], 403)
            : null);

        // Pesan ramah berbahasa Indonesia; jangan bocorkan nama model/route ke klien.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Sesi Anda berakhir. Silakan masuk lagi.'], 401)
            : null);

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $generic = $e->getPrevious() instanceof ModelNotFoundException || $e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route');

            return response()->json(['message' => $generic ? 'Data tidak ditemukan.' : $e->getMessage()], 404);
        });

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'], 429, $e->getHeaders())
            : null);

        // Tautan cetak (surat/laporan) dibuka di browser: tampilkan halaman singkat, bukan JSON.
        $exceptions->render(fn (InvalidSignatureException $e) => response(
            '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Tautan kedaluwarsa</title><body style="font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem">'
            .'<h1>Tautan tidak berlaku</h1><p>Tautan ini sudah kedaluwarsa atau tidak valid. Buka lagi dari aplikasi AKUR.</p></body></html>',
            403,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
        ));
    })->create();
