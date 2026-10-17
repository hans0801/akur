<?php

namespace App\Services\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/** Cookie sesi web: HttpOnly, Secure, SameSite=Strict, hanya untuk path /api. */
final class SessionCookie
{
    public static function make(string $token): Cookie
    {
        return Cookie::create(
            name: config('akur.session.cookie'),
            value: $token,
            expire: now()->addDays(config('akur.session.absolute_ttl_days')),
            path: '/api',
            secure: config('akur.session.cookie_secure'),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    public static function forget(): Cookie
    {
        return Cookie::create(
            name: config('akur.session.cookie'),
            value: '',
            expire: 1,
            path: '/api',
            secure: config('akur.session.cookie_secure'),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }
}
