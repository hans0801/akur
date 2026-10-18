<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use App\Models\AppSetting;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\InvalidGoogleToken;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\SessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FR-01/FR-02: tukar ID token Google dengan sesi AKUR.
 * Login pertama membuat akun berstatus `pending` tanpa peran apa pun.
 */
class GoogleLoginController extends Controller
{
    public function __invoke(
        Request $request,
        GoogleIdTokenVerifier $verifier,
        SessionManager $sessions,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', 'in:web,android,ios'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $google = $verifier->verify($data['id_token']);
        } catch (InvalidGoogleToken $e) {
            Log::warning('Google login rejected', ['reason' => $e->getMessage(), 'request_id' => $request->attributes->get('request_id')]);

            return response()->json(['message' => 'Login Google gagal diverifikasi.'], 401);
        }

        $verifiedEmail = $google['email_verified'] ? $google['email'] : null;

        [$user, $isNew] = DB::transaction(function () use ($google, $verifiedEmail) {
            $identity = AuthIdentity::query()
                ->where('provider', 'google')
                ->where('provider_subject', $google['sub'])
                ->lockForUpdate()
                ->first();

            if ($identity) {
                $user = User::withTrashed()->findOrFail($identity->user_id);
                $identity->update(['provider_email' => $verifiedEmail, 'last_used_at' => now()]);

                return [$user, false];
            }

            $user = User::query()->create([
                'display_name' => Str::limit($google['name'] ?: Str::before((string) $verifiedEmail, '@') ?: 'Pengguna', 160, ''),
                'email' => $verifiedEmail,
                'email_verified_at' => $verifiedEmail ? now() : null,
                'avatar_url' => $google['picture'],
                'account_status' => User::STATUS_PENDING,
            ]);

            $user->identities()->create([
                'provider' => 'google',
                'provider_subject' => $google['sub'],
                'provider_email' => $verifiedEmail,
                'linked_at' => now(),
                'last_used_at' => now(),
            ]);

            return [$user, true];
        });

        if ($isNew) {
            $audit->log('auth.account_created', 'user', $user->id, actorUserId: $user->id, metadata: ['provider' => 'google']);
        }

        if ($user->trashed() || ! $user->canSignIn()) {
            $audit->log('auth.login_denied', 'user', $user->id, actorUserId: $user->id, metadata: ['account_status' => $user->account_status]);

            return response()->json(['message' => 'Akun Anda tidak dapat digunakan. Hubungi pengurus.', 'code' => 'account_blocked'], 403);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'avatar_url' => $google['picture'] ?? $user->avatar_url,
            'email' => $verifiedEmail ?? $user->email,
        ])->save();

        ['session' => $session, 'token' => $token] = $sessions->issue($user, $data['platform'], $request, $data['device_label'] ?? null);

        $audit->log('auth.login', 'user_session', $session->id, actorUserId: $user->id, metadata: ['platform' => $data['platform']]);

        $maintenance = AppSetting::maintenance();
        $body = [
            'user' => new CurrentUserResource($user),
            'session' => ['id' => $session->id, 'expires_at' => $session->expires_at->toIso8601String()],
            'maintenance' => $maintenance['enabled'],
            'maintenance_message' => $maintenance['enabled'] ? $maintenance['message'] : null,
        ];

        if ($data['platform'] !== 'web') {
            // Android/iOS menyimpan token ini di secure storage platform (Keystore/Keychain).
            $body['token'] = $token;

            return response()->json($body);
        }

        return response()->json($body)->withCookie(SessionCookie::make($token));
    }
}
