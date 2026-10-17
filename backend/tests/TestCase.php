<?php

namespace Tests;

use App\Models\AdministrativeUnit;
use App\Models\Household;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMembership;
use App\Services\Auth\SessionManager;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected string $seeder = RoleSeeder::class;

    protected bool $seed = true;

    /**
     * Setiap request uji dimulai tanpa guard/instance scoped dari request sebelumnya,
     * seperti request HTTP sungguhan (RequestGuard menyimpan user yang sudah di-resolve).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** @return array{rw: AdministrativeUnit, rt1: AdministrativeUnit, rt2: AdministrativeUnit} */
    protected function unitTree(): array
    {
        $rw = AdministrativeUnit::factory()->create(['code' => '06']);

        return [
            'rw' => $rw,
            'rt1' => AdministrativeUnit::factory()->rtOf($rw)->create(['code' => '05']),
            'rt2' => AdministrativeUnit::factory()->rtOf($rw)->create(['code' => '07']),
        ];
    }

    protected function grant(User $user, AdministrativeUnit $unit, string $role): UserMembership
    {
        return UserMembership::query()->create([
            'user_id' => $user->id,
            'unit_id' => $unit->id,
            'role_id' => Role::idFor($role),
            'status' => UserMembership::STATUS_ACTIVE,
            'granted_at' => now(),
        ]);
    }

    protected function userWithRole(AdministrativeUnit $unit, string $role): User
    {
        $user = User::factory()->create();
        $this->grant($user, $unit, $role);

        return $user;
    }

    protected function residentIn(AdministrativeUnit $unit, array $attributes = []): Resident
    {
        $household = Household::query()->create(['unit_id' => $unit->id, 'address_text' => 'Jl. Uji No. 1', 'status' => 'active']);

        return Resident::query()->create($attributes + [
            'household_id' => $household->id,
            'full_name' => 'Warga Uji',
            'residency_status' => 'permanent',
        ]);
    }

    /** Header Bearer untuk sesi baru milik pengguna (alur Android). */
    protected function bearerFor(User $user): array
    {
        $token = app(SessionManager::class)->issue($user, 'android', Request::create('/'))['token'];

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
