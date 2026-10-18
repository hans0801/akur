<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Profil milik sendiri: pilihan avatar akun (ilustrasi bawaan, foto Google, atau inisial).
 * Terbuka juga untuk akun pending.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function updateAvatar(Request $request): CurrentUserResource
    {
        $data = $request->validate([
            // null = kembali ke avatar bawaan.
            'avatar_choice' => ['present', 'nullable', 'string', Rule::in(config('akur.avatar_choices'))],
        ]);
        $user = $request->user();

        if ($data['avatar_choice'] === 'google' && ! $user->avatar_url) {
            abort(response()->json([
                'message' => 'Akun Google Anda tidak memiliki foto profil.',
                'errors' => ['avatar_choice' => ['Akun Google Anda tidak memiliki foto profil.']],
            ], 422));
        }

        $user->forceFill(['avatar_choice' => $data['avatar_choice']])->save();
        $this->audit->log('profile.avatar_changed', 'user', $user->id, metadata: ['avatar_choice' => $data['avatar_choice']]);

        return new CurrentUserResource($user);
    }
}
