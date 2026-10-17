<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengumuman, kegiatan, rapat, dan surat pengantar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('publish_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignUuid('author_user_id')->constrained('users');
            $table->timestampsTz();

            $table->index(['unit_id', 'status', 'publish_at']);
        });
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_status_check CHECK (status IN ('draft','published','archived'))");

        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('title', 200);
            $table->text('description');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->text('location_text');
            $table->foreignUuid('image_file_id')->nullable()->constrained('file_objects');
            $table->string('category', 80);
            $table->foreignUuid('organizer_user_id')->constrained('users');
            $table->string('status', 20);
            $table->timestampsTz();

            $table->index(['unit_id', 'starts_at']);
        });
        DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_time_check CHECK (ends_at IS NULL OR ends_at >= starts_at)');

        Schema::create('activity_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignUuid('resident_id')->nullable()->constrained('residents');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('participation_status', 20);
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['activity_id', 'resident_id']);
        });
        DB::statement('ALTER TABLE activity_participants ADD CONSTRAINT activity_participants_who_check CHECK (resident_id IS NOT NULL OR user_id IS NOT NULL)');

        Schema::create('meetings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('title', 200);
            $table->text('agenda')->nullable();
            $table->timestampTz('meeting_at');
            $table->text('location_text')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index(['unit_id', 'meeting_at']);
        });

        Schema::create('meeting_attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignUuid('resident_id')->nullable()->constrained('residents');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('attendance_status', 20);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['meeting_id', 'resident_id']);
        });

        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('meeting_id')->constrained('meetings');
            $table->text('minutes_text');
            $table->jsonb('decisions')->nullable();
            $table->foreignUuid('attachment_file_id')->nullable()->constrained('file_objects');
            $table->foreignUuid('prepared_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->jsonb('required_fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('document_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->foreignUuid('requester_user_id')->constrained('users');
            $table->foreignUuid('resident_id')->nullable()->constrained('residents');
            $table->foreignUuid('document_type_id')->constrained('document_types');
            $table->text('purpose');
            $table->jsonb('form_data');
            $table->string('status', 20)->default('submitted');
            $table->text('admin_notes')->nullable();
            $table->foreignUuid('processed_by')->nullable()->constrained('users');
            $table->timestampTz('processed_at')->nullable();
            $table->foreignUuid('output_file_id')->nullable()->constrained('file_objects');
            $table->timestampTz('submitted_at');
            $table->timestampsTz();

            $table->index(['unit_id', 'status', 'submitted_at']);
            $table->index('requester_user_id');
        });
        DB::statement("ALTER TABLE document_requests ADD CONSTRAINT document_requests_status_check CHECK (status IN ('submitted','under_review','revision_requested','approved','rejected','issued','cancelled'))");

        Schema::create('document_request_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('document_requests')->cascadeOnDelete();
            $table->foreignUuid('file_id')->constrained('file_objects');
            $table->string('purpose', 40);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['request_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_request_files');
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('document_types');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('meeting_attendance');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('activity_participants');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('announcements');
    }
};
