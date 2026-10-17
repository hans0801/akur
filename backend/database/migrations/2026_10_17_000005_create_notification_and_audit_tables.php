<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('type', 60);
            $table->string('title', 200);
            $table->text('body');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->foreignUuid('unit_id')->nullable()->constrained('administrative_units');
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->uuid('entity_id')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('request_id', 100)->nullable();
            $table->ipAddress('ip_prefix')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['unit_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
        });

        // Append-oriented: tolak UPDATE/DELETE di level database.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_block_mutation();');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
    }
};
