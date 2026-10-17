<?php

namespace App\Providers;

use App\Services\Access\UnitAccess;
use App\Services\Auth\SessionManager;
use App\Services\Pii\PiiCipher;
use App\Services\Pii\SodiumPiiCipher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(UnitAccess::class);
        $this->app->singleton(PiiCipher::class, fn () => SodiumPiiCipher::fromConfig());
    }

    public function boot(): void
    {
        // Guard `akur`: Bearer token (Android) atau cookie HttpOnly (Web) → sesi di user_sessions.
        Auth::viaRequest('akur-session', function (Request $request) {
            $token = $request->bearerToken() ?? $request->cookies->get(config('akur.session.cookie'));
            if (! is_string($token) || $token === '' || strlen($token) > 200) {
                return null;
            }

            $session = app(SessionManager::class)->findUsable($token);
            $user = $session?->user;
            if (! $user || ! $user->canSignIn()) {
                return null;
            }

            $request->attributes->set('akur_session', $session);

            return $user;
        });

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: 'ip:'.$request->ip()));

        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(30)
            ->by('sensitive:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));
    }
}
