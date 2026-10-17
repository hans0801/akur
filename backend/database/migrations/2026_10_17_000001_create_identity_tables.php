<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unit administrasi, akun aplikasi, identitas provider, peran, keanggotaan, dan sesi.
 * Lihat PRD §9: users ≠ residents; Google `sub` adalah kunci identitas eksternal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrative_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_unit_id')->nullable();
            $table->string('unit_type', 8);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['parent_unit_id', 'code']);
        });
        DB::statement('ALTER TABLE administrative_units ADD CONSTRAINT administrative_units_parent_fk FOREIGN KEY (parent_unit_id) REFERENCES administrative_units(id)');
        DB::statement("ALTER TABLE administrative_units ADD CONSTRAINT administrative_units_unit_type_check CHECK (unit_type IN ('rw','rt'))");
        // RT wajib punya parent RW; RW tidak punya parent.
        DB::statement("ALTER TABLE administrative_units ADD CONSTRAINT administrative_units_hierarchy_check CHECK ((unit_type = 'rw' AND parent_unit_id IS NULL) OR (unit_type = 'rt' AND parent_unit_id IS NOT NULL))");

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('display_name', 160);
            $table->string('email', 254)->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('account_status', 20)->default('pending');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('account_status');
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_status_check CHECK (account_status IN ('pending','active','suspended','disabled'))");

        Schema::create('auth_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('provider', 30);
            $table->string('provider_subject', 255);
            $table->string('provider_email', 254)->nullable();
            $table->timestampTz('linked_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['provider', 'provider_subject']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
        });

        Schema::create('user_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->unsignedSmallInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->string('status', 20)->default('active');
            $table->foreignUuid('granted_by')->nullable()->constrained('users');
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'unit_id', 'role_id']);
            $table->index(['unit_id', 'status']);
        });
        DB::statement("ALTER TABLE user_memberships ADD CONSTRAINT user_memberships_status_check CHECK (status IN ('pending','active','revoked'))");

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            // SHA-256 dari token sesi; token mentah tidak pernah disimpan.
            $table->binary('refresh_token_hash');
            $table->string('device_label', 120)->nullable();
            $table->string('platform', 20);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('replaced_by_session_id')->nullable();
            $table->ipAddress('ip_prefix')->nullable();
            $table->text('user_agent')->nullable();

            $table->unique('refresh_token_hash');
            $table->index(['user_id', 'revoked_at']);
        });
        DB::statement('ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_replaced_by_fk FOREIGN KEY (replaced_by_session_id) REFERENCES user_sessions(id)');
        DB::statement("ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_platform_check CHECK (platform IN ('web','android'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_memberships');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('auth_identities');
        Schema::dropIfExists('users');
        Schema::dropIfExists('administrative_units');
    }
};
