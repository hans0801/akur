<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas privat, jalan/rumah, keluarga (KK), penduduk, dan riwayat mutasi.
 * Kolom *_ciphertext diisi oleh lapisan enkripsi aplikasi (keputusan desain W11);
 * *_lookup_hmac adalah keyed HMAC untuk pencarian exact-match — bukan hash biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_objects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users');
            $table->text('storage_key')->unique();
            $table->text('original_filename');
            $table->string('mime_type', 120);
            $table->bigInteger('byte_size');
            $table->binary('checksum_sha256');
            $table->string('classification', 30);
            $table->string('encryption_key_version', 40)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE file_objects ADD CONSTRAINT file_objects_byte_size_check CHECK (byte_size >= 0)');

        Schema::create('streets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('unit_id');
        });

        Schema::create('houses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('street_id')->constrained('streets');
            $table->string('house_number', 30);
            $table->string('block_label', 30)->nullable();
            $table->string('occupancy_status', 20);
            $table->timestampsTz();
        });
        // NULLS NOT DISTINCT agar rumah tanpa blok tetap unik per nomor.
        DB::statement('ALTER TABLE houses ADD CONSTRAINT houses_street_number_block_unique UNIQUE NULLS NOT DISTINCT (street_id, house_number, block_label)');

        Schema::create('households', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('household_number', 40)->nullable();
            $table->binary('kk_number_ciphertext')->nullable();
            $table->binary('kk_number_lookup_hmac')->nullable();
            $table->uuid('head_resident_id')->nullable();
            $table->foreignUuid('street_id')->nullable()->constrained('streets');
            $table->foreignUuid('house_id')->nullable()->constrained('houses');
            $table->text('address_text');
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->index(['unit_id', 'status']);
            $table->unique('kk_number_lookup_hmac');
        });

        Schema::create('residents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('household_id')->nullable()->constrained('households');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('full_name', 160);
            $table->binary('nik_ciphertext')->nullable();
            $table->binary('nik_lookup_hmac')->nullable();
            $table->string('birth_place', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('education', 80)->nullable();
            $table->boolean('is_household_head')->default(false);
            $table->boolean('is_alive')->default(true);
            $table->string('residency_status', 20);
            $table->foreignUuid('ktp_file_id')->nullable()->constrained('file_objects');
            $table->foreignUuid('kk_file_id')->nullable()->constrained('file_objects');
            $table->date('moved_in_at')->nullable();
            $table->date('moved_out_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('household_id');
            $table->unique('nik_lookup_hmac');
        });
        // Satu akun hanya terhubung ke satu data penduduk aktif.
        DB::statement('CREATE UNIQUE INDEX residents_user_id_unique ON residents (user_id) WHERE user_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX residents_full_name_lower_idx ON residents (lower(full_name))');

        // FK melingkar households ↔ residents ditambahkan setelah kedua tabel ada.
        DB::statement('ALTER TABLE households ADD CONSTRAINT households_head_resident_fk FOREIGN KEY (head_resident_id) REFERENCES residents(id) DEFERRABLE INITIALLY DEFERRED');

        Schema::create('resident_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resident_id')->constrained('residents');
            $table->string('movement_type', 20);
            $table->foreignUuid('from_household_id')->nullable()->constrained('households');
            $table->foreignUuid('to_household_id')->nullable()->constrained('households');
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['resident_id', 'effective_date']);
        });
        DB::statement("ALTER TABLE resident_movements ADD CONSTRAINT resident_movements_type_check CHECK (movement_type IN ('move_in','move_out','move_house','death','correction'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_movements');
        DB::statement('ALTER TABLE IF EXISTS households DROP CONSTRAINT IF EXISTS households_head_resident_fk');
        Schema::dropIfExists('residents');
        Schema::dropIfExists('households');
        Schema::dropIfExists('houses');
        Schema::dropIfExists('streets');
        Schema::dropIfExists('file_objects');
    }
};
