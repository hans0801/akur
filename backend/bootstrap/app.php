<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\VerifyBrowserRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $middleware->api(prepend: [AssignRequestId::class], append: [VerifyBrowserRequest::class]);

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
    })->create();
