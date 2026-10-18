<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan aplikasi global. Hanya super_admin yang boleh membaca dan mengubahnya.
 */
class AppSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => ['maintenance' => AppSetting::maintenance()]]);
    }

    public function updateMaintenance(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);

        AppSetting::query()->updateOrCreate(
            ['key' => AppSetting::MAINTENANCE],
            [
                'value' => ['enabled' => (bool) $data['enabled'], 'message' => filled($data['message'] ?? null) ? trim($data['message']) : null],
                'updated_by' => $request->user()->id,
            ],
        );
        $this->audit->log(
            $data['enabled'] ? 'settings.maintenance_enabled' : 'settings.maintenance_disabled',
            'app_setting',
            metadata: ['key' => AppSetting::MAINTENANCE],
        );

        return response()->json(['data' => ['maintenance' => AppSetting::maintenance()]]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        if (! $request->user()->isSuperAdmin()) {
            throw new AuthorizationException('Hanya super admin yang dapat mengelola pengaturan aplikasi.');
        }
    }
}
