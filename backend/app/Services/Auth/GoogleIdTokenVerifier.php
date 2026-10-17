<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifikasi ID token Google di server: signature (JWKS Google), `iss`, `aud`, `exp`, `sub`.
 * Token Google hanya dipakai sekali untuk login; tidak pernah dipakai sebagai token API.
 */
class GoogleIdTokenVerifier
{
    private const JWKS_CACHE_KEY = 'akur:google:jwks';

    /**
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string, picture: ?string}
     *
     * @throws InvalidGoogleToken
     */
    public function verify(string $idToken): array
    {
        $clientIds = config('akur.google.client_ids');
        if ($clientIds === []) {
            throw new InvalidGoogleToken('Google client ID belum dikonfigurasi.');
        }

        $claims = $this->decode($idToken);

        if (! in_array($claims->iss ?? null, config('akur.google.issuers'), true)) {
            throw new InvalidGoogleToken('Issuer tidak valid.');
        }

        if (! in_array($claims->aud ?? null, $clientIds, true)) {
            throw new InvalidGoogleToken('Audience tidak valid.');
        }

        if (! isset($claims->sub) || ! is_string($claims->sub) || $claims->sub === '') {
            throw new InvalidGoogleToken('Subject kosong.');
        }

        return [
            'sub' => $claims->sub,
            'email' => $claims->email ?? null,
            'email_verified' => filter_var($claims->email_verified ?? false, FILTER_VALIDATE_BOOL),
            'name' => $claims->name ?? null,
            'picture' => $claims->picture ?? null,
        ];
    }

    private function decode(string $idToken): object
    {
        JWT::$leeway = config('akur.google.leeway_seconds');

        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->jwks()));
        } catch (\UnexpectedValueException $e) {
            // `kid` tidak dikenal: kemungkinan Google merotasi kunci. Ambil ulang sekali.
            if (str_contains($e->getMessage(), '"kid"')) {
                try {
                    return JWT::decode($idToken, JWK::parseKeySet($this->jwks(refresh: true)));
                } catch (Throwable $retry) {
                    throw new InvalidGoogleToken('Token tidak valid.', previous: $retry);
                }
            }
            throw new InvalidGoogleToken('Token tidak valid.', previous: $e);
        } catch (Throwable $e) {
            throw new InvalidGoogleToken('Token tidak valid.', previous: $e);
        }
    }

    /** @return array{keys: list<array<string, string>>} */
    private function jwks(bool $refresh = false): array
    {
        if (! $refresh && ($cached = Cache::get(self::JWKS_CACHE_KEY))) {
            return $cached;
        }

        $response = Http::timeout(5)->retry(2, 200)->get(config('akur.google.jwks_url'))->throw();
        $jwks = $response->json();

        if (! is_array($jwks) || ! isset($jwks['keys'])) {
            throw new InvalidGoogleToken('JWKS Google tidak dapat dibaca.');
        }

        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m);
        Cache::put(self::JWKS_CACHE_KEY, $jwks, now()->addSeconds(max(60, (int) ($m[1] ?? 3600))));

        return $jwks;
    }
}
