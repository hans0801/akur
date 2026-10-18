<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 *  - users.avatar_choice: avatar pilihan pengguna (ilustrasi bawaan aplikasi, foto Google, atau inisial);
 *    null = avatar bawaan. Nilai yang sah ada di config akur.avatar_choices.
 *  - app_settings: pengaturan aplikasi global (mis. mode pemeliharaan), hanya diubah super_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_choice', 40)->nullable();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_choice');
        });
    }
};
