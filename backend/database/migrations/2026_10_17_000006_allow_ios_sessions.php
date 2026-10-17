<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Aplikasi iOS memakai alur token Bearer yang sama dengan Android. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE user_sessions DROP CONSTRAINT user_sessions_platform_check');
        DB::statement("ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_platform_check CHECK (platform IN ('web','android','ios'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_sessions DROP CONSTRAINT user_sessions_platform_check');
        DB::statement("ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_platform_check CHECK (platform IN ('web','android'))");
    }
};
